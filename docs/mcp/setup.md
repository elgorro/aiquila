# AIquila MCP Server Setup Guide

This guide will walk you through setting up the AIquila MCP Server to connect Claude Desktop (or other MCP clients) to your Nextcloud instance.

## Prerequisites

- **Node.js** 18 or higher
- **Nextcloud** instance with WebDAV access
- **Nextcloud credentials** (URL, username, password)
- **Claude Desktop** or another MCP-compatible client

## Installation

### 1. Install AIquila

If you haven't already, install AIquila following the [main installation guide](../installation.md).

### 2. Build the MCP Server

```bash
cd mcp-server
npm install
npm run build
```

This will:
- Install all dependencies
- Compile TypeScript to JavaScript in the `dist/` directory

## Configuration

### Environment Variables

The MCP server requires three environment variables to connect to your Nextcloud:

- `NEXTCLOUD_URL` - Your Nextcloud instance URL (e.g., `https://cloud.example.com`)
- `NEXTCLOUD_USER` - Your Nextcloud username
- `NEXTCLOUD_PASSWORD` - Your Nextcloud password or app-specific password

### Setting Up Environment Variables

#### Option 1: Using .env file (Recommended for development)

Create a `.env` file in the `mcp-server` directory:

```bash
NEXTCLOUD_URL=https://cloud.example.com
NEXTCLOUD_USER=your-username
NEXTCLOUD_PASSWORD=your-password
```

**Note**: Never commit this file to version control. It's already in `.gitignore`.

#### Option 2: System environment variables

Add to your shell configuration (`.bashrc`, `.zshrc`, etc.):

```bash
export NEXTCLOUD_URL="https://cloud.example.com"
export NEXTCLOUD_USER="your-username"
export NEXTCLOUD_PASSWORD="your-password"
```

### Security Best Practice: App Passwords

For better security, use Nextcloud **App Passwords** instead of your main password:

1. Log in to Nextcloud
2. Go to **Settings** → **Security**
3. Under **Devices & sessions**, create a new app password
4. Name it "MCP Server" or similar
5. Use this generated password for `NEXTCLOUD_PASSWORD`

## Claude Desktop Configuration

### 1. Locate Claude Desktop Config

The configuration file location depends on your operating system:

- **macOS**: `~/Library/Application Support/Claude/claude_desktop_config.json`
- **Windows**: `%APPDATA%\Claude\claude_desktop_config.json`
- **Linux**: `~/.config/Claude/claude_desktop_config.json`

### 2. Add AIquila MCP Server

Edit the configuration file to add the AIquila MCP server:

```json
{
  "mcpServers": {
    "aiquila": {
      "command": "node",
      "args": ["/absolute/path/to/aiquila/mcp-server/dist/index.js"],
      "env": {
        "NEXTCLOUD_URL": "https://cloud.example.com",
        "NEXTCLOUD_USER": "your-username",
        "NEXTCLOUD_PASSWORD": "your-app-password"
      }
    }
  }
}
```

**Important**: Replace `/absolute/path/to/aiquila` with the actual absolute path on your system.

### 3. Alternative: Using npm Script

Instead of `node dist/index.js`, you can use the npm start script:

```json
{
  "mcpServers": {
    "aiquila": {
      "command": "npm",
      "args": ["start"],
      "cwd": "/absolute/path/to/aiquila/mcp-server",
      "env": {
        "NEXTCLOUD_URL": "https://cloud.example.com",
        "NEXTCLOUD_USER": "your-username",
        "NEXTCLOUD_PASSWORD": "your-app-password"
      }
    }
  }
}
```

### 4. Restart Claude Desktop

After saving the configuration:
1. Quit Claude Desktop completely
2. Restart Claude Desktop
3. The MCP server will automatically start when Claude Desktop launches

## Verification

### Check Connection

Ask Claude:
```
Can you list the files in my Nextcloud root directory?
```

If successful, you should see a list of your Nextcloud files.

### View Available Tools

In Claude Desktop, you can check which tools are available by asking:
```
What Nextcloud tools are available?
```

You should see 12 tools:
- 5 file system tools
- 2 Tasks tools
- 1 Cookbook tool
- 1 Notes tool
- 3 AIquila internal tools

## Troubleshooting

### Connection Issues

**Problem**: "Missing Nextcloud credentials" error

**Solution**: Ensure all three environment variables are set correctly in your Claude Desktop config.

---

**Problem**: "Failed to connect to Nextcloud"

**Solution**:
- Verify `NEXTCLOUD_URL` is correct and accessible
- Check if your Nextcloud instance requires HTTPS
- Test WebDAV access manually: `curl -u username:password https://cloud.example.com/remote.php/dav/files/username/`

---

**Problem**: "Authentication failed"

**Solution**:
- Verify username and password/app-password are correct
- Try generating a new app password
- Check if two-factor authentication is enabled (use app password)

### Build Issues

**Problem**: TypeScript compilation errors

**Solution**:
```bash
cd mcp-server
rm -rf node_modules dist
npm install
npm run build
```

---

**Problem**: Module not found errors

**Solution**: Ensure you're using Node.js 18 or higher:
```bash
node --version
```

### Runtime Issues

**Problem**: Tools not appearing in Claude Desktop

**Solution**:
1. Check Claude Desktop logs (Help → Show Logs)
2. Verify the MCP server path in config is absolute
3. Restart Claude Desktop completely

---

**Problem**: "OCC commands must be run on the Nextcloud server"

**Solution**: This is expected. AIquila configuration tools provide instructions for running OCC commands manually. See [AIquila Tools documentation](tools/apps/aiquila.md).

## Development Mode

For active development with hot reload:

```bash
cd mcp-server
npm run dev
```

This uses `tsx` to watch for file changes and automatically restart the server.

## Testing

Run the test suite:

```bash
cd mcp-server
npm test
```

## Next Steps

- Read the [Tools Reference](README.md#tools-reference) to learn what you can do
- Explore [Usage Examples](README.md#usage-examples)
- Learn to extend the server: [Development Guides](development/)

## Getting Help

- **Issues**: [GitHub Issues](https://github.com/elgorro/aiquila/issues)
- **Discussions**: [GitHub Discussions](https://github.com/elgorro/aiquila/discussions)
- **Documentation**: [Main docs](../README.md)
