# aiquila-hetzner — Command Reference

← [Overview](README.md)

## Global flags

| Flag | Default | Description |
|------|---------|-------------|
| `--profile` | — | Named credential profile to use |
| `--log-file` | `aiquila-hetzner.log.json` | NDJSON audit log path; `""` to disable |

---

## `create`

Provision a new AIquila server end-to-end.

**Stack selection:**

| Flag | Default | Description |
|------|---------|-------------|
| `--stack` | `mcp` | `mcp` / `nextcloud` / `full` |

**Required — `--stack mcp`:**

| Flag | Env var | Description |
|------|---------|-------------|
| `--mcp-domain` | — | FQDN for the MCP server |
| `--nc-url` | `NEXTCLOUD_URL` | External Nextcloud URL |
| `--nc-user` | `NEXTCLOUD_USER` | External Nextcloud username |
| `--nc-password` | `NEXTCLOUD_PASSWORD` | Nextcloud app password |

**Required — `--stack nextcloud`:**

| Flag | Description |
|------|-------------|
| `--nc-domain` | FQDN for the Nextcloud server |
| `--nc-admin-password` | Nextcloud admin password |

**Required — `--stack full`:**

| Flag | Description |
|------|-------------|
| `--mcp-domain` | FQDN for the MCP server |
| `--nc-domain` | FQDN for the Nextcloud server |
| `--nc-admin-password` | Nextcloud admin password |

**Nextcloud self-hosted options** (`--stack nextcloud` / `full`):

| Flag | Default | Description |
|------|---------|-------------|
| `--nc-admin-user` | `admin` | Nextcloud admin username |
| `--nc-app-version` | `latest` | AIquila app version to install (e.g. `v1.2.3`) |

**Server options:**

| Flag | Default | Description |
|------|---------|-------------|
| `--name` | `aiquila-<random>` | Server name |
| `--type` | `cpx21` | Server type (`cpx11` / `cpx21` / `cpx31` / `cx22` / `cx32` / `ccx13` / `ccx23`) |
| `--image` | `fedora-41` | OS image (`ubuntu` / `debian` / `fedora` / `centos` / `rocky` / `almalinux` / `opensuse-leap` / `arch`) |
| `--location` | `nbg1` | Datacenter (`nbg1` / `fsn1` / `hel1` / `ash` / `hil` / `sin`) |

Run `aiquila-hetzner options` to list all server types, locations, and images available in your account.

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
| `--monitoring` | Add Prometheus + Grafana (accessible at `/grafana`; mcp stack only) |
| `--acme-email` | Email for Let's Encrypt expiry notices |
| `--label` | Resource label `key=value` (repeatable; applied to server, firewall, key, volume) |
| `--package` | Extra package to install via cloud-init (repeatable) |
| `--config` | Path to YAML/JSON deployment config file |
| `--token` | Hetzner API token (default: `$HCLOUD_TOKEN`) |
| `--dry-run` | Print plan without making any API calls |
| `--noconfirm` | Skip interactive cost/recovery prompts (CI/CD; warnings still printed) |

---

## `destroy` / `delete`

Remove a server and all associated resources (firewall, SSH key, Cloud Volume).
Both commands are equivalent.

| Flag | Description |
|------|-------------|
| `--name` | Server name (required) |
| `--token` | Hetzner API token |
| `--dns-zone` | Delete `<name>.<zone>` A/AAAA records after destroy |
| `--dns-token` | Hetzner DNS token |

---

## `rebuild`

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

## `deploy`

Pull the latest Docker image and restart services on a running server.

| Flag | Default | Description |
|------|---------|-------------|
| `--name` | — | Server name (required) |
| `--ssh-key` | `~/.ssh/aiquila_ed25519` | SSH private key path |
| `--token` | `$HCLOUD_TOKEN` | Hetzner API token |

---

## `logs`

Stream Docker Compose logs from a server.

| Flag | Default | Description |
|------|---------|-------------|
| `--name` | — | Server name (required) |
| `--ssh-key` | `~/.ssh/aiquila_ed25519` | SSH private key path |
| `--service` | all | Service to tail |
| `--tail` | 100 | Lines to show before streaming |
| `--token` | `$HCLOUD_TOKEN` | Hetzner API token |

---

## `list`

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

## `start` / `stop` / `restart`

Power-manage a server.

| Flag | Description |
|------|-------------|
| `--name` | Server name (required) |
| `--hard` | Hard power-off / reset instead of graceful (not available for `start`) |
| `--token` | Hetzner API token |

`start` also accepts the alias `boot`.

---

## `options`

List all server types, locations, and system images available in your Hetzner
account. Use this to find valid values for `--type`, `--location`, and `--image`.

```bash
aiquila-hetzner options
```

Output is three tables: **Server types** (name, cores, RAM, disk, arch),
**Locations** (name, city, country), and **Images** (name, description).

| Flag | Description |
|------|-------------|
| `--token` | Hetzner API token (default: `$HCLOUD_TOKEN`) |

---

## `dns`

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

## `firewall`

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

## `network`

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

## `snapshot`

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
