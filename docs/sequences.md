# Sequences

A sequence is a short recipe of AIquila tool calls that an AI client runs in one
go: for example, "show me today's events, my open tasks and my notifications".
You write it once in Nextcloud. After that your MCP client (Claude, Cursor, …)
can run it by name. Each step runs as you, with your permissions.

How the tools behave is described in [Sequences tools](mcp/tools/apps/sequences.md).

## Where to find them

- **Personal settings → AIquila → Sequences**: your own, the organization's, and the built-in ones.
- **Administration settings → AIquila → Sequences**: the organization's, the built-in ones, and the policy.

There is no Run button on these pages. A sequence runs from an MCP client
connected to the AIquila MCP server.

## The three groups

**Built-in**: sequences that come with the MCP server, such as *Daily briefing*,
*Inbox triage* or *Out of office on*. The switch turns one off for you. If your
administrator has turned one off, it stays off.

**Organization**: published by your administrator. You can't edit them, but
**Duplicate to mine** copies one into your own sequences, where you can change it.

**Mine**: your own. Edit them, delete them, or switch one off with **Enabled**.

## Writing a sequence

1. **Title**: what you call it.
2. **Tool name**: lowercase letters, digits, `_` and `-`. The model sees your
   sequence as `seq_my_<name>`; an organization one as `seq_<name>`.
3. **Description**: what the model reads to decide when to use it. Say what it is for.
4. **Inputs** (optional): values passed in when it runs, such as a calendar name
   or a date. Each has a type, can be required, and can have a default.
5. **Steps**: pick a tool for each step. Its fields come from that tool. Leave a
   field empty to use the tool's default. **On failure** says whether a failed
   step stops the sequence or lets it continue. The arrows change the order.
6. **Show as its own tool**: also offer it to the model as its own tool, not
   only through `run_sequence`. Your administrator may limit how many you can
   show this way.

The **variable** button next to a field inserts a placeholder:

| Placeholder | Becomes |
|---|---|
| `{{inputs.calendar}}` | The input `calendar` |
| `{{today}}`, `{{today+7d}}` | Today's date, or a week from today |
| `{{now}}`, `{{now+1h}}` | The current time, or an hour from now |
| `{{steps.events.text}}` | What the step with the id `events` returned |
| `{{steps.note.result.id}}` | A field of what that step returned, for tools that return structured data |

A badge shows what the sequence can do, based on its steps: **Read-only**,
**Changes data**, or **Destructive**. Saving checks the whole sequence and names
anything that is wrong, such as an unknown argument or a placeholder that
points at a later step.

## For administrators

- **Organization sequences** are listed for every user and always run as the
  user who calls them.
- **Built-in** switches turn a sequence off for everyone.
- **Policy**:
  - *Users may write their own sequences*: when off, personal sequences are hidden and can't be changed.
  - *Sequences per user*, *Steps per sequence* (at most 20), *Personal sequences shown as their own tool*.

Organization sequences and the policy are part of the library export
(`occ aiquila:library export` / `import`).
