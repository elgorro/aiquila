# MCP Server Installation

The Model Context Protocol (MCP) server allows Claude Desktop and Mobile apps to interact with your Nextcloud files directly.

## What is MCP?

MCP (Model Context Protocol) is Anthropic's standard for connecting AI assistants to external data sources. The AIquila MCP server gives Claude Desktop access to your Nextcloud files through a secure connection.

**What you can do:**
- List and search Nextcloud files from Claude Desktop
- Read file contents
- Create, update, and delete files
- Work with files without leaving your Claude conversation
- Access Nextcloud OCC commands (admin operations)

## Prerequisites

- Node.js 20 or higher
- npm 10 or higher
- A running Nextcloud instance (with AIquila app installed)
- Claude Desktop app installed
- Nextcloud app password for authentication

## Installation Steps

### 1. Build the MCP Server

```bash
cd mcp-server

# Install dependencies
npm install

# Build the server
npm run build

# Verify build succeeded
ls -la dist/index.js
```

The built server will be in `mcp-server/dist/index.js`.

### 2. Create Nextcloud App Password

App passwords are secure, limited-scope credentials for applications:

1. Log into your Nextcloud instance
2. Go to **Settings → Security → Devices & sessions**
3. Scroll to **App name for new token**
4. Enter "AIquila MCP" (or any name you prefer)
5. Click **Create new app token**
6. **Copy the generated password immediately** (you can't see it again!)
7. Save it securely (you'll need it in the next step)

**Security note:** App passwords only have access to files and data, not admin functions. They can be revoked anytime without affecting your main password.

### 3. Configure Claude Desktop

Claude Desktop uses a JSON configuration file to connect to MCP servers.

**Configuration file location:**
- **Linux**: `~/.config/claude/claude_desktop_config.json`
- **macOS**: `~/Library/Application Support/Claude/claude_desktop_config.json`
- **Windows**: `%APPDATA%\Claude\claude_desktop_config.json`

**Edit the file** (create it if it doesn't exist):

```json
{
  "mcpServers": {
    "aiquila": {
      "command": "node",
      "args": ["/absolute/path/to/aiquila/mcp-server/dist/index.js"],
      "env": {
        "NEXTCLOUD_URL": "https://cloud.yourdomain.com",
        "NEXTCLOUD_USER": "your-username",
        "NEXTCLOUD_PASSWORD": "your-app-password-from-step-2"
      }
    }
  }
}
```

**Important:**
- Use **absolute paths** (not relative like `~/` or `./`)
- Replace `cloud.yourdomain.com` with your actual Nextcloud URL
- Use your Nextcloud username (not email)
- Use the **app password** from step 2, not your regular password
- Don't include trailing slashes in the URL

**Example (Linux):**
```json
{
  "mcpServers": {
    "aiquila": {
      "command": "node",
      "args": ["/home/username/projects/aiquila/mcp-server/dist/index.js"],
      "env": {
        "NEXTCLOUD_URL": "https://nextcloud.example.com",
        "NEXTCLOUD_USER": "john",
        "NEXTCLOUD_PASSWORD": "xxxxx-xxxxx-xxxxx-xxxxx-xxxxx"
      }
    }
  }
}
```

### 4. Restart Claude Desktop

**Complete restart required:**
1. Fully quit Claude Desktop (don't just close the window)
   - **macOS**: Cmd+Q or Claude → Quit
   - **Linux/Windows**: File → Exit or right-click tray icon → Quit
2. Start Claude Desktop again
3. Wait a few seconds for MCP servers to initialize

## Verification

### Check MCP Server is Connected

When you open a new conversation in Claude Desktop, you should see MCP tools available.

**Visual indicators:**
- Look for a tools/plugins icon in the interface
- MCP server status might show in settings or preferences
- Available tools should include AIquila commands

### Test Commands

Try these commands in a Claude conversation:

**List files:**
```
List all files in my Nextcloud
```

**Read a file:**
```
Read the contents of README.md from my Nextcloud
```

**Create a file:**
```
Create a file called "test.txt" with content "Hello from Claude!" in my Nextcloud
```

**Search files:**
```
Search for files containing "meeting notes" in my Nextcloud
```

**OCC commands (admin only):**
```
Check the status of Nextcloud using occ
```

## Available Tools

The MCP server provides these tools to Claude:

### File Operations

- `list_files` - List files in a directory
- `read_file` - Read file contents
- `write_file` - Create or update a file
- `delete_file` - Delete a file
- `search_files` - Search for files by name or content

### System Operations

- `execute_occ` - Run Nextcloud OCC commands (admin only)

### Internal API

- `aiquila_ask` - Ask Claude questions via AIquila app
- `aiquila_summarize` - Summarize text using AIquila app

See [internal-api.md](../internal-api.md) for detailed tool documentation.

## Troubleshooting

### MCP Server Not Appearing

**Problem:** No AIquila tools available in Claude Desktop

**Solutions:**

1. **Verify configuration file exists and is valid JSON:**
   ```bash
   # Linux/macOS
   cat ~/.config/claude/claude_desktop_config.json | python3 -m json.tool

   # Windows (PowerShell)
   Get-Content "$env:APPDATA\Claude\claude_desktop_config.json" | ConvertFrom-Json
   ```

2. **Check absolute path is correct:**
   ```bash
   # Test the path directly
   node /absolute/path/to/aiquila/mcp-server/dist/index.js
   ```

3. **Restart Claude Desktop completely:**
   - Close all windows
   - Quit from system tray/menu bar
   - Wait 5 seconds
   - Relaunch

4. **Check Claude Desktop logs:**
   - **macOS**: `~/Library/Logs/Claude/`
   - **Linux**: `~/.config/claude/logs/`
   - **Windows**: `%APPDATA%\Claude\logs\`

### Authentication Errors

**Problem:** "401 Unauthorized" or "Invalid credentials"

**Solutions:**

1. **Verify app password is correct:**
   ```bash
   # Test WebDAV connection
   curl -u "username:app-password" \
     https://cloud.yourdomain.com/remote.php/dav/files/username/
   ```

2. **Check Nextcloud username:**
   - Use username, not email address
   - Check **Settings → Personal info** for exact username

3. **Regenerate app password:**
   - Go to **Settings → Security → Devices & sessions**
   - Delete old "AIquila MCP" token
   - Create new one
   - Update `claude_desktop_config.json`

4. **Verify Nextcloud URL:**
   - Include `https://` or `http://`
   - No trailing slash
   - Accessible from your machine (test in browser)

### Connection Errors

**Problem:** "Could not connect" or timeout errors

**Solutions:**

1. **Test Nextcloud accessibility:**
   ```bash
   curl -I https://cloud.yourdomain.com
   ```

2. **Check firewall/network:**
   - Ensure Nextcloud URL is accessible
   - Check proxy settings if behind corporate firewall
   - Try from browser first

3. **Verify SSL certificates:**
   ```bash
   # Test SSL connection
   openssl s_client -connect cloud.yourdomain.com:443
   ```

4. **Check Nextcloud is running:**
   - Access Nextcloud in your browser
   - Check server logs if self-hosted

### File Operation Errors

**Problem:** Can't read/write files

**Solutions:**

1. **Check file permissions in Nextcloud:**
   - Ensure user has access to files
   - Check sharing permissions
   - Verify file paths are correct

2. **Test WebDAV access manually:**
   ```bash
   # List files
   curl -u "user:pass" -X PROPFIND \
     https://cloud.yourdomain.com/remote.php/dav/files/user/

   # Read file
   curl -u "user:pass" \
     https://cloud.yourdomain.com/remote.php/dav/files/user/README.md
   ```

3. **Check file paths:**
   - Paths are relative to user's Nextcloud root
   - Use forward slashes: `Documents/notes.txt`
   - Don't start with `/`

### OCC Commands Not Working

**Problem:** OCC commands fail or return errors

**Solutions:**

1. **Verify user has admin privileges:**
   - OCC commands require admin access
   - Check in **Settings → Users**

2. **Check AIquila app is installed:**
   - The Nextcloud app must be enabled
   - Internal API must be working

3. **Test OCC through web interface:**
   - Try using Nextcloud's web-based OCC tools
   - Verify commands work there first

## Security Considerations

### App Password Security

✅ **Do:**
- Create unique app passwords for each device/application
- Store app passwords securely (password manager)
- Revoke unused app passwords regularly
- Use descriptive names ("Claude Desktop - Work Laptop")

❌ **Don't:**
- Share app passwords
- Use your main Nextcloud password
- Commit app passwords to version control
- Reuse app passwords across applications

### Network Security

If accessing Nextcloud over the internet:
- Use HTTPS (SSL/TLS) - never plain HTTP
- Keep Nextcloud updated with security patches
- Use strong passwords
- Enable two-factor authentication on your Nextcloud account
- Consider VPN for remote access

### Revoking Access

To revoke MCP server access:

1. **Remove app password:**
   - Go to **Settings → Security → Devices & sessions**
   - Find "AIquila MCP" token
   - Click delete/revoke

2. **Remove from Claude Desktop:**
   - Edit `claude_desktop_config.json`
   - Remove the `aiquila` server entry
   - Restart Claude Desktop

## Advanced Configuration

### Multiple Nextcloud Instances

You can configure multiple Nextcloud servers:

```json
{
  "mcpServers": {
    "aiquila-work": {
      "command": "node",
      "args": ["/path/to/aiquila/mcp-server/dist/index.js"],
      "env": {
        "NEXTCLOUD_URL": "https://work.example.com",
        "NEXTCLOUD_USER": "john.work",
        "NEXTCLOUD_PASSWORD": "work-app-password"
      }
    },
    "aiquila-personal": {
      "command": "node",
      "args": ["/path/to/aiquila/mcp-server/dist/index.js"],
      "env": {
        "NEXTCLOUD_URL": "https://personal.nextcloud.com",
        "NEXTCLOUD_USER": "john",
        "NEXTCLOUD_PASSWORD": "personal-app-password"
      }
    }
  }
}
```

### Custom Environment Variables

You can add additional environment variables:

```json
{
  "mcpServers": {
    "aiquila": {
      "command": "node",
      "args": ["/path/to/aiquila/mcp-server/dist/index.js"],
      "env": {
        "NEXTCLOUD_URL": "https://cloud.example.com",
        "NEXTCLOUD_USER": "john",
        "NEXTCLOUD_PASSWORD": "app-password",
        "DEBUG": "true",
        "TIMEOUT": "60000"
      }
    }
  }
}
```

## Resources

- 📦 [GitHub Repository](https://github.com/elgorro/aiquila)
- 📖 [Documentation](https://github.com/elgorro/aiquila/tree/main/docs)
- 🐛 [Report Issues](https://github.com/elgorro/aiquila/issues)
- 💬 [Discussions](https://github.com/elgorro/aiquila/discussions)
- 🔧 [MCP Protocol Docs](https://modelcontextprotocol.io)

## Next Steps

- [AIquila Setup](aiquila-setup.md) - Install the Nextcloud app
- [Internal API Guide](../internal-api.md) - Integrate AIquila into your apps
- [Docker Development](../dev/docker-setup.md) - Development environment setup

## Getting Help

Having issues? Here's how to get help:

1. Check the troubleshooting section above
2. Search [existing issues](https://github.com/elgorro/aiquila/issues)
3. Ask in [discussions](https://github.com/elgorro/aiquila/discussions)
4. Open a new issue with:
   - Operating system and version
   - Node.js version (`node -v`)
   - Nextcloud version
   - Complete error messages
   - Contents of `claude_desktop_config.json` (remove passwords!)
   - Claude Desktop version
