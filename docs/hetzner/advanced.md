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

SSH (port 22) is never opened to the internet by default. When you do not pass
`--ssh-allow-cidr`, `create` detects the public IP of the machine you run it
from and restricts port 22 to that single address:

```
No --ssh-allow-cidr specified, restricting SSH to your current IP: 203.0.113.7/32
```

**Always set `--ssh-allow-cidr` explicitly** for anything long-lived — a
detected address is whatever your ISP or VPN handed you today, and it will
change. Pass the range you actually administer the server from:

```bash
aiquila-hetzner create --mcp-domain mcp.example.com \
  --nc-url https://nc.example.com --nc-user admin --nc-password pass \
  --ssh-allow-cidr 203.0.113.0/24
```

An invalid CIDR is rejected before any Hetzner resource is created. Ports
80/443 are always open to the world; only port 22 is restricted.

If you genuinely need SSH reachable from anywhere — CI provisioning, a dynamic
office IP — opt in explicitly:

```bash
aiquila-hetzner create ... --ssh-allow-any
```

That prints a warning and falls back to `0.0.0.0/0`. The same fallback applies
(also with a warning) when public-IP detection fails, so an offline or
firewalled workstation never blocks a deploy.

### Changing the CIDR later

`create` is idempotent and reuses a firewall that already carries the target
name. It will **not** rewrite the SSH rule of an existing firewall; if the rule
does not match what you asked for, you get:

```
WARNING: existing firewall "myserver-fw" allows SSH from 0.0.0.0/0, not 203.0.113.0/24 — not modified;
edit it in the Hetzner console or delete it to have it recreated
```

Adjust the rule in the Hetzner console (or delete the firewall and re-run
`create`) to change it.

---

## SSH key rotation

There is no `rotate` subcommand — key material has no automated lifecycle. Rotate
on a fixed schedule (annually is a reasonable default) and immediately whenever a
laptop holding a private key is lost, leaves the team, or is suspected compromised.

**Know this first:** `create` derives the Hetzner key name from the server name
(`<server>-key`) and, if a key with that name already exists, **reuses it as-is
without comparing fingerprints**. Generating a new local key pair and re-running
`create` therefore does *not* rotate anything — the old public key stays on the
server and keeps working. The old key must be removed explicitly.

### Rotating

```bash
# 1. Generate a new pair (do not overwrite the old one yet — you still need it)
ssh-keygen -t ed25519 -f ~/.ssh/aiquila_ed25519.new -C "aiquila $(date +%Y-%m)"

# 2. Append the new public key on the server, using the old key to get in
ssh -i ~/.ssh/aiquila_ed25519 root@<server-ip> \
  "cat >> /root/.ssh/authorized_keys" < ~/.ssh/aiquila_ed25519.new.pub

# 3. Verify the new key works — in a second terminal, before closing the first
ssh -i ~/.ssh/aiquila_ed25519.new root@<server-ip> true && echo "new key OK"

# 4. Only once step 3 succeeds, drop the old key from the server
ssh -i ~/.ssh/aiquila_ed25519.new root@<server-ip> \
  "grep -v -F -f /dev/stdin /root/.ssh/authorized_keys > /root/.ssh/ak.new && \
   mv /root/.ssh/ak.new /root/.ssh/authorized_keys" < ~/.ssh/aiquila_ed25519.pub

# 5. Promote the new key locally
mv ~/.ssh/aiquila_ed25519.new ~/.ssh/aiquila_ed25519
mv ~/.ssh/aiquila_ed25519.new.pub ~/.ssh/aiquila_ed25519.pub
```

Keep the old key until step 3 passes. Removing it first and finding the new key
does not work leaves you locked out, recoverable only through the Hetzner
console's rescue mode.

### Replacing the stored Hetzner key

The key object in the Hetzner project is only used when a server is *created*;
deleting it does not affect a running server. Replace it so future `create` runs
pick up the new material:

```bash
hcloud ssh-key delete <server>-key
hcloud ssh-key create --name <server>-key --public-key-from-file ~/.ssh/aiquila_ed25519.pub
```

`aiquila-hetzner destroy` also deletes the `<server>-key` object along with the
server.

### Revoking access

To cut off a key immediately without a full rotation, remove its line from
`/root/.ssh/authorized_keys` on each server and delete the Hetzner key object.
Existing SSH sessions survive removal — terminate them too:

```bash
ssh root@<server-ip> "pkill -f 'sshd:.*@pts' || true"
```

Since SSH is restricted to a single CIDR by default (see
[SSH CIDR restriction](#ssh-cidr-restriction)), tightening that range is a fast
containment step while you rotate.

---

## DNS automation

Create A (and AAAA) records automatically after the server IP is assigned:

```bash
aiquila-hetzner create --mcp-domain mcp.example.com \
  --nc-url https://nc.example.com --nc-user admin --nc-password pass \
  --dns-zone example.com
```

Uses the same Cloud API token (`$HCLOUD_TOKEN`). The record name is derived from
the server `--name` flag (e.g. `mcp` → `mcp.example.com`).

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
| `MCP_CLIENT_ID` | empty | OAuth client ID (set for static pre-seeded client) |
| `MCP_CLIENT_SECRET` | auto 64-char hex | OAuth client secret |
| `MCP_CLIENT_REDIRECT_URIS` | empty | Redirect URIs for pre-seeded client |
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

## Upgrading PostgreSQL to 18

The Nextcloud and full stacks ship **`postgres:18`**. A PostgreSQL *major* upgrade does
not happen automatically — the on-disk data format changes between majors, so starting the
new image against a data volume created by an older major (e.g. `postgres:16`) will fail.

**Fresh deployments need no action.** To migrate an existing `postgres:16` volume, dump
before bumping the image and restore afterwards:

```bash
# 1. While still on the old image, dump the database:
docker compose exec -T nc-db pg_dump -U nextcloud -Fc nextcloud > nextcloud.dump

# 2. Stop the stack, remove the old data volume, then start only the DB on the new image:
docker compose down
docker volume rm aiq-nc_db_data        # Hetzner nextcloud/full stack volume name
docker compose up -d nc-db

# 3. Restore into the fresh PostgreSQL 18 cluster:
docker compose exec -T nc-db pg_restore -U nextcloud -d nextcloud --clean < nextcloud.dump

# 4. Bring the rest of the stack back up:
docker compose up -d
```

For the local dev stack (`docker/installation/`) the service is `db` and the volume is
`aiquila_postgres_data`; adjust the names accordingly.

### Where the data volume is mounted

The database volume is mounted at **`/var/lib/postgresql`**, not at
`/var/lib/postgresql/data`:

```yaml
volumes:
  - nc_db_data:/var/lib/postgresql
```

`postgres:18` and newer keep their data in a major-version subdirectory
(`/var/lib/postgresql/18/docker`) so that in-place `pg_upgrade` works. The image refuses to
start when it finds a mount at the old `/var/lib/postgresql/data` path, reporting
`there appears to be PostgreSQL data in: /var/lib/postgresql/data (unused mount/volume)`
and entering a restart loop — which also blocks Nextcloud, the MCP server and Caddy, since
they all wait for the database to become healthy.

If you are running a stack that predates this change, its volume still holds a
pre-18 layout and **cannot be reused in place**. Follow the dump/restore steps above; step
2 removes the old volume, which is exactly what is needed here.
