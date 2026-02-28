# Acknowledgements

AIquila is built on these excellent open-source projects and services.

## Core platforms
| Project | Version | Role |
|---------|---------|------|
| [Nextcloud](https://nextcloud.com) | 31–33 | Self-hosted cloud platform — files, tasks, notes, and recipes |
| [Claude / Anthropic](https://anthropic.com) | — | AI model powering all chat, summarise, and text-tool features |
| [Model Context Protocol](https://modelcontextprotocol.io) | — | Open standard connecting Claude to external tools and data |

## Languages & runtimes
| Project | Version | Role |
|---------|---------|------|
| [TypeScript](https://www.typescriptlang.org) | ^5.8 | MCP server — type-safe Node.js with strict mode |
| [Node.js](https://nodejs.org) | ^20 | MCP server runtime |
| [Go](https://go.dev) | 1.23 | Hetzner CLI (`aiquila-hetzner`) |
| [PHP](https://www.php.net) | ^8.4 | Nextcloud app backend |
| [Vue](https://vuejs.org) | 2.7 | Nextcloud app frontend (Settings, Chat, File actions) |
| [JavaScript](https://developer.mozilla.org/en-US/docs/Web/JavaScript) | ES2022 | Nextcloud app frontend scripting |

## Key libraries
| Library | Version | Role |
|---------|---------|------|
| [@modelcontextprotocol/sdk](https://github.com/modelcontextprotocol/typescript-sdk) | ^1.27 | MCP protocol implementation — tools, resources, and OAuth |
| [pino](https://getpino.io) | ^9.0 | Structured JSON logging to stderr in the MCP server |
| [webdav](https://github.com/perry-mitchell/webdav-client) | ^5.8 | WebDAV client — Nextcloud file operations in the MCP server |
| [zod](https://zod.dev) | ^3.25 | Runtime schema validation for MCP tool inputs |
| [anthropic-ai/sdk](https://packagist.org/packages/anthropic-ai/sdk) | ^0.5 | Claude API PHP client used by the Nextcloud app |
| [symfony/http-client](https://symfony.com/doc/current/http_client.html) | ^8.0 | HTTP client for the Nextcloud app backend |
| [hcloud-go](https://github.com/hetznercloud/hcloud-go) | v2.10 | Hetzner Cloud Go SDK — server provisioning in the CLI |
| [cobra](https://github.com/spf13/cobra) | v1.9 | CLI framework for `aiquila-hetzner` |
| [@nextcloud/vue](https://github.com/nextcloud-libraries/nextcloud-vue) | ^8.21 | Nextcloud design-system Vue components |

## Build & package
| Project | Version | Role |
|---------|---------|------|
| [npm](https://www.npmjs.com) | ^10 | Node.js package manager |
| [Composer](https://getcomposer.org) | 2.x | PHP dependency manager |
| [Vite](https://vitejs.dev) | ^7.1 | Frontend (Nextcloud app) build tool |
| [tsx](https://github.com/privatenumber/tsx) | ^4.20 | TypeScript execution for MCP server dev mode |
| [vitest](https://vitest.dev) | ^4.0 | MCP server unit testing |
| [ESLint](https://eslint.org) | ^9.39 | TypeScript linting |
| [Prettier](https://prettier.io) | ^3.8 | Code formatting |
| [Bash](https://www.gnu.org/software/bash/) | — | Provisioning and CI scripting |
| [Make](https://www.gnu.org/software/make/) | — | Build orchestration (Docker stacks, release) |
| [tar](https://www.gnu.org/software/tar/) | — | Nextcloud app package archiving |

## Infrastructure & services
| Project | Role |
|---------|------|
| [Docker](https://www.docker.com) | Container runtime for all deployment stacks |
| [Caddy](https://caddyserver.com) | Reverse proxy with automatic HTTPS (standalone stack) |
| [Traefik](https://traefik.io) | Reverse proxy and TLS termination (Hetzner stacks) |
| [CrowdSec](https://www.crowdsec.net) | Collaborative intrusion prevention (Hetzner stacks) |
| [PostgreSQL](https://www.postgresql.org) | Relational database for Nextcloud |
| [Redis](https://redis.io) | Cache and session store for Nextcloud |
| [Hetzner Cloud](https://www.hetzner.com) | Cloud VPS infrastructure for production deployments |

## Dev tooling
| Project | Role |
|---------|------|
| [Git](https://git-scm.com) | Version control |
| [GitHub Actions](https://github.com/features/actions) | CI/CD — lint, test, release, and integration test workflows |
| [MailHog](https://github.com/mailhog/MailHog) | Local email testing in the dev Docker stack |
| [Adminer](https://www.adminer.org) | Database UI in the dev Docker stack |
| [Markdown](https://commonmark.org) | Documentation format |
| [SVG](https://www.w3.org/Graphics/SVG/) | App icon format |
