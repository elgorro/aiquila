# AIquila — Hetzner Deployment

`aiquila-hetzner` is a single-binary CLI written in Go that provisions a
production-ready AIquila MCP server on Hetzner Cloud with one command.

---

## What it sets up

- Hetzner Cloud server (configurable type, image, and location)
- Ed25519 SSH key pair (auto-generated or supplied)
- Firewall (TCP 22/80/443 + UDP 443, optional CIDR restriction on SSH)
- Optional Hetzner Cloud Volume (plain ext4 or LUKS-encrypted)
- cloud-init: Docker + Docker Compose via distro-native package manager
- Optional swap file
- Optional extra OS packages installed via cloud-init
- Traefik reverse proxy with automatic TLS (Let's Encrypt)
- CrowdSec intrusion prevention
- `.env` generated from your credentials
- Optional Prometheus + Grafana monitoring stack
- Optional Hetzner DNS A/AAAA record creation
- Optional private network attachment

---

## Prerequisites

- Hetzner Cloud account + API token (`$HCLOUD_TOKEN`)
- (Optional) Hetzner DNS token (`$HETZNER_DNS_TOKEN`) for DNS automation
- Existing Nextcloud instance with an app-password
- Domain that points to (or will be pointed to) the server IP
- Go 1.22+ to build from source

---

## Installation

```bash
cd hetzner
go build -o aiquila-hetzner ./cmd/aiquila-hetzner/
sudo mv aiquila-hetzner /usr/local/bin/
```

---

## Quick Start

```bash
export HCLOUD_TOKEN=your_token

aiquila-hetzner create \
  --domain mcp.example.com \
  --nc-url https://nextcloud.example.com \
  --nc-user admin \
  --nc-password your-app-password
```

Use `--dry-run` to preview what would be created without making any API calls.

---

## Profiles

Profiles store credentials locally so you don't repeat them on every command.
They are saved to `~/.config/aiquila-hetzner/config.json` (mode 0600).

```bash
# Save a profile
aiquila-hetzner profile add --name myprofile \
  --token hcloud_... \
  --nc-url https://nc.example.com \
  --nc-user admin \
  --nc-password secret \
  --acme-email me@example.com

# Use it once
aiquila-hetzner create --profile myprofile --domain mcp.example.com

# Set as default
aiquila-hetzner profile use --name myprofile

# List / inspect
aiquila-hetzner profile list
aiquila-hetzner profile show --name myprofile

# Remove
aiquila-hetzner profile delete --name myprofile
```

**Credential resolution order** (first non-empty value wins):

1. CLI flag (e.g. `--token`)
2. Environment variable (e.g. `$HCLOUD_TOKEN`)
3. Profile named by `--profile`
4. Profile set as current with `profile use`
5. Config file (`--config`)

---

## Config file (DeployConfig)

Pass a YAML or JSON file with `--config` to avoid repeating flags. CLI flags
override config file values.

```yaml
# deploy.yaml
name: my-server
domain: mcp.example.com
nc_url: https://nextcloud.example.com/
nc_user: admin
nc_password: app_password_here
acme_email: admin@example.com
monitoring: true
ssh_key: ~/.ssh/id_ed25519.pub
token: hcloud_token_here
packages:
  - htop
  - git
```

```bash
aiquila-hetzner create --config deploy.yaml
aiquila-hetzner rebuild --config deploy.yaml
```

**Field reference:**

| Field | Type | CLI flag |
|-------|------|----------|
| `name` | string | `--name` |
| `domain` | string | `--domain` |
| `nc_url` | string | `--nc-url` |
| `nc_user` | string | `--nc-user` |
| `nc_password` | string | `--nc-password` |
| `acme_email` | string | `--acme-email` |
| `monitoring` | bool | `--monitoring` |
| `ssh_key` | string | `--ssh-key` |
| `token` | string | `--token` |
| `packages` | []string | `--package` (repeatable) |

---

## Commands reference

### Global flags

| Flag | Default | Description |
|------|---------|-------------|
| `--profile` | — | Named credential profile to use |
| `--log-file` | `aiquila-hetzner.log.json` | NDJSON audit log path; `""` to disable |

---

### `create`

Provision a new AIquila server end-to-end.

**Required:**

| Flag | Description |
|------|-------------|
| `--domain` | FQDN for HTTPS and `MCP_AUTH_ISSUER` |

**Nextcloud connection** (or env vars / profile):

| Flag | Env var | Description |
|------|---------|-------------|
| `--nc-url` | `NEXTCLOUD_URL` | Nextcloud URL |
| `--nc-user` | `NEXTCLOUD_USER` | Nextcloud username |
| `--nc-password` | `NEXTCLOUD_PASSWORD` | Nextcloud app password |

**Server options:**

| Flag | Default | Description |
|------|---------|-------------|
| `--name` | `aiquila-<random>` | Server name |
| `--type` | `cpx21` | Server type (`cpx11` / `cpx21` / `cpx31` / `cx22` / `cx32` / `ccx13` / `ccx23`) |
| `--image` | `fedora-41` | OS image (`ubuntu` / `debian` / `fedora` / `centos` / `rocky` / `almalinux` / `opensuse-leap` / `arch`) |
| `--location` | `nbg1` | Datacenter (`nbg1` / `fsn1` / `hel1` / `ash` / `hil` / `sin`) |

**SSH key:**

| Flag | Description |
|------|-------------|
| `--ssh-key` | Path to existing SSH public key; omit to auto-generate an Ed25519 pair |

**Networking & DNS:**

| Flag | Description |
|------|-------------|
| `--dns-zone` | Hetzner DNS zone (e.g. `example.com`); creates `<name>.<zone>` A record |
| `--dns-token` | Hetzner DNS token (default: `$HETZNER_DNS_TOKEN` or `$HCLOUD_TOKEN`) |
| `--ssh-allow-cidr` | Restrict SSH to this CIDR (e.g. `203.0.113.0/24`) instead of `0.0.0.0/0` |
| `--network` | Attach server to an existing private network |

**Storage:**

| Flag | Default | Description |
|------|---------|-------------|
| `--volume-size` | 0 (disabled) | Cloud Volume size in GB, mounted at `/opt/aiquila` |
| `--luks` | false | LUKS-encrypt the volume (requires `--volume-size`; experimental) |
| `--swap` | — | Create swap file (e.g. `1G`, `2G`) |

**Services & extras:**

| Flag | Description |
|------|-------------|
| `--monitoring` | Add Prometheus + Grafana (accessible at `/grafana`) |
| `--acme-email` | Email for Let's Encrypt expiry notices |
| `--label` | Resource label `key=value` (repeatable; applied to server, firewall, key, volume) |
| `--package` | Extra package to install via cloud-init (repeatable) |
| `--config` | Path to YAML/JSON deployment config file |
| `--token` | Hetzner API token (default: `$HCLOUD_TOKEN`) |
| `--dry-run` | Print plan without making any API calls |

---

### `destroy` / `delete`

Remove a server and all associated resources (firewall, SSH key, Cloud Volume).
Both commands are equivalent.

| Flag | Description |
|------|-------------|
| `--name` | Server name (required) |
| `--token` | Hetzner API token |
| `--dns-zone` | Delete `<name>.<zone>` A/AAAA records after destroy |
| `--dns-token` | Hetzner DNS token |

---

### `rebuild`

Re-upload config files and restart the Docker stack on an existing server
without tearing down infrastructure.

| Flag | Description |
|------|-------------|
| `--name` | Server name (required unless provided by `--config`) |
| `--ssh-key` | Path to SSH private key for server access (required) |
| `--config` | Path to YAML/JSON config file |
| `--domain` | Override domain from config |
| `--nc-url` | Override Nextcloud URL |
| `--nc-user` | Override Nextcloud username |
| `--nc-password` | Override Nextcloud password |
| `--acme-email` | Override ACME email |
| `--monitoring` | Enable monitoring profile |
| `--token` | Hetzner API token |

---

### `deploy`

Pull the latest Docker image and restart services on a running server.

| Flag | Default | Description |
|------|---------|-------------|
| `--name` | — | Server name (required) |
| `--ssh-key` | `~/.ssh/aiquila_ed25519` | SSH private key path |
| `--token` | `$HCLOUD_TOKEN` | Hetzner API token |

---

### `logs`

Stream Docker Compose logs from a server.

| Flag | Default | Description |
|------|---------|-------------|
| `--name` | — | Server name (required) |
| `--ssh-key` | `~/.ssh/aiquila_ed25519` | SSH private key path |
| `--service` | all | Service to tail |
| `--tail` | 100 | Lines to show before streaming |
| `--token` | `$HCLOUD_TOKEN` | Hetzner API token |

---

### `list`

Show all servers in the project.

```
NAME           TYPE    LOCATION  STATUS   IPv4
aiquila-abc    cpx21   nbg1      running  1.2.3.4
```

| Flag | Description |
|------|-------------|
| `--label` | Filter by label selector (e.g. `env=prod`) |
| `--token` | Hetzner API token |

---

### `start` / `stop` / `restart`

Power-manage a server.

| Flag | Description |
|------|-------------|
| `--name` | Server name (required) |
| `--hard` | Hard power-off / reset instead of graceful (not available for `start`) |
| `--token` | Hetzner API token |

`start` also accepts the alias `boot`.

---

### `dns`

Manage Hetzner DNS records (requires `$HETZNER_DNS_TOKEN`).

```bash
# Create A (and optionally AAAA) records
aiquila-hetzner dns create \
  --name mcp --zone example.com \
  --ip 1.2.3.4 [--ipv6 2001:db8::1]

# Delete records
aiquila-hetzner dns delete --name mcp --zone example.com
```

| Flag | Description |
|------|-------------|
| `--name` | Subdomain / record name (required) |
| `--zone` | DNS zone / apex domain (required) |
| `--ip` | IPv4 address for the A record (required for `create`) |
| `--ipv6` | IPv6 address for the AAAA record |
| `--dns-token` | Hetzner DNS token (default: `$HETZNER_DNS_TOKEN` or `$HCLOUD_TOKEN`) |

---

### `firewall`

Inspect and modify firewall rules. The firewall is looked up as `<server-name>-fw`.

```bash
# List inbound rules
aiquila-hetzner firewall rules --name myserver

# Allow a port (optionally restricted to a CIDR)
aiquila-hetzner firewall allow --name myserver \
  --port 5432 --proto tcp --cidr 10.0.0.0/8 \
  --description "PostgreSQL from VPC"

# Remove an allow rule
aiquila-hetzner firewall deny --name myserver --port 5432
```

| Flag | Default | Description |
|------|---------|-------------|
| `--name` | — | Server name (required) |
| `--port` | — | Port or range, e.g. `5432` or `5432-5440` |
| `--proto` | `tcp` | Protocol: `tcp` or `udp` |
| `--cidr` | `0.0.0.0/0` + `::/0` | Source CIDR (repeatable; `allow` only) |
| `--description` | — | Rule description (`allow` only) |
| `--token` | `$HCLOUD_TOKEN` | Hetzner API token |

---

### `network`

Manage Hetzner private networks.

```bash
aiquila-hetzner network create  --name mynet --cidr 10.0.0.0/16
aiquila-hetzner network attach  --server myserver --network mynet
aiquila-hetzner network detach  --server myserver --network mynet
aiquila-hetzner network delete  --name mynet
```

`network create` is idempotent — it returns successfully if the network already exists.

| Flag | Default | Description |
|------|---------|-------------|
| `--name` | — | Network name |
| `--cidr` | `10.0.0.0/16` | Network CIDR (create only) |
| `--zone` | `eu-central` | Network zone: `eu-central` / `us-east` / `us-west` / `ap-southeast` |
| `--server` | — | Server name (attach / detach) |
| `--network` | — | Network name (attach / detach) |
| `--label` | — | Label `key=value` (repeatable; create only) |
| `--token` | `$HCLOUD_TOKEN` | Hetzner API token |

---

### `snapshot`

Manage server snapshots.

```bash
aiquila-hetzner snapshot create --name myserver --description "Before upgrade"
aiquila-hetzner snapshot list   [--name myserver]
aiquila-hetzner snapshot delete --id 12345678
```

| Flag | Description |
|------|-------------|
| `--name` | Server name (create: required; list: filter) |
| `--description` | Snapshot description (default: auto-generated with timestamp) |
| `--label` | Label `key=value` (repeatable; create only) |
| `--id` | Snapshot ID (required for delete) |
| `--token` | Hetzner API token |

---

## Advanced topics

### Cloud Volume + LUKS

```bash
aiquila-hetzner create --domain mcp.example.com \
  --volume-size 20   # plain ext4, mounted at /opt/aiquila

aiquila-hetzner create --domain mcp.example.com \
  --volume-size 20 --luks   # LUKS-encrypted (experimental)
```

With `--luks`, a random key is generated and stored at `/root/.luks/aiquila.key`
on the unencrypted root disk. This protects against Hetzner volume
snapshots/transfers but not against root disk access or server compromise.
The volume is auto-unlocked on reboot via `/etc/crypttab`.

---

### Monitoring

```bash
aiquila-hetzner create --domain mcp.example.com --monitoring
```

Adds a Prometheus + Grafana stack. Grafana is available at
`https://<domain>/grafana`. The monitoring profile is started alongside the
main stack and can be re-enabled via `rebuild --monitoring`.

---

### Extra packages

Install additional OS packages during cloud-init before Docker starts:

```bash
aiquila-hetzner create --domain mcp.example.com \
  --package htop --package bash-completion
```

Or use the `packages:` list in a config file. Packages are installed with the
distro-native package manager (`dnf`, `apt`, `pacman`, etc.).

---

### Swap

Useful for small instance types (`cpx11`, `cx22`) that have limited RAM:

```bash
aiquila-hetzner create --domain mcp.example.com --swap 2G
```

Creates `/swapfile` at the specified size and enables it on boot.

---

### Private networks

```bash
# Create the network first
aiquila-hetzner network create --name mynet --cidr 10.0.0.0/16

# Attach during provisioning
aiquila-hetzner create --domain mcp.example.com --network mynet

# Or attach to an existing server
aiquila-hetzner network attach --server myserver --network mynet
```

---

### SSH CIDR restriction

Restrict SSH access to a known IP range:

```bash
aiquila-hetzner create --domain mcp.example.com \
  --ssh-allow-cidr 203.0.113.0/24
```

The firewall rule for port 22 is updated to allow only the specified CIDR
instead of the default `0.0.0.0/0`.

---

### DNS automation

Create A (and AAAA) records automatically after the server IP is assigned:

```bash
aiquila-hetzner create --domain mcp.example.com \
  --dns-zone example.com
```

Requires `$HETZNER_DNS_TOKEN` or `--dns-token`. The record name is derived from
the first label of `--domain` (e.g. `mcp` from `mcp.example.com`).

---

## Environment variables

| Variable | Used by |
|----------|---------|
| `HCLOUD_TOKEN` | All commands |
| `HETZNER_DNS_TOKEN` | `dns`, `create --dns-zone`, `destroy --dns-zone` |
| `NEXTCLOUD_URL` | `create`, `rebuild` |
| `NEXTCLOUD_USER` | `create`, `rebuild` |
| `NEXTCLOUD_PASSWORD` | `create`, `rebuild` |

---

## Integration testing

An automated integration test suite runs against a real Hetzner server using the
Claude Agent SDK. See [docs/hetzner/integration-test.md](integration-test.md).

---

## Audit log

Every command run is appended as a JSON object to the log file:

```json
{"time":"2026-02-25T12:00:00Z","level":"info","cmd":"create","step":"start","name":"aiquila-abc","type":"cpx21","location":"nbg1","domain":"mcp.example.com"}
{"time":"2026-02-25T12:01:30Z","level":"info","cmd":"create","step":"done","ok":true,"elapsed_s":90.2}
```

The default log path is `aiquila-hetzner.log.json` in the working directory.
Pass `--log-file ""` to disable logging entirely.

```bash
aiquila-hetzner create ... --log-file /var/log/aiquila-hetzner.json
aiquila-hetzner create ... --log-file ""   # disable
```
