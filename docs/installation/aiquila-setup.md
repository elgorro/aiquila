# AIquila Nextcloud App Setup

Complete guide to installing and configuring the AIquila Nextcloud app.

## Upgrade notes

### 0.4.0 — conversations pin their provider, and the settings pages changed

**Nothing to do on upgrade.** Existing conversations, keys and model choices
are preserved; the migration runs on `occ upgrade`.

What changes:

- **A conversation now remembers its provider.** Previously, switching provider
  in personal settings flipped *every* existing conversation on its next
  message and replayed its history to a different model. Conversations created
  from 0.4.0 onwards are pinned to the provider they started on. Conversations
  that predate the upgrade are unpinned, so they keep following your setting
  exactly as before — pin one from the picker in the chat header if you want it
  fixed.
- **Both settings pages were rebuilt.** Providers are now cards under tabs
  instead of one long page, and each provider describes its own fields, so
  Mistral, DeepSeek, Hetzner and the local endpoint finally get their own model
  lists rather than Anthropic's. All config keys are unchanged.
- **The chat's settings panel moved.** Provider, model, key and defaults now
  live in **Settings → Personal → AIquila**. The gear in the chat navigation
  links there. The chat keeps a per-conversation provider/model picker in its
  header.
- **Effort validation is provider-aware.** Setting `/effort` on a conversation
  served by a provider without the concept now says so, instead of listing
  Anthropic's levels.
- **Two admin endpoints were replaced.** `POST /api/admin/local`,
  `GET /api/admin/local/status` and `POST /api/admin/test` are gone; the
  schema-driven `GET|POST /api/admin/providers[/{id}]` and
  `POST /api/admin/providers/{id}/test` cover all providers uniformly. This
  only matters if you scripted against them.
- **MCP server:** `create_coworker` / `update_coworker` gain a `provider`
  argument. Their `model` argument used to hold a provider id and now holds an
  actual model id; existing coworkers are migrated automatically.

### 0.3.32 — the default model changed

The built-in default moved from `claude-sonnet-4-6` to **`claude-sonnet-5`**.

This only affects installs that never picked a model explicitly. If an
administrator or user has selected a model in the settings UI or via
`occ aiquila:configure --model`, that choice is preserved.

If you are on the default, two things change after upgrading:

- **Token counts rise for identical work.** Sonnet 5 uses a newer tokenizer;
  the same text produces roughly 30% more tokens than on Sonnet 4.6. Usage
  figures in the admin dashboard and the `aiquila_usage` widget will step up
  accordingly. This is a measurement change, not a fault.
- **The output ceiling rises** from 64,000 to 128,000 tokens.

AIquila never sends the `temperature`, `top_p`, and `top_k` sampling
parameters to Claude, so no configuration change is needed.

To stay on the previous model:

```bash
php occ aiquila:configure --model claude-sonnet-4-6
```

## Prerequisites

- Nextcloud 35 — see [Nextcloud compatibility](../nextcloud-compatibility.md) for the supported version window
- PHP 8.4 or higher with Composer
- Node.js 26 or higher (for building frontend)
- npm 10 or higher
- An API key for one of the supported providers — e.g. Claude from [console.anthropic.com](https://console.anthropic.com), Hetzner Inference from [experiments.hetzner.com](https://experiments.hetzner.com/inference), or Mistral. A local model (Ollama, LM Studio, llama.cpp) needs no key.

## What You Get

AIquila provides six main features:

1. **Chat Interface**: Interactive chat with your AI provider at `/apps/aiquila`
2. **TaskProcessing Provider**: Native integration with the Nextcloud Assistant
3. **Flow operation**: Process files automatically when a Flow rule matches
4. **Cowork**: Scheduled, repeating AI jobs over your files
5. **Talk bot**: Answers in Nextcloud Talk conversations when addressed
6. **Public API**: RESTful endpoints for other apps to use AIquila

## Installation

### 1. Install Dependencies

```bash
cd nextcloud-app

# Install PHP dependencies (Anthropic SDK)
composer install

# Install Node.js dependencies
npm install

# Build frontend
npm run build
```

### 2. Deploy the App

Choose one of these deployment methods:

**Option A: Copy to Nextcloud (Production)**
```bash
cp -r nextcloud-app /path/to/nextcloud/custom_apps/aiquila
```

**Option B: Symlink (Development)**
```bash
ln -s /path/to/aiquila/nextcloud-app /path/to/nextcloud/custom_apps/aiquila
```

**Option C: Docker Development**
```bash
# See docs/docker-setup.md for complete Docker development environment
```

### 3. Set Correct Permissions

The web server needs to read all app files:

```bash
cd /path/to/nextcloud/custom_apps/aiquila

# Quick fix: set all permissions recursively
find . -type f -exec chmod 644 {} \;
find . -type d -exec chmod 755 {} \;
```

**Important files that need 644 permissions:**
- `lib/**/*.php` - PHP classes
- `templates/*.php` - Templates
- `css/*.css` - Stylesheets
- `js/*.js` - JavaScript files
- `img/*.svg` - Icons

### 4. Enable the App

**Via command line (recommended):**
```bash
cd /path/to/nextcloud
sudo -u www-data php occ app:enable aiquila
```

**Via web interface:**
1. Go to **Settings → Apps**
2. Find "AIquila" in the disabled apps list
3. Click **Enable**

### 5. Configure a provider

**Admin configuration** (applies to every user):

1. Navigate to **Settings → Administration → AIquila**
2. The **Providers** tab shows one card per available provider — Claude
   (Anthropic), Claude through a cloud platform (Claude Platform on AWS, Claude
   on Google Cloud, Amazon Bedrock, Vertex AI and Microsoft Foundry — see
   [Claude on cloud platforms](../dev/cloud-providers.md)), Mistral, DeepSeek,
   Hetzner Inference (EU) and Local model.
   Each card shows whether it is configured, which model it will use, and what
   it can do (vision, tools, thinking, effort, native MCP, documents).
3. Pick the instance default with the radio on the card, then click
   **Configure** to open it:
   - **API key** — from the provider's console. Stored encrypted in Nextcloud's
     credential manager. The Local model card takes an endpoint URL instead; its
     key is optional (Ollama needs none). The cloud cards take a region, project
     or resource name and offer the platform's own authentication modes.
   - **Default model** — the live model list from the provider, with
     **Refresh models** to re-query it.
   - **Effort** and **thinking** (Claude card) — the defaults for chat. See
     [Thinking and effort](#thinking-and-effort); the card states how the
     selected model treats both and flags a value it cannot honour.
   - **Advanced** — max output tokens, request timeout, and any
     provider-specific options. On the Claude card this is also where the
     **background task** effort and thinking, **service tier** and **fast mode**
     live; they are Anthropic concepts and do not apply to the other
     providers. See [Latency and capacity](#latency-and-capacity) for the last
     two, both of which do nothing on the default model.
4. Click **Save**, then **Test connection** to send a live request and confirm
   the key reaches the provider.

The other tabs cover instance defaults (unified search, which lists users'
AIquila conversations in the Nextcloud search bar and is on by default,
[automatic prompt caching](../dev/prompt-caching.md)), **MCP servers**, and
**Advanced** (the native MCP connector). Each tab is linkable by its anchor,
e.g. `Settings → Administration → AIquila#mcp`.

**Personal configuration** (optional):

1. Go to **Settings → Personal → AIquila**
2. The **Providers** tab shows the same cards, limited to what you may change:
   your own API key and preferred model per provider, plus which provider is
   your default. Cloud platform credentials are instance-only. On the Claude card you can also set your own default effort
   and thinking. Leave a field blank to inherit the instance setting.
   Endpoint URLs are administrator-only.
3. **Defaults** sets the system prompt and verbose mode new conversations start
   with, and holds the two notification toggles: completed AI tasks are silent
   unless you switch them on, failed ones are reported by default.
   **Connectors** overrides the native MCP connector for your account.

Endpoint URLs stay admin-only deliberately: Nextcloud makes outbound requests to
whatever is stored there, so a user-settable endpoint would be a server-side
request forgery vector.

#### Thinking and effort

**Effort** sets how much work Claude puts into a response; lower is faster and
cheaper. **Thinking** is whether Claude reasons before answering. It takes
three values:

| Value | Meaning |
|---|---|
| *blank* (auto) | Follow the model. The Fable models, Opus 5, Opus 5.5 and Sonnet 5 think adaptively on their own; Opus 4.x and Sonnet 4.6 do not. |
| `on` | Adaptive thinking on every model that supports it. |
| `off` | No thinking, where the model allows it. |

"Off" does not mean the same thing on every model:

| Model | Thinking off |
|---|---|
| Fable 5.1, Fable 5, Opus 5.5 | Not possible — these models always think. Lower the effort to make them cheaper. |
| Opus 5 | Possible up to effort `high`; a higher effort is lowered to `high` while thinking is off. |
| Sonnet 5 | Possible at any effort. |
| Opus 4.x, Sonnet 4.6 | Possible; it is also their default. |

Each request takes the first value set, in this order: the conversation
(`/effort`, `/thinking`) → your personal setting → the instance default → the
model's default. A value the model does not accept is skipped.

`/thinking:on` and `/thinking:off` need a provider with thinking controls, which is
Anthropic. On the other providers they are refused with an error rather than stored,
and switching a conversation to one of those providers clears its thinking override.

Assistant tasks (proofread, headline, summary, …), coworkers and other apps
using AIquila have their own admin defaults, **Effort for background tasks** and
**Thinking for background tasks**, which apply before the chat defaults. Routine
work rarely needs more than `low` or `medium`. A coworker can override both in
its own settings.

```bash
occ aiquila:configure --effort=high --thinking=auto
occ aiquila:configure --task-effort=low --task-thinking=off
```

In the chat, the header shows what the next message will actually do — for
example *Thinking: always on · effort xhigh* — and a reply that involved
thinking carries a collapsible summary of it.

#### Latency and capacity

Two Claude-only settings under **Advanced** trade money for speed. Both are off
by default, and **neither has any effect on the default model**, so check the
model before concluding a setting is broken.

| Setting | What it does | Where it applies | Cost |
|---|---|---|---|
| **Service tier** | `auto` lets a request use priority capacity; `standard_only` never does. Blank leaves your Anthropic account's own default in charge. | Only if your organisation has purchased Priority Tier. Priority Tier is not offered on Opus 5, Sonnet 5 or the Fable models. | None |
| **Fast mode** | Generates output roughly 2.5x faster. | Opus 5.5, Opus 5 and Opus 4.8 only; silently ignored elsewhere. Not available for batched work. | Roughly double the price per token |

Fast mode has its own rate limit, separate from the model's standard one.

Both are also settable from the command line:

```bash
occ aiquila:configure --service-tier=standard_only --fast=on
occ aiquila:configure --show
```

Users can pin fast mode for a single conversation with `/fast:on` and
`/fast:off`; `/fast` with no value returns the conversation to the instance
default. The service tier is instance-wide and has no per-conversation form.

Since the real numbers depend on your account and your traffic, measure rather
than guess:

```bash
occ aiquila:benchmark-tier --model claude-opus-5 --runs 5
```

#### Attachments and the Files API

On the Claude API, images and PDFs attached in a chat are uploaded once to
Anthropic's [Files API](https://platform.claude.com/docs/en/build-with-claude/files)
and referred to by ID on later turns, instead of being sent again with every
message. Each upload is deduplicated per user and expires on Anthropic's side after
30 days. AIquila uploads the file again shortly before that, and a daily job drops
the expired references.

Uploaded files are kept by Anthropic until they expire, so the Files API is **not
eligible for zero data retention**. If your Anthropic agreement requires ZDR, switch
it off under **Defaults** → *Upload attachments to the Files API*, or with:

```bash
occ aiquila:configure --files-api=off
```

Attachments are then sent inline on every turn, and the daily job deletes whatever
was uploaded before, using each user's key. The cloud-platform providers always
send attachments inline (see [Cloud providers](../dev/cloud-providers.md)).

It reports p50/p95 latency and output tokens per second for each variant the
model can run, plus the tier the API says it actually served. The requests are
real and are billed to your account, so it asks before starting.

### 6. Pick a provider per conversation

Provider and model are snapshotted when a conversation is created, so changing
your default afterwards leaves existing conversations answering from where they
started.

The picker in the chat header changes both for the open conversation only. It
lists just the providers you have credentials for. Choosing **Follow my
default** unpins the conversation so it tracks your personal setting again.

## Features

### 1. Chat Interface

Access at **`/apps/aiquila`**

Features:
- Interactive conversation with Claude
- Conversation history with search
- File and image attachments (see below)
- Slash commands (`/add-file`, `/add-directory`, `/verbose`, `/search`, and more)
- Markdown rendering in responses
- Clean, responsive design
- Per-conversation provider and model picker in the header
- Settings gear linking to your personal AIquila settings

Usage:
1. Navigate to `/apps/aiquila`
2. Type your question in the text area
3. Press **Enter** or click **Send**
4. See Claude's response appear in the chat history

#### Attaching Files

You can attach files to your message so Claude can read or analyze them:

| Method | How |
|--------|-----|
| **Attachment button** | Click the 📎 button next to Send to open the Nextcloud file picker |
| **Slash command** | Type `/add-file` and press Enter to open the file picker (multi-select) |
| **Directory context** | Type `/add-directory` to attach a directory listing |
| **Drag & drop** | Drag files from your desktop or browser into the chat input area |
| **Clipboard paste** | Press `Ctrl+V` to paste an image from your clipboard |

Attached files appear as chips above the text input (with thumbnails for images). Pasted and dropped images are automatically uploaded to the `/AIquila Uploads` folder in your Nextcloud files.

An attached file stays in context for the rest of the conversation, so you can ask follow-up questions without attaching it again. Each turn reads the file's current version from Nextcloud. If you delete the file, Claude is told it can no longer be read.

#### Asking about a file from the Files app

In the Files app, open a file's **⋯** menu and choose **Ask AIquila**. This starts a conversation with the file attached, and you can keep asking follow-ups in the dialog. **Open in AIquila** continues the same conversation in the chat.

### 2. Nextcloud Assistant Integration

AIquila registers itself against Nextcloud's TaskProcessing framework, so it shows up
wherever the server offers an AI action — the Assistant, the Files and Photos context
menus, Text, Talk and anything else built on the same API. No extra configuration is
needed.

#### In the Text editor

The Text editor shows a **Nextcloud Assistant** menu (free prompt, summarize, headline,
topics, formalize, simplify, reformulate, translate) as soon as AIquila serves the
matching task types. Select some text, pick an action, and the result can be inserted
in place of the selection or copied.

Text only provides the menu; each entry opens a dialog that normally comes from the
**Assistant** app. When Assistant is installed, its dialog is used. When it is not,
AIquila supplies its own: the same actions, run through the same task queue, with the
editor's **Insert** buttons. Closing the dialog does not cancel a running action — the
result is listed under **Show assistant results** in the editor's menu. Assistant adds
a fuller task history and notifications on top. The admin overview
(**Settings → Administration → Overview**) and `occ aiquila:doctor` show which of the
two serves the editor.

| Task type | What it does |
|---|---|
| `core:text2text` | Free-form prompt |
| `core:text2text:chat` | Multi-turn chat, with history and a system prompt |
| `core:text2text:chatwithtools` | Multi-turn chat where the caller supplies the tools and runs them |
| `core:text2text:multimodal-chatwithtools` | The same, with images, PDFs and text files attached to the message; returns no files (Nextcloud 35+) |
| `core:text2text:summary` | Summarize |
| `core:text2text:headline` | Suggest a headline |
| `core:text2text:topics` | Extract topics |
| `core:text2text:translate` | Translate between the server's languages; the source language can be auto-detected |
| `core:text2text:proofread` | Proofread |
| `core:text2text:changetone` | Change tone (formal, friendly, casual, polite, humorous, confident or urgent) |
| `core:text2text:simplification` | Simplify |
| `core:text2text:reformulation` | Reformulate |
| `core:text2text:formalization` | Make formal |
| `core:text2text:reformatparagraphs` | Split into topic-separated paragraphs (Nextcloud 34+) |
| `core:text2text:improve` | Improve a text following free-form instructions; backs the Assistant's **Improve** button (Nextcloud 35+) |
| `core:contextwrite` | Write about a subject in the voice of a sample |
| `core:generateemoji` | Suggest an emoji for a text |
| `core:analyze-images` | Ask a question about one or more images |
| `core:image2text:ocr` | Extract the text visible in images |
| `core:audio2text` | Transcribe a recording |
| `core:audio2text:subtitles` | Subtitles for a recording or video, as SubRip (default) or WebVTT (Nextcloud 35+) |
| `core:text2speech` | Read a text out as audio |
| `core:text2image` | Generate images from a description |
| `core:audio2audio:chat` | Voice chat: a spoken question answered with spoken audio |
| `core:audio2audio:translate` | A recording spoken again in another language; the source language can be auto-detected (Nextcloud 35+) |

Assistant actions run on whichever AI provider the user has selected — the same
choice that drives chat — within what the admin allows. A task may also pin a
provider explicitly through the optional `provider` input.

Not every provider can serve every action. Anything beyond plain text needs the
matching capability, shown as a chip on the provider's card in the AIquila settings:

| Action | Needs | Providers that offer it |
|---|---|---|
| Image questions and OCR, images attached to a tool chat | `vision` | Claude, Mistral, Hetzner (model-dependent), Local (admin flag) |
| Transcription, subtitles, voice chat, audio translation | `transcription` | Mistral, Local (admin flag) |
| Generated speech, voice chat, audio translation | `speech` | Mistral, Local (admin flag) |
| Generated images | `image generation` | Mistral |
| PDFs attached to a tool chat | `documents` | Claude |

Picking a provider without the capability an action needs is an error rather than a
silent hand-off to a different one — the point of choosing a provider is that the data
goes where you chose. The action fails with a message naming the providers you could
switch to. Voice chat needs one provider holding both audio capabilities, so that a
spoken message and its reply do not travel to two different vendors.

AIquila does not serve these Nextcloud task types:

| Task type | Why not |
|---|---|
| `core:contextagent:interaction`, `core:contextagent:audio-interaction`, `core:contextagent:multimodal-interaction` | The Context Agent runs its own agent loop over Nextcloud tools; install the `context_agent` app, which drives its model through `core:text2text:chatwithtools` and `core:text2text:multimodal-chatwithtools` — so AIquila can still be the model behind it ([why](../dev/context-agent.md)) |

Some features in Nextcloud's AI overview are built on top of these task types rather
than being task types themselves. **Document generation** in Nextcloud Office runs
on `core:text2text`, so AIquila serves it once Office is installed. **Live
transcription** in Talk calls comes from the `live_transcription` ExApp and does not
go through TaskProcessing at all.

AIquila answers these tasks but never starts them, and the app that did — the
Assistant, Files, Mail — normally puts the result in front of you already. So a
successful task notifies nobody unless the user turns it on under
**Settings → Personal → AIquila → Defaults**. A failed one does notify by default:
it usually means AIquila itself needs attention, such as a rejected API key, an
exhausted quota or an unreachable endpoint.

A failed task also carries a reason for whoever reads it — the Assistant, and the MCP
`get_task_result` and `translate_text` tools — in the task's `userFacingErrorMessage`.
AIquila's own refusals (missing input, a provider without the needed capability) appear
as written. A provider's error is reduced to a fixed sentence for its kind of failure:
provider unreachable, credentials rejected, rate limit or quota reached, model not
offered, input too large, or provider not set up. Provider errors often quote the
upstream response, so the full text is not shown there. It stays in the task's
`errorMessage`, the Nextcloud log and the failure notification. An error that fits
none of these kinds gets no user-facing reason.

### 3. Flow: process files automatically

With Nextcloud's **Flow** app (`workflowengine`, shipped with the server), AIquila
adds the operation **Process with AIquila** under **Settings → Personal → Flow**
(your own files) and **Settings → Administration → Flow** (everyone's). Combine it
with any file trigger and conditions, for example *File created* with
*File name matches* `/\.pdf$/` and *File is in folder* `Invoices`, or with
*Tag assigned*.

| Action | What it does |
|---|---|
| **Summarize text and PDFs, describe images** (default) | Picks summarize or describe by file type |
| **Summarize** | A one-sentence overview, then the key points |
| **Describe** | What the file shows, including any visible text |
| **Ask a question** | Runs your own prompt (up to 4000 characters) against the file |

The answer is saved as `<file name>.aiquila.md` in the same folder, replacing the
previous one, with the prompt quoted for **Ask a question**. These result files never
trigger the operation themselves.

The work runs in a background job, so cron has to be running, and results arrive
on the next cron run rather than during the upload. The file is processed as its
owner, on the provider that owner has selected and within the admin's access rules.
Text files (`text/*`, JSON, XML, YAML) work everywhere. Images need a `vision`
provider and PDFs a `documents` provider (see the capability table above). For
other types, an incapable provider, or a folder the owner cannot write to, nothing
is written and the reason is logged.

### 4. Cowork: scheduled jobs

A **coworker** is an AI job that runs on a schedule, for example "every night at
03:00, summarize the new documents in `Reports`". Open AIquila and choose **Cowork**
in the sidebar. Start from a template under **Add a coworker**, or build a
**Custom coworker**. Each coworker has:

- a **task**: what it does (see below);
- a **source folder** it works on, and optionally an output folder;
- a **schedule** in five-field cron syntax (`minute hour day-of-month month
  day-of-week`): `0 3 * * *` is every day at 03:00, `0 8 * * 1` every Monday at
  08:00, `*/30 * * * *` every half hour. Schedules are evaluated in **UTC**;
- a **provider and model**, and optionally effort and thinking. Otherwise the
  background-task defaults apply (see [Thinking and effort](#thinking-and-effort)).

| Task | What it does | Options |
|---|---|---|
| `vision:classify` | Labels each image and stores the labels as collaborative tags, so they can be searched and filtered in Files | `maxTags` (1–30, default 8), `recursive` |
| `docs:summarize` | Writes `<name>.summary.md` beside each text document (`text/*` and JSON by default; PDFs are not read) | `recursive`, `force`, `outputFolder`, `mimeTypes`, `maxBytesPerFile` (default 1 MiB) |
| `docs:translate` | Writes `<name>.<language>.md` beside each document | `targetLanguage`, plus the options of `docs:summarize` |

The document tasks only redo a file when it changed since its result was written,
unless `force` is set, and never process their own output. A run handles up to 200
documents; the rest wait for the next run. `vision:classify` needs a `vision` provider.

Each coworker can be paused, run immediately with **Run now**, and keeps a run
history with a summary of what it did. The **Coworkers** and **Coworker output**
Dashboard widgets show the latest results, and the [MCP cowork tools](../mcp/tools/apps/cowork.md)
manage coworkers from Claude.

How it runs:

- Coworkers run from Nextcloud's background jobs, so **cron must be running**
  (Settings → Administration → Basic settings → Background jobs → Cron). A due
  coworker starts on the first cron run after its scheduled time, so with the usual
  5-minute cron interval it can start up to five minutes late.
- On the Claude API, the document tasks submit their work through Anthropic's
  [Message Batches API](../dev/batch-processing.md) at half the price. The run then
  stays *pending* until the batch finishes, which is usually minutes and at most 24
  hours. Other providers process the files one by one within the run, at the normal
  price.
- Other apps can register coworkers and their own task types through the
  [Cowork management API](../cowork-api.md).

### 5. Talk bot

AIquila can take part in Nextcloud Talk conversations. Write `@AIquila` in a message,
or reply to one of its messages, and it answers in the conversation. The last 30
messages serve as context, so "@AIquila summarize what we decided" works. It works in
group conversations and one-to-one chats alike.

Talk only accepts bots set up by an administrator:

```bash
occ aiquila:talk-bot install      # register the bot with Talk
occ aiquila:talk-bot status
occ aiquila:talk-bot uninstall    # remove it and delete the stored messages
```

After installing, a moderator adds **AIquila** to a conversation under
**Conversation settings → Bots**. An administrator can also do it with
`occ talk:bot:list` and `occ talk:bot:setup <bot-id> <conversation-token>`.

How it works:

- It is a Talk *app bot*: Talk hands AIquila every message of the conversations the
  bot is enabled in, inside Nextcloud, with no webhook. AIquila keeps the last 30
  messages of each such conversation in its database, because Talk gives a bot no way
  to read history. Messages sent before the bot was added are not known to it.
- An addressed message gets a 👀 reaction straight away. The answer comes from a
  background job, so it arrives with the next cron run. For prompt answers, run a
  dedicated worker such as
  `occ background-job:worker 'OCA\AIquila\BackgroundJob\TalkBotReplyJob'`.
- The answer is generated on the asking user's AIquila provider, within the admin's
  access rules. Guests and federated users get the instance default.
- Replies are posted through Talk's bot API on this server, at its `overwrite.cli.url`.
  If the server cannot reach itself there (a reverse proxy only reachable from
  outside, a container port mapping), give the internal address:
  `occ aiquila:talk-bot install --base-url=http://localhost`. An empty `--base-url=`
  goes back to `overwrite.cli.url`.

Requires Talk on Nextcloud 33 or later.

### 6. Public API

Other Nextcloud apps can programmatically use AIquila:

**Ask Claude:**
```http
POST /apps/aiquila/api/ask
Content-Type: application/json

{
  "prompt": "Your question here",
  "context": "Optional context to provide"
}
```

**Response:**
```json
{
  "response": "Claude's answer...",
  "model": "claude-sonnet-4-5-20250929",
  "usage": {
    "input_tokens": 15,
    "output_tokens": 120
  }
}
```

**Summarize Text:**
```http
POST /apps/aiquila/api/summarize
Content-Type: application/json

{
  "content": "Long text to summarize..."
}
```

**Response:**
```json
{
  "summary": "Concise summary...",
  "original_length": 5000,
  "summary_length": 150
}
```

See [internal-api.md](../internal-api.md) for complete API documentation.

## Verification

### Quick Tests

1. **Chat Interface**:
   - Go to `/apps/aiquila`
   - Ask "What is Nextcloud?"
   - Verify you get a response

2. **Admin Test**:
   - Go to **Settings → Administration → AIquila**
   - Open the default provider's card and click **Test connection**
   - Should see the provider's reply inline

3. **Assistant Integration**:
   - Use Nextcloud Assistant anywhere in the UI
   - Select "AIquila" as the provider
   - Verify it responds to prompts

## Troubleshooting

### Common Issues

#### "Class does not exist" Errors

**Problem:** PHP can't find AIquila classes

**Solutions:**
```bash
# 1. Install Composer dependencies
cd /path/to/nextcloud/custom_apps/aiquila
composer install

# 2. Check vendor directory exists
ls -la vendor/

# 3. Verify autoloader was created
ls -la vendor/autoload.php

# 4. Fix file permissions
chmod 644 lib/**/*.php
find lib -type f -exec chmod 644 {} \;
```

#### JavaScript Not Loading

**Problem:** Page loads but interface doesn't appear

**Solutions:**
```bash
# 1. Build the frontend
cd /path/to/nextcloud/custom_apps/aiquila
npm run build

# 2. Verify build output
ls -la js/aiquila-main.js

# 3. Check browser console for errors
# Open developer tools (F12) and check Console tab

# 4. Fix JS file permissions
chmod 644 js/*.js
```

#### API Key Not Working

**Problem:** "Invalid API key" or authentication errors

**Solutions:**
1. Verify API key is valid at [console.anthropic.com](https://console.anthropic.com)
2. Check you copied the entire key (starts with `sk-ant-`)
3. Remove any extra spaces or newlines
4. Open the provider's card in **Settings → Administration → AIquila** and click **Test connection**
5. Check Nextcloud logs:
   ```bash
   tail -f /path/to/nextcloud/data/nextcloud.log
   ```

#### Network/Connection Errors

**Problem:** "Failed to connect" or timeout errors

**Solutions:**
1. Verify server can reach `api.anthropic.com`:
   ```bash
   curl -I https://api.anthropic.com
   ```
2. Check firewall rules allow HTTPS outbound
3. If using a proxy, configure PHP to use it
4. Raise **Request timeout** under **Advanced** on the provider's card (default: 30s; the local endpoint defaults to 300s)

#### Permission Errors

**Problem:** "Permission denied" when reading files

**Solution:**
```bash
# Fix all permissions at once
cd /path/to/nextcloud/custom_apps/aiquila
find . -type f -exec chmod 644 {} \;
find . -type d -exec chmod 755 {} \;

# Verify web server can read files
sudo -u www-data cat lib/AppInfo/Application.php
```

### Advanced Debugging

**Enable debug mode in Nextcloud:**

Edit `config/config.php`:
```php
'debug' => true,
'loglevel' => 0,
```

**Check Nextcloud logs:**
```bash
tail -f /path/to/nextcloud/data/nextcloud.log | grep -i aiquila
```

**Test Anthropic API directly:**
```bash
curl -X POST https://api.anthropic.com/v1/messages \
  -H "x-api-key: YOUR_API_KEY" \
  -H "anthropic-version: 2023-06-01" \
  -H "content-type: application/json" \
  -d '{
    "model": "claude-sonnet-4-5-20250929",
    "max_tokens": 100,
    "messages": [{"role": "user", "content": "Hello"}]
  }'
```

## Resources

Need help? Check out these resources:

- 📦 [GitHub Repository](https://github.com/elgorro/aiquila)
- 📖 [Documentation](https://github.com/elgorro/aiquila/tree/main/docs)
- 🐛 [Report Issues](https://github.com/elgorro/aiquila/issues)
- 💬 [Discussions](https://github.com/elgorro/aiquila/discussions)

## Next Steps

- [MCP Server Setup](../mcp/setup.md) - Connect MCP clients to your Nextcloud
- [Internal API Guide](../internal-api.md) - Integrate AIquila into your own apps
- [Docker Development](../dev/docker-setup.md) - Set up complete development environment

## Getting Help

If you're still having issues:

1. Search existing [issues](https://github.com/elgorro/aiquila/issues)
2. Ask in [discussions](https://github.com/elgorro/aiquila/discussions)
3. Open a new issue with:
   - Nextcloud version (`Settings → Administration → Overview`)
   - PHP version (`php -v`)
   - Node.js version (`node -v`)
   - Complete error messages from logs
   - Steps to reproduce the problem
