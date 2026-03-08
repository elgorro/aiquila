# aiquila-hetzner — Advanced Topics

← [Overview](README.md)

## Cloud Volume + LUKS

```bash
aiquila-hetzner create --mcp-domain mcp.example.com \
  --nc-url https://nc.example.com --nc-user admin --nc-password pass \
  --volume-size 20   # plain ext4, mounted at /opt/aiquila

aiquila-hetzner create --mcp-domain mcp.example.com \
  --nc-url https://nc.example.com --nc-user admin --nc-password pass \
  --volume-size 20 --luks   # LUKS-encrypted (experimental)
```

With `--luks`, a random key is generated and stored at `/root/.luks/aiquila.key`
on the unencrypted root disk. This protects against Hetzner volume
snapshots/transfers but not against root disk access or server compromise.
The volume is auto-unlocked on reboot via `/etc/crypttab`.

---

## Monitoring

```bash
aiquila-hetzner create --mcp-domain mcp.example.com \
  --nc-url https://nc.example.com --nc-user admin --nc-password pass \
  --monitoring
```

Adds a Prometheus + Grafana stack. Grafana is available at
`https://<domain>/grafana`. The monitoring profile is started alongside the
main stack and can be re-enabled via `rebuild --monitoring`.

---

## Extra packages

Install additional OS packages during cloud-init before Docker starts:

```bash
aiquila-hetzner create --mcp-domain mcp.example.com \
  --nc-url https://nc.example.com --nc-user admin --nc-password pass \
  --package htop --package bash-completion
```

Or use the `packages:` list in a config file. Packages are installed with the
distro-native package manager (`dnf`, `apt`, `pacman`, etc.).

---

## Swap

Useful for small instance types (`cpx11`, `cx22`) that have limited RAM:

```bash
aiquila-hetzner create --mcp-domain mcp.example.com \
  --nc-url https://nc.example.com --nc-user admin --nc-password pass \
  --swap 2G
```

Creates `/swapfile` at the specified size and enables it on boot.

---

## Private networks

```bash
# Create the network first
aiquila-hetzner network create --name mynet --cidr 10.0.0.0/16

# Attach during provisioning
aiquila-hetzner create --mcp-domain mcp.example.com \
  --nc-url https://nc.example.com --nc-user admin --nc-password pass \
  --network mynet

# Or attach to an existing server
aiquila-hetzner network attach --server myserver --network mynet
```

---

## SSH CIDR restriction

Restrict SSH access to a known IP range:

```bash
aiquila-hetzner create --mcp-domain mcp.example.com \
  --nc-url https://nc.example.com --nc-user admin --nc-password pass \
  --ssh-allow-cidr 203.0.113.0/24
```

The firewall rule for port 22 is updated to allow only the specified CIDR
instead of the default `0.0.0.0/0`.

---

## DNS automation

Create A (and AAAA) records automatically after the server IP is assigned:

```bash
aiquila-hetzner create --mcp-domain mcp.example.com \
  --nc-url https://nc.example.com --nc-user admin --nc-password pass \
  --dns-zone example.com
```

Requires `$HETZNER_DNS_TOKEN` or `--dns-token`. The record name is derived from
the first label of `--mcp-domain` (e.g. `mcp` from `mcp.example.com`).

---

## Partial-deployment recovery

If provisioning fails after the server is created (e.g. SSH timeout), the CLI
prints a partial summary (name, IP, SSH command, and hourly cost) and asks
whether to keep or delete the server.

With `--noconfirm` the server is deleted automatically.

```
╔════════════════════════════════════════════╗
║  ⚠  Partial deployment — server is live   ║
╠════════════════════════════════════════════╣
  Name:    aiquila-abc123
  Server:  1.2.3.4
  SSH:     ssh -i ~/.ssh/aiquila_ed25519 root@1.2.3.4
  Cost:    €0.0149/hr  €9.99/mo (gross incl. VAT)  ← still accruing
╚════════════════════════════════════════════╝

  Keep this server? [y/N]:
```

To delete a kept server later: `aiquila-hetzner delete --name <name>`.

---

## Updating the .env configuration

The generated `/opt/aiquila/.env` file contains all runtime configuration for the stack.
This section explains what each variable does, how to change individual values safely,
and what to expect from `rebuild`.

### What's generated

**MCP stack** (`/opt/aiquila/.env`):

| Variable | Source | Description |
|----------|--------|-------------|
| `NEXTCLOUD_URL` | `--nc-url` | Nextcloud instance URL |
| `NEXTCLOUD_USER` | `--nc-user` | Nextcloud username |
| `NEXTCLOUD_PASSWORD` | `--nc-password` | Nextcloud app password |
| `MCP_TRANSPORT` | static `http` | Transport mode (always HTTP in Docker) |
| `MCP_PORT` | static `3339` | Internal MCP port |
| `MCP_AUTH_ENABLED` | static `true` | OAuth 2.0 enabled |
| `MCP_AUTH_SECRET` | auto 64-char hex | JWT signing key |
| `MCP_AUTH_ISSUER` | `https://<domain>` | OAuth issuer URL |
| `MCP_CLIENT_ID` | static `aiquila-claude` | OAuth client ID |
| `MCP_CLIENT_SECRET` | auto 64-char hex | OAuth client secret |
| `MCP_CLIENT_REDIRECT_URIS` | static | Claude.ai callback URL |
| `MCP_REGISTRATION_ENABLED` | static `true` | Allow dynamic client registration |
| `MCP_REGISTRATION_TOKEN` | auto 32-char hex | Token required for dynamic registration |
| `MCP_TRUST_PROXY` | static `true` | Trust Traefik reverse-proxy headers |
| `MCP_DOMAIN` | `--mcp-domain` | Traefik routing domain |
| `ACME_EMAIL` | `--acme-email` | Let's Encrypt notifications |
| `CROWDSEC_BOUNCER_KEY` | auto 64-char hex | CrowdSec bouncer API key |
| `GRAFANA_PASSWORD` | auto 32-char hex | Grafana admin password |
| `LOG_LEVEL` | static `info` | Pino log level (`trace`/`debug`/`info`/`warn`/`error`) |

**Nextcloud stack** (`/opt/aiquila/.env`):

| Variable | Source | Description |
|----------|--------|-------------|
| `NC_DOMAIN` | `--nc-domain` | Nextcloud domain |
| `ACME_EMAIL` | `--acme-email` | Let's Encrypt notifications |
| `NC_ADMIN_USER` | `--nc-admin-user` | Nextcloud admin username |
| `NC_ADMIN_PASSWORD` | `--nc-admin-password` | Nextcloud admin password |
| `POSTGRES_PASSWORD` | auto 64-char hex | PostgreSQL password |
| `CROWDSEC_BOUNCER_KEY` | auto 64-char hex | CrowdSec bouncer API key |
| `NC_METRICS_TOKEN` | auto 32-char hex | NC33 metrics endpoint token |

**Full stack** (`/opt/aiquila/.env`): all variables from both tables above, plus:

| Variable | Source | Description |
|----------|--------|-------------|
| `NC_MCP_USER` | generated via OCC | Nextcloud username the MCP container uses |
| `NC_MCP_PASSWORD` | generated via OCC | Nextcloud app password for the MCP container |

### How to update a variable in-place

Most variables only require a container restart to take effect:

```bash
# SSH into the server
ssh -i ~/.ssh/aiquila_ed25519 root@<server-ip>

# Edit the .env file
nano /opt/aiquila/.env

# Restart the affected service(s) to pick up the change
cd /opt/aiquila && docker compose up -d
```

This workflow is appropriate for: `LOG_LEVEL`, Nextcloud credentials (`NEXTCLOUD_URL` /
`NEXTCLOUD_USER` / `NEXTCLOUD_PASSWORD`), and OAuth settings such as
`MCP_AUTH_SECRET` or `MCP_CLIENT_SECRET`.

### What `rebuild` does to the .env

`rebuild` calls `config.Generate*()` internally, which **generates new random values**
for every auto-generated field. Running `rebuild` will:

- Invalidate all existing OAuth tokens (new `MCP_AUTH_SECRET`)
- Change the OAuth client secret (`MCP_CLIENT_SECRET`)
- Rotate the dynamic registration token (`MCP_REGISTRATION_TOKEN`)
- Rotate the CrowdSec bouncer key (`CROWDSEC_BOUNCER_KEY`)
- Regenerate the Grafana admin password (`GRAFANA_PASSWORD`)
- Regenerate the PostgreSQL password (`POSTGRES_PASSWORD`) on Nextcloud/full stacks

Use `rebuild` when you want a clean slate or when you need to rotate all secrets at
once. **Do not use `rebuild` just to change `LOG_LEVEL`** — edit `.env` directly
and restart instead.

### Variables requiring extra care

Some variables cannot be changed by simply editing `.env` and restarting:

- **`POSTGRES_PASSWORD`** — changing this breaks the running database container because
  the value inside PostgreSQL does not update automatically. After editing `.env` you
  must also update the password inside the database:
  ```bash
  docker compose exec aiq-nc-db psql -U nextcloud -c "ALTER USER nextcloud PASSWORD 'newpass';"
  ```

- **`CROWDSEC_BOUNCER_KEY`** — must match the key registered in the CrowdSec local API.
  After changing the value in `.env`, re-register the bouncer:
  ```bash
  docker compose exec aiq-crowdsec cscli bouncers add traefik-bouncer --key <new-key>
  ```

- **`NC_MCP_PASSWORD`** (full stack only) — this is a Nextcloud app password. Changing
  it in `.env` alone will not work because the old password still exists in Nextcloud.
  Generate a new app password via OCC and update `.env` to match:
  ```bash
  docker compose exec aiq-nc php occ user:generate-app-password <nc-mcp-user>
  # Copy the output, then update NC_MCP_PASSWORD in .env and restart
  ```
