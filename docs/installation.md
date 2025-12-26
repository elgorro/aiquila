# Installation Guide

## Prerequisites

- Nextcloud Hub 10 (version 31.x)
- Node.js 18 or higher
- npm or yarn
- Claude API key from [console.anthropic.com](https://console.anthropic.com)

## MCP Server Installation

The MCP server allows Claude (mobile/desktop app) to interact with your Nextcloud.

### 1. Build the server

```bash
cd mcp-server
npm install
npm run build
```

### 2. Create a Nextcloud App Password

1. Log into your Nextcloud instance
2. Go to **Settings → Security → Devices & sessions**
3. Create a new app password (name it "AIquila MCP")
4. Save the generated password securely

### 3. Configure Claude Desktop

Edit `~/.config/claude/claude_desktop_config.json` (Linux) or `~/Library/Application Support/Claude/claude_desktop_config.json` (macOS):

```json
{
  "mcpServers": {
    "aiquila": {
      "command": "node",
      "args": ["/absolute/path/to/aiquila/mcp-server/dist/index.js"],
      "env": {
        "NEXTCLOUD_URL": "https://cloud.yourdomain.com",
        "NEXTCLOUD_USER": "your-username",
        "NEXTCLOUD_PASSWORD": "your-app-password"
      }
    }
  }
}
```

### 4. Restart Claude Desktop

The AIquila tools will now be available in your Claude conversations.

## Nextcloud App Installation

The Nextcloud app provides AI features directly within the Nextcloud web interface.

### 1. Copy the app

```bash
cp -r nextcloud-app /path/to/nextcloud/apps/aiquila
```

Or create a symlink for development:
```bash
ln -s /path/to/aiquila/nextcloud-app /path/to/nextcloud/apps/aiquila
```

### 2. Enable the app

Via command line:
```bash
cd /path/to/nextcloud
sudo -u www-data php occ app:enable aiquila
```

Or via web interface:
1. Go to **Settings → Apps**
2. Find "AIquila" in the list
3. Click **Enable**

### 3. Configure API key

**Admin configuration** (applies to all users):
1. Go to **Settings → Administration → AIquila**
2. Enter your Claude API key
3. Click Save

**User configuration** (overrides admin key):
1. Go to **Settings → AIquila**
2. Enter your personal Claude API key

## Verification

### Test MCP Server

In Claude Desktop, try:
- "List my files in Nextcloud"
- "Create a note called 'Test' with content 'Hello World'"

### Test Nextcloud App

1. Navigate to Files in Nextcloud
2. Right-click a text file
3. Select "Ask Claude"
4. Try summarizing the document

## Troubleshooting

### MCP Server not connecting

- Check that the path in `claude_desktop_config.json` is absolute
- Verify credentials by testing WebDAV manually:
  ```bash
  curl -u "user:app-password" https://cloud.yourdomain.com/remote.php/dav/files/user/
  ```
- Check Claude Desktop logs for errors

### Nextcloud App errors

- Check Nextcloud logs: `tail -f /path/to/nextcloud/data/nextcloud.log`
- Verify API key is set correctly
- Ensure your server can reach `api.anthropic.com`
