# aiquila-hetzner — Configuration

← [Overview](README.md)

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
aiquila-hetzner create --profile myprofile --mcp-domain mcp.example.com

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
override config file values. Ready-to-use templates live in `hetzner/examples/`.

```bash
aiquila-hetzner create --config hetzner/examples/deploy-mcp.yaml
aiquila-hetzner rebuild --config hetzner/examples/deploy-mcp.yaml
```

**Field reference:**

| Field | Type | CLI flag | Notes |
|-------|------|----------|-------|
| `name` | string | `--name` | Default: `aiquila-<random>` |
| `stack` | string | `--stack` | `mcp` (default) / `nextcloud` / `full` |
| `domain` | string | `--mcp-domain` | FQDN for the MCP server |
| `nc_domain` | string | `--nc-domain` | FQDN for Nextcloud (nextcloud/full stacks) |
| `nc_url` | string | `--nc-url` | External NC URL (mcp stack) |
| `nc_user` | string | `--nc-user` | External NC username (mcp stack) |
| `nc_password` | string | `--nc-password` | External NC app password (mcp stack) |
| `nc_admin_user` | string | `--nc-admin-user` | NC admin username (nextcloud/full; default: `admin`) |
| `nc_admin_password` | string | `--nc-admin-password` | NC admin password (nextcloud/full) |
| `nc_app_version` | string | `--nc-app-version` | AIquila app version (nextcloud/full; default: `latest`) |
| `acme_email` | string | `--acme-email` | Let's Encrypt expiry notices |
| `monitoring` | bool | `--monitoring` | Prometheus + Grafana (mcp stack only) |
| `ssh_key` | string | `--ssh-key` | Path to existing SSH public key |
| `token` | string | `--token` | Hetzner API token |
| `type` | string | `--type` | Server type (default: `cpx21`) |
| `image` | string | `--image` | OS image (default: `fedora-41`) |
| `location` | string | `--location` | Datacenter (default: `nbg1`) |
| `swap` | string | `--swap` | Swap file size, e.g. `2G` |
| `volume_size` | int | `--volume-size` | Cloud Volume size in GB |
| `luks` | bool | `--luks` | LUKS-encrypt the volume |
| `network` | string | `--network` | Attach to an existing private network |
| `labels` | []string | `--label` | Resource labels `key=value` (repeatable) |
| `dns_zone` | string | `--dns-zone` | Hetzner DNS zone for auto-record creation |
| `dns_token` | string | `--dns-token` | Hetzner DNS API token |
| `ssh_allow_cidr` | string | `--ssh-allow-cidr` | Restrict SSH to this CIDR |
| `packages` | []string | `--package` | Extra OS packages via cloud-init (repeatable) |

---

## Environment variables

### Resolution order

The CLI resolves each value in this order (first non-empty wins):

1. Explicit CLI flag (e.g. `--token`, `--nc-url`)
2. Environment variable (e.g. `$HCLOUD_TOKEN`)
3. Active profile (`~/.config/aiquila-hetzner/config.json`)

### Setting env vars for the current shell session

```bash
export HCLOUD_TOKEN=your_hcloud_token
export NEXTCLOUD_URL=https://nextcloud.example.com
export NEXTCLOUD_USER=admin
export NEXTCLOUD_PASSWORD=your-app-password
```

For persistence across shells, add these lines to `~/.bashrc` or `~/.zshrc`.

### Profile system (recommended for repeated use)

The `profile` subcommand stores credentials locally in
`~/.config/aiquila-hetzner/config.json` so you don't need to export env vars
or repeat flags on every invocation.

```bash
# Create a named profile
aiquila-hetzner profile add \
  --name my-setup \
  --token $HCLOUD_TOKEN \
  --nc-url https://nextcloud.example.com \
  --nc-user admin \
  --nc-password your-app-password \
  --acme-email you@example.com

# Set it as the active default
aiquila-hetzner profile use --name my-setup

# Inspect it (secrets masked)
aiquila-hetzner profile show

# List all profiles
aiquila-hetzner profile list

# Remove a profile
aiquila-hetzner profile delete --name my-setup
```

Once a profile is active, `create` and `rebuild` pick up the stored values without
any flags or env vars. Override per-run with `--profile <name>` or by passing flags
directly.

### Variable reference

| Variable | Used by | Notes |
|----------|---------|-------|
| `HCLOUD_TOKEN` | All commands | Hetzner Cloud API token |
| `HETZNER_DNS_TOKEN` | `dns`, `create --dns-zone`, `destroy --dns-zone` | Defaults to `HCLOUD_TOKEN` if unset |
| `NEXTCLOUD_URL` | `create`, `rebuild` (MCP/full stacks) | Falls back to active profile |
| `NEXTCLOUD_USER` | `create`, `rebuild` (MCP/full stacks) | Falls back to active profile |
| `NEXTCLOUD_PASSWORD` | `create`, `rebuild` (MCP/full stacks) | Falls back to active profile |
| `HETZNER_ROBOT_USER` | `create --storage-box` | Hetzner Robot API username |
| `HETZNER_ROBOT_PASSWORD` | `create --storage-box` | Hetzner Robot API password |
| `HETZNER_STORAGE_BOX_PASSWORD` | `create --storage-box` | CIFS mount password for storage box |
