# Getting Started

Choose the path that matches how you want to use AIquila.

## Path 1: Claude Desktop / Claude Code (simplest)

Give Claude direct access to your Nextcloud — files, calendar, tasks, contacts, mail, bookmarks, maps, notes, recipes, and more.

**Prerequisites:** Node.js 20+, a Nextcloud instance, a Nextcloud app password.

```bash
# 1. Run with npx (no clone needed)
npx aiquila-mcp

# 2. Or add to Claude Desktop config (~/.config/claude/claude_desktop_config.json):
```

```json
{
  "mcpServers": {
    "aiquila": {
      "command": "npx",
      "args": ["-y", "aiquila-mcp"],
      "env": {
        "NEXTCLOUD_URL": "https://cloud.example.com",
        "NEXTCLOUD_USER": "your-username",
        "NEXTCLOUD_PASSWORD": "your-app-password"
      }
    }
  }
}
```

```bash
# 3. Restart Claude Desktop
# 4. Ask Claude: "List my Nextcloud files"
```

**[Full MCP Setup Guide →](mcp/setup.md)**

---

## Path 2: Claude.ai (remote MCP)

Connect Claude.ai to your Nextcloud via Docker + OAuth 2.0. Requires a publicly accessible server.

```bash
# 1. Clone and configure
git clone https://github.com/elgorro/aiquila.git
cd aiquila/docker/standalone
cp .env.example .env
nano .env   # Set NEXTCLOUD_URL, credentials, and MCP_AUTH_* vars

# 2. Start the stack
make up

# 3. Add the MCP server URL in Claude.ai settings
# 4. Complete OAuth login when prompted
```

**[Standalone Docker Guide →](mcp/standalone-docker.md)** | **[OAuth Setup →](mcp/oauth.md)**

---

## Path 3: Nextcloud App

Add Claude AI directly inside your Nextcloud UI — chat interface, text processing, and public API.

**Prerequisites:** Nextcloud 33+, PHP 8.4+, Claude API key.

```bash
# 1. Install from Nextcloud App Store (recommended)
# Settings → Apps → search "AIquila" → Install

# 2. Or install manually:
cd nextcloud-app && composer install && npm install && npm run build
cp -r nextcloud-app /path/to/nextcloud/custom_apps/aiquila
sudo -u www-data php occ app:enable aiquila

# 3. Configure API key: Settings → Administration → AIquila
# 4. Open /apps/aiquila to start chatting
```

**[Full Nextcloud App Setup →](installation/aiquila-setup.md)**

---

## Path 4: Self-hosted on Hetzner Cloud

Single-command provisioning of a production-ready AIquila server with Traefik, CrowdSec, and TLS.

```bash
# 1. Install the CLI
# Download from GitHub Releases or build from source
cd hetzner && go build -o aiquila-hetzner .

# 2. Provision a server
./aiquila-hetzner create \
  --stack full \
  --mcp-domain mcp.example.com \
  --nc-domain cloud.example.com \
  --nc-admin-user admin \
  --nc-admin-password "secure-password"

# 3. DNS: point both domains to the server IP
# 4. TLS certificates are provisioned automatically
```

**[Hetzner Deployment Guide →](hetzner/README.md)**

---

## Path 5: Claude Mobile App (voice)

Use Claude on your phone with voice input to manage Nextcloud hands-free — list tasks, create notes, check your calendar, all by speaking.

**Prerequisites:** Same as Path 2 (Docker + OAuth on a publicly accessible server), plus the Claude mobile app (iOS/Android).

```bash
# 1. Set up the MCP server with OAuth (see Path 2)
#    → Standalone Docker Guide + OAuth Setup

# 2. Install the Claude app on iOS or Android

# 3. In the Claude app, go to Settings → MCP Servers
#    Add your MCP server URL (e.g. https://mcp.example.com/mcp)
#    Complete the OAuth login when prompted

# 4. (Optional) Set up voice input:
```

**Voice input options:**

- **FUTO Voice** (recommended, open-source) — Install from [F-Droid](https://f-droid.org) or Play Store, set as your default keyboard, then tap the microphone icon in the Claude app to dictate.
- **Google Assistant** — Use "Hey Google, ask Claude..." or the share-to-Claude workflow on Android.
- **iOS Dictation** — Use the built-in dictation button on the iOS keyboard.

**Example voice commands:**
- "List my tasks for today"
- "Create a note called grocery list with milk, eggs, and bread"
- "What's on my calendar tomorrow?"
- "Search my files for the project proposal"

**[Standalone Docker Guide →](mcp/standalone-docker.md)** | **[OAuth Setup →](mcp/oauth.md)**

---

## Which path is right for me?

| I want to... | Path |
|--------------|------|
| Use Claude Desktop/Code with Nextcloud | **Path 1** — `npx aiquila-mcp` |
| Use Claude.ai with Nextcloud | **Path 2** — Docker + OAuth |
| Add AI features inside Nextcloud | **Path 3** — Nextcloud App |
| Deploy everything on a fresh server | **Path 4** — Hetzner |
| Use Claude on my phone with voice | **Path 5** — Claude Mobile + Voice |
| Use multiple paths together | All paths work independently and together |

## What's next?

- **[Full Documentation](README.md)** — architecture, configuration, and all guides
- **[MCP Tools Reference](mcp/README.md#tools-reference)** — 100+ tools across 20 modules
- **[Internal API](internal-api.md)** — integrate AIquila into your own Nextcloud apps
- **[Connectivity Guide](connectivity.md)** — network and connection troubleshooting
