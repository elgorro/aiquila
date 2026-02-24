# AIquila Hetzner — Roadmap

This file tracks planned features for the `aiquila-hetzner` CLI.
Implemented items live in [CHANGELOG](../CHANGELOG.md) (TBD).

---

## Planned

### DNS management (Hetzner DNS API)

Automate DNS record creation so deployments are truly one-command.

- `aiquila-hetzner dns create --name foo --zone example.com` — create A record
  pointing to the server's IPv4 (and AAAA for IPv6)
- `aiquila-hetzner dns delete --name foo --zone example.com` — remove record on destroy
- Auto-integration into `create`: pass `--dns-zone example.com` and the A record is
  created after the server IP is known, before Traefik starts requesting certs
- Hetzner DNS API: `dns.hetzner.com/api/v1`

### Advanced firewall management

Extend beyond the fixed 22/80/443 rules created by `create`.

- `aiquila-hetzner firewall rules --name foo` — list current rules
- `aiquila-hetzner firewall allow --name foo --port 5432 --proto tcp --cidr 10.0.0.0/8`
- `aiquila-hetzner firewall deny --name foo --port 5432`
- `--ssh-allow-cidr` flag on `create` — restrict port 22 to a specific IP range
  instead of 0.0.0.0/0 (reduces SSH brute-force noise in CrowdSec logs)
- Sync rules between Hetzner firewall and server-side nftables/iptables

### Private networks (Hetzner Networks)

Enable private, non-routable networking between servers.

- `aiquila-hetzner network create --name aiquila-net --cidr 10.0.0.0/16`
- `aiquila-hetzner network attach --server foo --network aiquila-net`
- `aiquila-hetzner network detach --server foo --network aiquila-net`
- `aiquila-hetzner network delete --name aiquila-net`
- `--network` flag on `create` — attach the new server to an existing private network
  and configure the docker-compose stack to use the private IP for internal traffic
  (avoids public internet for server-to-server communication)

### Object storage (Hetzner Object Storage / S3-compatible)

Hetzner Object Storage is S3-compatible. Useful for MCP file uploads, backups,
and Nextcloud external storage.

- `aiquila-hetzner storage create --name aiquila-bucket --location nbg1`
- `aiquila-hetzner storage list`
- `aiquila-hetzner storage delete --name aiquila-bucket`
- Generate access credentials and inject them into the server's `.env` as
  `S3_ENDPOINT`, `S3_ACCESS_KEY`, `S3_SECRET_KEY`, `S3_BUCKET`
- Auto-configure Nextcloud external storage or MCP file backend via `--s3` flag on `create`
- Automated volume backups: `aiquila-hetzner backup --name foo --bucket aiquila-bucket`
  (tar + encrypt + upload `/opt/aiquila` to S3 on a cron schedule)

---

## Nice-to-have (lower priority)

- `snapshot` — create a Hetzner server snapshot before deploys
- `rebuild` — re-run provisioning on an existing server without destroy/create cycle
- Config file (`~/.config/aiquila-hetzner/config.yml`) via Viper — store token,
  nc-url, nc-user so they don't need to be passed on every command
- `--output json` on `list` and `status` for scripting
- Global `--timeout` flag — currently all operations use `context.Background()`
  and can hang indefinitely if the Hetzner API is degraded
- Swap setup in cloud-init for small instances (cpx11 / 2 GB RAM)
- IPv6 firewall: expose 80/443 on IPv6 as well (currently TCP 22/80/443 use
  both 0.0.0.0/0 and ::/0 but Hetzner's dual-stack setup may need explicit rules)
