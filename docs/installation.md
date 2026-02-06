# Installation Guide

AIquila provides two main components that can be installed independently:

## 1. AIquila Nextcloud App

**Recommended for most users**

Integrates Claude AI directly into your Nextcloud instance with:
- 💬 **Chat Interface** - Interactive conversations at `/apps/aiquila`
- 🤖 **Text Processing Provider** - Works with Nextcloud Assistant
- 🔌 **Public API** - RESTful endpoints for other apps

**[→ Complete Setup Guide](installation/aiquila-setup.md)**

### Quick Start

```bash
# 1. Install dependencies
cd nextcloud-app
composer install
npm install
npm run build

# 2. Deploy to Nextcloud
cp -r nextcloud-app /path/to/nextcloud/custom_apps/aiquila

# 3. Enable the app
sudo -u www-data php occ app:enable aiquila

# 4. Configure API key
# Go to Settings → Administration → AIquila
```

## 2. MCP Server (Optional)

**For advanced users who want Claude Desktop integration**

Allows Claude Desktop/Mobile to access your Nextcloud files:
- 📁 List, read, and search files
- ✏️ Create, update, and delete files
- 🛠️ Execute Nextcloud OCC commands
- 🔗 Direct integration with Claude conversations

**[→ Complete MCP Setup Guide](installation/mcp-installation.md)**

### Quick Start

```bash
# 1. Build MCP server
cd mcp-server
npm install
npm run build

# 2. Create Nextcloud app password
# Settings → Security → Devices & sessions

# 3. Configure Claude Desktop
# Edit ~/.config/claude/claude_desktop_config.json
```

## Which Should You Install?

| Feature | Nextcloud App | MCP Server |
|---------|--------------|------------|
| Chat with Claude in Nextcloud | ✅ | ❌ |
| Nextcloud Assistant integration | ✅ | ❌ |
| Public API for other apps | ✅ | ❌ |
| Claude Desktop file access | ❌ | ✅ |
| Mobile Claude file access | ❌ | ✅ |
| Requires API key | ✅ | Via app |
| Installation complexity | Medium | Low |

**Recommendation:**
- **Just want AI in Nextcloud?** → Install the Nextcloud app
- **Want Claude Desktop to access files?** → Install MCP server
- **Want both?** → Install both (they work together!)

## Prerequisites

### For Nextcloud App
- Nextcloud 32 or higher
- PHP 8.1+ with Composer
- Node.js 20+ and npm 10+
- Claude API key from [console.anthropic.com](https://console.anthropic.com)

### For MCP Server
- Node.js 20 or higher
- npm 10 or higher
- Nextcloud instance (any version with WebDAV)
- Claude Desktop app
- Nextcloud app password

## Resources

- 📦 [GitHub Repository](https://github.com/elgorro/aiquila)
- 📖 [Documentation](https://github.com/elgorro/aiquila/tree/main/docs)
- 🐛 [Report Issues](https://github.com/elgorro/aiquila/issues)
- 💬 [Discussions](https://github.com/elgorro/aiquila/discussions)

## Documentation

### Installation Guides
- **[AIquila Nextcloud App Setup](installation/aiquila-setup.md)** - Complete Nextcloud app installation
- **[MCP Server Installation](installation/mcp-installation.md)** - Claude Desktop integration

### Usage Guides
- [Internal API Guide](internal-api.md) - Integrate AIquila into your apps
- [Connectivity Guide](connectivity.md) - Network and connection troubleshooting

### Development Guides
- [Docker Development Setup](dev/docker-setup.md) - Development environment
- [Development Guide](dev/development.md) - Contributing and workflow
- [Best Practices](dev/best-practices.md) - Code quality guidelines
- [CI/CD Setup](dev/ci-cd.md) - Continuous integration

## Getting Help

1. Check the relevant setup guide above
2. Search [existing issues](https://github.com/elgorro/aiquila/issues)
3. Ask in [discussions](https://github.com/elgorro/aiquila/discussions)
4. Open a new issue with detailed information

## Quick Troubleshooting

### Nextcloud App Issues
See the [AIquila Setup Guide - Troubleshooting](installation/aiquila-setup.md#troubleshooting) section.

### MCP Server Issues
See the [MCP Installation Guide - Troubleshooting](installation/mcp-installation.md#troubleshooting) section.

## What's Next?

After installation:

1. **Configure your API key** in Nextcloud admin settings
2. **Test the chat interface** at `/apps/aiquila`
3. **Try the Nextcloud Assistant** integration
4. **Explore the public API** for custom integrations
5. **Set up MCP server** if you want Claude Desktop access

Happy chatting with Claude! 🎉
