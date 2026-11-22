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
| `nextcloud_aiquila_prompt_cache_tokens_total` | counter | `model`, `request_type`, `kind` (`creation`/`read`) | Prompt-cache tokens written and read. Only providers that report them (Anthropic) contribute |
| `nextcloud_aiquila_tasks_total` | counter | `status` | Coworker task runs processed |
| `nextcloud_aiquila_conversations_total` | counter | — | Conversations created |
| `nextcloud_aiquila_mcp_server_status` | gauge | `server_id`, `server` | Per MCP server: `1` = enabled and last connection healthy, `0` = disabled or failing |

> The `aiquila_task_duration_seconds` histogram from the original proposal is tracked
> as a follow-up.

### Token usage

`nextcloud_aiquila_tokens_used_total`, `nextcloud_aiquila_prompt_cache_tokens_total`, the
`aiquila_usage` dashboard widget and the [usage export](#exporting-usage) all read
`aiquila_usage_stats`. Every model call AIquila makes writes one row there, with the
input, output and cache tokens the provider reported, the model, and the provider that
served it (`anthropic`, `bedrock`, `mistral`, …: one model id can be billed by several
providers). Rows written before the provider was recorded have none. `request_type`
names the path that spent them:

| `request_type` | Source |
|---|---|
| `chat` | The AIquila chat, streamed or not |
| `api` | The `/api/ask`-style HTTP endpoints and the `IAIquila` PHP API for other apps |
| `task` | Assistant tasks through TaskProcessing (summary, proofread, translate, …), including the Anthropic Batch summary path |
| `coworker` | Coworker runs, synchronous and batched |
| `talk` | The Talk bot. Replies to guests are stored without a user id |
| `flow` | The "Process with AIquila" Flow action |

A call that fails or reports no tokens writes nothing. Recording is best-effort: a
database error is logged as a warning and never fails the request. Admin diagnostics
(`occ aiquila:benchmark-*`, `occ aiquila:test`) are not counted.

On upgrade, a migration backfills `chat` rows from the per-message token counts already
in the chat history, once and only while the table is empty. Other request types were
never stored before, so their history cannot be recovered.

### Exporting usage

The metrics above are aggregates. For accounting, chargeback or audit, the rows
themselves can be downloaded as CSV or JSON:

| Who | Where | Covers |
|---|---|---|
| Any user | **Personal settings → AIquila → Usage**, also linked from the `aiquila_usage` widget | Their own usage |
| Admins | **Administration settings → AIquila → Usage** | Every user, one user or one group |
| Admins, scripts | `occ aiquila:usage:export` | Same as the admin page |

Over HTTP, the same exports are `GET /apps/aiquila/api/usage/export` (the caller's own; it
takes no user parameter) and `GET /apps/aiquila/api/admin/usage/export` (admins only, `403`
for anyone else). An API client that authenticates with an app password sends
`OCS-APIRequest: true` to pass Nextcloud's CSRF check. Both endpoints are in the
[OpenAPI spec](dev/openapi.md). They allow 10 exports per minute per user.

**Parameters** (query string over HTTP, `--option` for occ):

| Parameter | occ | Values |
|---|---|---|
| `format` | `--format` | `csv` (default) or `json` |
| `granularity` | `--granularity` | `request` (default): one row per model call. `day`, `week`, `month`: sums per period |
| `from` | `--from` | Start, inclusive. `YYYY-MM-DD` (a UTC day) or an ISO 8601 date-time with an offset (`2026-01-01T09:00:00+01:00`) |
| `to` | `--to` | End, exclusive. A bare date includes that whole day, so `from=2026-01-01&to=2026-01-31` is January |
| `model` | `--model` | One model id |
| `provider` | `--provider` | One provider id |
| `requestType` | `--type` | One of the `request_type` values above |
| `user` | `--user` | Admin only: one user id |
| `group` | `--group` | Admin only: the members of one group. Not combined with `user` |

`occ aiquila:usage:export` writes to stdout, or to a file with `--output <file>`:

```bash
occ aiquila:usage:export --granularity=month --from=2026-01-01 --to=2026-12-31 --output=/tmp/usage-2026.csv
occ aiquila:usage:export --group=finance --format=json --from=2026-11-01
```

**Columns.** Per request: `timestamp` (ISO 8601, UTC), `user`, `provider`, `model`,
`request_type`, `conversation_id`, `input_tokens`, `output_tokens`,
`cache_creation_tokens`, `cache_read_tokens`. Aggregated rows replace `timestamp` with
`period_start` (the period's first day, `YYYY-MM-DD`), drop `conversation_id`, and add
`requests`, the number of model calls summed. One aggregated row covers one period ×
user × provider × model × request type.

- `user` appears only in admin exports. Talk replies to guests are recorded with an
  empty user.
- Periods are UTC. Weeks start on Monday (ISO 8601). A `from` or `to` inside a period
  sums only the part of the period it covers.
- An empty field (CSV) or `null` (JSON) means the provider did not report that value: no
  cache tokens, or no provider on an older row. In aggregated rows the cache sums are
  empty only when no row in the period reported them.
- Rows are grouped by user (sorted by user id), oldest first within each user.

**CSV** follows RFC 4180: a header row, CRLF line endings, and quoting for fields with
commas, quotes or line breaks. A text field that starts with `=`, `+`, `-`, `@`, a tab or a
carriage return gets a leading `'`, so a spreadsheet does not read it as a formula. The
content type is `text/csv; charset=utf-8; header=present`.

**JSON** is one object: the export's parameters (`granularity`, `from`, `to`, `model`,
`provider`, `request_type`, `generated_at`), then `rows`, an array of objects with the
CSV column names as keys and integers as numbers.

Exports stream, so a year of per-request rows does not have to fit in PHP's memory. The
settings pages collect the file in the browser before saving it; for very large or
scheduled exports, use occ.

A user's own usage is also part of Nextcloud's user data export when the
[user_migration](https://apps.nextcloud.com/apps/user_migration) app is installed, as
`aiquila/usage.json` (the per-request JSON export). Importing it on another instance
restores the rows under the new account, without their conversation ids, which belong
to the old instance.

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
- **Prompt-cache hit ratio** — share of the prompt served from cache. `input` excludes
  cached tokens, so the denominator adds all three:
  `sum by (model) (rate(nextcloud_aiquila_prompt_cache_tokens_total{kind="read"}[1h])) / (sum by (model) (rate(nextcloud_aiquila_prompt_cache_tokens_total[1h])) + sum by (model) (rate(nextcloud_aiquila_tokens_used_total{direction="input"}[1h])))`.
  A model that keeps writing (`kind="creation"`) without reading has something
  changing in its prefix; see [Prompt caching](dev/prompt-caching.md#reading-the-result)
- **Task success ratio** —
  `sum(nextcloud_aiquila_tasks_total{status="success"}) / sum(nextcloud_aiquila_tasks_total)`
- **MCP server health** — `nextcloud_aiquila_mcp_server_status` (stat panel, mapped 1→up / 0→down)
- **Conversations created** — `nextcloud_aiquila_conversations_total`
