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

### Development
- 🐳 **[Docker Setup](dev/docker-setup.md)** - Development environment
- 💻 **[Development Guide](dev/development.md)** - Contributing and development workflow
- ✅ **[Best Practices](dev/best-practices.md)** - Code quality and standards
- 🚀 **[CI/CD](dev/ci-cd.md)** - Continuous integration and deployment

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
├── dev/                        # Development documentation
│   ├── docker-setup.md         # Docker development environment
│   ├── development.md          # Development workflow
│   ├── best-practices.md       # Code quality guidelines
│   └── ci-cd.md                # CI/CD setup
│
├── internal-api.md             # API documentation
└── connectivity.md             # Networking guide
```

## Getting Started

### For End Users

1. Read the [Installation Overview](installation.md)
2. Follow either:
   - [AIquila App Setup](installation/aiquila-setup.md) for Nextcloud integration
   - [MCP Server Setup](installation/mcp-installation.md) for Claude Desktop
3. Check [Connectivity Guide](connectivity.md) if you have network issues

### For Developers

1. Set up your environment with [Docker Setup](dev/docker-setup.md)
2. Read the [Development Guide](dev/development.md)
3. Follow [Best Practices](dev/best-practices.md)
4. Review [Internal API](internal-api.md) for integration

### For Integrators

1. Review the [Internal API Guide](internal-api.md)
2. Check [AIquila App Setup](installation/aiquila-setup.md) for installation
3. See [Connectivity Guide](connectivity.md) for network configuration

## Contributing to Documentation

Found an error or want to improve the docs?

1. Fork the repository
2. Make your changes
3. Submit a pull request

Or open an issue at [GitHub Issues](https://github.com/elgorro/aiquila/issues).

## Resources

- 📦 [GitHub Repository](https://github.com/elgorro/aiquila)
- 🐛 [Report Issues](https://github.com/elgorro/aiquila/issues)
- 💬 [Discussions](https://github.com/elgorro/aiquila/discussions)

## License

Documentation is licensed under [CC BY 4.0](https://creativecommons.org/licenses/by/4.0/).
Code is licensed under AGPL-3.0 (see main repository).
