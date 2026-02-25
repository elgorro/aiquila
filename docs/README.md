# AIquila Documentation

Complete documentation for the AIquila Nextcloud app and MCP server.

## Quick Links

### Installation
- 📋 **[Installation Overview](installation.md)** - Start here
- 🔧 **[AIquila App Setup](installation/aiquila-setup.md)** - Nextcloud app installation
- 🖥️ **[MCP Server Setup](installation/mcp-installation.md)** - Claude Desktop integration

### Usage
- 🔌 **[Internal API Guide](internal-api.md)** - Integrate AIquila into your apps
- 🌐 **[Connectivity Guide](connectivity.md)** - Network and connection troubleshooting

### MCP Server
- 📡 **[MCP Documentation](mcp/README.md)** - Model Context Protocol server documentation
- ⚙️ **[MCP Setup Guide](mcp/setup.md)** - Configure Claude Desktop
- 🛠️ **[MCP Tools Reference](mcp/README.md#tools-reference)** - Available tools and usage
- 📚 **[MCP Development](mcp/development/)** - Extend the MCP server

### Hetzner / Self-hosting
- 🚀 **[Hetzner Deployment](hetzner/README.md)** — Provision AIquila on Hetzner Cloud

### Development
- 🐳 **[Docker Setup](dev/docker-setup.md)** - Development environment (run from `docker/installation/`)
- 💻 **[Development Guide](dev/development.md)** - Contributing and development workflow
- ✅ **[Best Practices](dev/best-practices.md)** - Code quality and standards
- 🚀 **[CI/CD](dev/ci-cd.md)** - Continuous integration and deployment
- 🏗️ **[MCP Server Architecture](dev/mcp-server-architecture.md)** - MCP server technical design

## Documentation Structure

```
docs/
├── README.md                    # This file
├── installation.md              # Installation overview & comparison
│
├── installation/                # Installation guides
│   ├── aiquila-setup.md        # Nextcloud app installation
│   └── mcp-installation.md     # MCP server installation
│
├── mcp/                        # MCP Server documentation
│   ├── README.md                # MCP overview & tools reference
│   ├── setup.md                 # MCP setup guide
│   ├── tools/                   # Tool documentation
│   │   ├── system-tools.md      # File system tools
│   │   └── apps/                # App-specific tools
│   │       ├── tasks.md         # Nextcloud Tasks tools
│   │       ├── cookbook.md      # Nextcloud Cookbook tools
│   │       ├── notes.md         # Nextcloud Notes tools
│   │       └── aiquila.md       # AIquila internal tools
│   └── development/             # MCP development guides
│       ├── architecture.md      # MCP architecture overview
│       ├── adding-tools.md      # How to add new tools
│       └── adding-apps.md       # How to add new apps
│
├── hetzner/                    # Hetzner Cloud deployment
│   └── README.md               # Full CLI reference
│
├── dev/                        # Development documentation
│   ├── docker-setup.md         # Docker development environment
│   ├── development.md          # Development workflow
│   ├── best-practices.md       # Code quality guidelines
│   ├── ci-cd.md                # CI/CD setup
│   └── mcp-server-architecture.md  # MCP technical architecture
│
├── internal-api.md             # API documentation
└── connectivity.md             # Networking guide
```

## Getting Started

### For End Users

1. Read the [Installation Overview](installation.md)
2. Follow either:
   - [AIquila App Setup](installation/aiquila-setup.md) for Nextcloud integration
   - [MCP Server Setup](mcp/setup.md) for Claude Desktop
3. Check [Connectivity Guide](connectivity.md) if you have network issues
4. Browse available [MCP Tools](mcp/README.md#tools-reference)

### For Developers

1. Set up your environment: `cd docker/installation && make build-tarball && make up`
2. Read the [Development Guide](dev/development.md)
3. Follow [Best Practices](dev/best-practices.md)
4. Review [Internal API](internal-api.md) for integration
5. Study [MCP Server Architecture](dev/mcp-server-architecture.md) for MCP development

### For Integrators

1. Review the [Internal API Guide](internal-api.md)
2. Check [AIquila App Setup](installation/aiquila-setup.md) for installation
3. See [Connectivity Guide](connectivity.md) for network configuration

## Resources

- 📦 [GitHub Repository](https://github.com/elgorro/aiquila)
- 🐛 [Report Issues](https://github.com/elgorro/aiquila/issues)
