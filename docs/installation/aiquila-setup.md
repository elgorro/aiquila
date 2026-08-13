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

Sonnet 5 also rejects the `temperature`, `top_p`, and `top_k` sampling
parameters; AIquila omits them automatically for models that don't accept them,
so no configuration change is needed.

To stay on the previous model:

```bash
php occ aiquila:configure --model claude-sonnet-4-6
```

## Prerequisites

- Nextcloud 33 or higher
- PHP 8.4 or higher with Composer
- Node.js 20 or higher (for building frontend)
- npm 10 or higher
- Claude API key from [console.anthropic.com](https://console.anthropic.com)

## What You Get

AIquila provides three main features:

1. **Chat Interface**: Interactive chat with Claude AI at `/apps/aiquila`
2. **Text Processing Provider**: Native integration with Nextcloud Assistant
3. **Public API**: RESTful endpoints for other apps to use Claude

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
   (Anthropic), Mistral, DeepSeek, Hetzner Inference (EU) and Local model.
   Each card shows whether it is configured, which model it will use, and what
   it can do (vision, tools, thinking, effort, native MCP, documents).
3. Pick the instance default with the radio on the card, then click
   **Configure** to open it:
   - **API key** — from the provider's console. Stored encrypted in Nextcloud's
     credential manager. The Local model card takes an endpoint URL instead; its
     key is optional (Ollama needs none).
   - **Default model** — the live model list from the provider, with
     **Refresh models** to re-query it.
   - **Advanced** — max output tokens, request timeout, and any
     provider-specific options. On the Claude card this is also where
     **effort** and **adaptive thinking** defaults live; they are Anthropic
     concepts and do not apply to the other providers.
4. Click **Save**, then **Test connection** to send a live request and confirm
   the key reaches the provider.

The other tabs cover instance defaults (unified search), **MCP servers**, and
**Advanced** (the native MCP connector). Each tab is linkable by its anchor,
e.g. `Settings → Administration → AIquila#mcp`.

**Personal configuration** (optional):

1. Go to **Settings → Personal → AIquila**
2. The **Providers** tab shows the same cards, limited to what you may change:
   your own API key and preferred model per provider, plus which provider is
   your default. Leave a field blank to inherit the instance setting.
   Endpoint URLs are administrator-only.
3. **Defaults** sets the system prompt and verbose mode new conversations start
   with; **Connectors** overrides the native MCP connector for your account.

Endpoint URLs stay admin-only deliberately: Nextcloud makes outbound requests to
whatever is stored there, so a user-settable endpoint would be a server-side
request forgery vector.

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

### 2. Nextcloud Assistant Integration

AIquila automatically registers as a Text Processing Provider, making it available throughout Nextcloud:

- Accessible via Nextcloud's native Assistant
- Works with any feature using text processing
- No additional configuration needed
- Seamlessly integrated into workflows

### 3. Public API

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
   - Select "Claude (AIquila)" as the provider
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
