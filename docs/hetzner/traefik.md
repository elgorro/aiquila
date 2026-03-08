# aiquila-hetzner — Traefik

← [Overview](README.md)

---

## Overview

All three Hetzner stacks (`mcp`, `nextcloud`, `full`) use **Traefik v3** as the reverse
proxy. Traefik handles TLS termination via Let's Encrypt and redirects HTTP → HTTPS.
The static configuration (`traefik.yml`) is identical across all stacks; routing rules
are declared via Docker labels on each backend container.

---

## Entrypoints

| Name | Address | Purpose |
|------|---------|---------|
| `web` | `:80` | Accepts HTTP; immediately redirects to `websecure` (HTTPS) |
| `websecure` | `:443` | Accepts HTTPS; terminates TLS |

Both ports are published on the host (`ports: ["80:80", "443:443"]`).

---

## TLS / ACME

Certificates are issued by **Let's Encrypt** using the `httpChallenge` method (HTTP-01):

```yaml
certificatesResolvers:
  letsencrypt:
    acme:
      email: "${ACME_EMAIL}"
      storage: /certs/acme.json
      httpChallenge:
        entryPoint: web
```

- `ACME_EMAIL` comes from `--acme-email` (or the `acmeEmail` profile field) and is
  substituted into `traefik.yml` by `aiquila-hetzner` at provisioning time.
- Certificates are stored in the `traefik_certs` Docker volume at `/certs/acme.json`.
- HTTP-01 requires that port 80 is reachable from the internet during initial issuance
  and renewals. The Hetzner firewall rule allows TCP 80 for this reason.

---

## Docker provider & socket proxy

```yaml
providers:
  docker:
    exposedByDefault: false
    network: aiq-net
    endpoint: "tcp://socket-proxy:2375"
```

- `exposedByDefault: false` — containers are not routed by default; they must opt in
  with the label `traefik.enable=true`.
- Traefik does **not** mount the Docker socket directly. Instead it talks to
  `aiq-socket-proxy` (`tecnativa/docker-socket-proxy:0.3`), which only exposes the
  `CONTAINERS` and `NETWORKS` API endpoints. This limits the blast radius if Traefik
  is compromised.

---

## Routing & middlewares

### MCP stack

| Router | Rule | Backend | Middlewares |
|--------|------|---------|-------------|
| `mcp` | `Host(MCP_DOMAIN)` | `aiq-mcp:3339` | `crowdsec` |
| `grafana` *(monitoring profile)* | `Host(MCP_DOMAIN) && PathPrefix(/grafana)` | `aiq-grafana:3000` | — |

### Nextcloud stack

| Router | Rule | Backend | Middlewares |
|--------|------|---------|-------------|
| `nc` | `Host(NC_DOMAIN)` | `aiq-nc:80` | `crowdsec`, `nc-headers` |

### Full stack

| Router | Rule | Backend | Middlewares |
|--------|------|---------|-------------|
| `nc` | `Host(NC_DOMAIN)` | `aiq-nc:80` | `crowdsec`, `nc-headers` |
| `mcp` | `Host(MCP_DOMAIN)` | `aiq-mcp:3339` | `crowdsec` |

**`nc-headers` middleware** adds Nextcloud-recommended security headers:

```
Strict-Transport-Security: max-age=15552000; includeSubDomains
```

---

## CrowdSec plugin

The CrowdSec bouncer runs as a Traefik plugin loaded from the experimental plugin
registry:

```yaml
experimental:
  plugins:
    crowdsec-bouncer-traefik-plugin:
      moduleName: github.com/maxlerebourg/crowdsec-bouncer-traefik-plugin
      version: v1.3.5
```

The plugin is wired up as the `crowdsec` middleware via Docker labels on the backend
container:

```yaml
- "traefik.http.middlewares.crowdsec.plugin.crowdsec-bouncer-traefik-plugin.crowdseclapikey=${CROWDSEC_BOUNCER_KEY}"
- "traefik.http.middlewares.crowdsec.plugin.crowdsec-bouncer-traefik-plugin.crowdseclapischeme=http"
- "traefik.http.middlewares.crowdsec.plugin.crowdsec-bouncer-traefik-plugin.crowdseclapihost=crowdsec:8080"
```

On every incoming request the plugin queries `http://crowdsec:8080/api/v1/decisions`.
If the source IP is banned, the request receives a **403** before reaching the backend.

See [crowdsec.md](crowdsec.md) for details on how decisions are made and how to manage
bans.

---

## Logs

| Log | Path | Format |
|-----|------|--------|
| Daemon log | Docker JSON log (`json-file` driver) | JSON, level `INFO` |
| Access log | `/logs/access.log` | JSON, buffered (100 lines) |

The `/logs` path is a shared Docker volume also mounted read-only by `aiq-crowdsec`,
which reads the access log to detect threats.

---

## Dashboard

The Traefik dashboard is disabled (`api.dashboard: false`) and not exposed. There is
no management endpoint reachable from the network.

---

## Using nginx instead

> **Not officially supported.** The following is guidance for users who need to
> replace Traefik with nginx; no support is provided for this configuration.

To replace Traefik with nginx you would need to:

1. Remove the `traefik` and `socket-proxy` services from `docker-compose.yml`.
2. Add an `nginx` service with a hand-written `nginx.conf` that proxies to the
   backend containers by name (e.g. `proxy_pass http://aiq-mcp:3339`).
3. Obtain and renew TLS certificates with **certbot** (standalone or webroot mode)
   and reference them from `nginx.conf`.
4. Replace the Traefik plugin with the **CrowdSec nginx bouncer**
   (`crowdsecurity/cs-nginx-bouncer`). See the
   [CrowdSec nginx bouncer documentation](https://docs.crowdsec.net/docs/bouncers/nginx/)
   for installation and configuration steps.
5. Remove all `traefik.*` Docker labels — they have no effect with nginx.

The main complexity is that Traefik's label-based routing and automatic certificate
management must be replicated manually. The CrowdSec acquisition config
(`crowdsec/acquis.yml`) stays the same since the access log format does not change.
