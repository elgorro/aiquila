# Context Agent, and why AIquila serves the model rather than the agent

Nextcloud's **Context Agent** is the Assistant mode that acts in the instance: it creates
calendar events, sends Talk messages, shares files. Three TaskProcessing task types back it:

| Task type | Input | Output |
|---|---|---|
| `core:contextagent:interaction` | `input`, `confirmation`, `conversation_token` | `output`, `conversation_token`, `actions` |
| `core:contextagent:multimodal-interaction` | the above, plus `input_attachments` and `memories` | the above, plus `output_attachments` and `sources` |
| `core:contextagent:audio-interaction` | audio `input`, `confirmation`, `conversation_token` | audio `output`, both transcripts, `conversation_token`, `actions` |

AIquila does not provide these types. They are listed in `ProviderRegistry::NOT_SERVED`,
and `RegisteredProvidersTest` fails if a type Nextcloud ships is neither served nor listed
there. This page records why, and what would change the answer.

## These types are an agent, not a prompt

Each provider in `lib/TaskProcessing/` is one input shape, one model call and one output
shape. The Context Agent types expect a whole agent behind them:

- **A resumable session.** `conversation_token` round-trips. The provider returns it and gets
  it back on the next task, and has to pick up the conversation from it, including a tool
  call that is waiting for approval.
- **An approval protocol.** When the agent wants to do something consequential, it stops and
  returns the pending tool calls in `actions`. The user's answer comes back as
  `confirmation` (`0` denies, `1` approves), along with any text explaining a denial.
- **A tool set.** The agent can only act through tools bound to Nextcloud's APIs, with each
  tool's side effects classified so the agent knows what needs approval.

## How upstream fills it

The `context_agent` ExApp provides `interaction` and `multimodal-interaction`. The
Assistant's own audio provider transcribes speech and hands it to `interaction`, so
`audio-interaction` runs through the same agent. Inside `context_agent`:

- **Session.** The LangGraph checkpoint is serialised into the token itself and signed with
  HMAC-SHA512 under a key the app keeps in its persistent storage. Nothing is stored on the
  server per conversation. The token *is* the state.
- **Approval.** The graph has a confirmation node. Each pending call is annotated with an
  *impulse radius* (who the effect reaches: the user, an existing audience, or outside
  parties) and whether it destroys something. The admin sets the thresholds above which a
  call needs approval. A denial is fed back to the model as a `ToolMessage` together with the
  user's reason.
- **Tools.** About forty modules cover calendar, contacts, Talk, Mail, Deck, Files, shares,
  Tables, Collectives, Forms, OpenProject, web search and more. The app also takes
  admin-configured remote MCP servers (streamable HTTP only) as extra tools, and serves its
  own tools as an MCP server for outside agents.
- **The model.** `context_agent` does not call an LLM itself. Every model turn is a
  `core:text2text:chatwithtools` task, or `core:text2text:multimodal-chatwithtools` when there
  are attachments. It is scheduled as the user the interaction belongs to and handled by
  whichever provider the admin chose for that type.

## Position

**AIquila serves the model behind the Context Agent, not the agent itself.**

AIquila provides both `chatwithtools` types (`ChatWithToolsProvider`,
`MultimodalChatWithToolsProvider`). With `context_agent` installed and AIquila chosen for
those types, every model turn the agent takes goes through `ProviderResolver`. That means the
user's chosen provider, the admin's per-user and per-group access rules, and the capability
checks that refuse to send attachments to a provider without vision or document support.
The Assistant features built on the agent, such as Skills, on-demand memories and scheduled
tasks, live in the Assistant and `context_agent`, so they run on AIquila's model the same way.

Serving the agent types as well would add little and cost a lot:

- **Nextcloud picks one provider per task type.** An AIquila provider for
  `contextagent:interaction` would not work alongside `context_agent`; it would replace it.
  Replacing it means rebuilding everything above: the tool set, the impulse-radius
  classification and the admin thresholds behind it, the signed-token format, and the MCP
  server. Users would lose features the moment an admin switched.
- **The tool surface would be a second copy.** AIquila's tools already reach the model in
  AIquila's own chat through `McpClientService` and the MCP server. Wrapping those same tools
  in a Context Agent loop would duplicate `context_agent`'s tools without adding new
  capabilities.
- **The `actions` format belongs to its consumer.** The Assistant renders the confirmation
  dialog from the JSON in `actions`. That JSON is `context_agent`'s annotated tool-call list,
  not a documented contract. A second provider would have to follow it release by release.

## What would change this

- **Instances that cannot run ExApps.** `context_agent` needs AppAPI and a deploy daemon. On
  hosting without them there is no Context Agent at all, and an agent written in PHP on top of
  `McpClientService` would be the only route to one. This is the strongest case for serving
  the types, and the trigger to revisit.
- **`context_agent` stops delegating to `chatwithtools`.** If it starts calling a model
  directly, AIquila drops out of the path, and this position has to be weighed again.
- **A feature that needs the agent types themselves.** An Assistant feature that schedules
  `contextagent:*` directly, and cannot be served through `chatwithtools`, would shift the
  balance.

If the types are ever served, the design starts from upstream's choices rather than against
them:

- Keep the session in a signed, self-contained token so no new table is needed. Sign it with
  a key derived from the instance secret.
- Emit `actions` in the shape the Assistant already renders.
- Bind tools through `McpClientService::getAllTools()` and `executeTool()`, as AIquila's chat
  does. Classify consequential tools before any of them runs without approval.

## Checking the premise

The delegation this position depends on lives in `ex_app/lib/nc_model.py` in
[`nextcloud/context_agent`](https://github.com/nextcloud/context_agent) (the
`TEXT_CHAT_WITH_TOOLS` and `MULTIMODAL_CHAT_WITH_TOOLS` constants). Session and approval
handling live in `ex_app/lib/agent.py` and `ex_app/lib/all_tools/lib/impulse.py`. Check those
files again before relying on this page after a major `context_agent` release. Admin setup is
described in Nextcloud's
[Context Agent manual](https://docs.nextcloud.com/server/latest/admin_manual/ai/app_context_agent.html).
