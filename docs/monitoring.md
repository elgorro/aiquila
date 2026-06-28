# Monitoring — OpenMetrics / Prometheus

The AIquila Nextcloud app exports usage and task-processing metrics in the
[OpenMetrics](https://openmetrics.io/) format, so admins can monitor AI adoption,
token spend and system health with Prometheus and Grafana.

The metrics are served through Nextcloud's built-in `/metrics` endpoint
(**Nextcloud 33+**). AIquila registers its exporters via `appinfo/info.xml`; no extra
configuration is required once the app is installed.

## Exported metrics

All series are emitted by Nextcloud with a `nextcloud_` prefix. Values are aggregated
**globally across all users** — there are no per-user labels (this keeps cardinality
bounded and avoids exposing individual usage to anyone scraping the endpoint).

| Series | Type | Labels | Description |
|--------|------|--------|-------------|
| `nextcloud_aiquila_tokens_used_total` | counter | `model`, `request_type`, `direction` (`input`/`output`) | Total tokens consumed |
| `nextcloud_aiquila_tasks_total` | counter | `status` | Coworker task runs processed |
| `nextcloud_aiquila_conversations_total` | counter | — | Conversations created |
| `nextcloud_aiquila_mcp_server_status` | gauge | `server_id`, `server` | Per MCP server: `1` = enabled and last connection healthy, `0` = disabled or failing |

> The `aiquila_task_duration_seconds` histogram from the original proposal is tracked
> as a follow-up.

## Enabling the endpoint

Nextcloud's `/metrics` endpoint is gated by an **IP allowlist**, not a token. Only the
client ranges listed in the `openmetrics_allowed_clients` system config may scrape it
(default: `127.0.0.0/16` and `::1/128`). Add the address range of your Prometheus host
in `config.php`:

```php
'openmetrics_allowed_clients' => ['127.0.0.0/16', '::1/128', '10.0.0.0/24'],
```

or via OCC:

```bash
php occ config:system:set openmetrics_allowed_clients 0 --value='127.0.0.0/16'
php occ config:system:set openmetrics_allowed_clients 1 --value='10.0.0.0/24'
```

The endpoint is then reachable at:

```
https://<your-nextcloud>/metrics
```

Verify AIquila series are present (run from an allowlisted host):

```bash
curl -s https://<your-nextcloud>/metrics | grep nextcloud_aiquila_
```

## Prometheus scrape config

```yaml
scrape_configs:
  - job_name: nextcloud-aiquila
    metrics_path: /metrics
    scheme: https
    static_configs:
      - targets: ['your-nextcloud:443']
```

(Ensure the Prometheus host's source IP is covered by `openmetrics_allowed_clients`.)

## Grafana

Point Grafana at the Prometheus data source and build panels from the series above.
Useful starting queries:

- **Token spend rate** — `sum by (model) (rate(nextcloud_aiquila_tokens_used_total[1h]))`
- **Task success ratio** —
  `sum(nextcloud_aiquila_tasks_total{status="success"}) / sum(nextcloud_aiquila_tasks_total)`
- **MCP server health** — `nextcloud_aiquila_mcp_server_status` (stat panel, mapped 1→up / 0→down)
- **Conversations created** — `nextcloud_aiquila_conversations_total`
