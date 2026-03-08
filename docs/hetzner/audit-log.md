# aiquila-hetzner — Audit Log

← [Overview](README.md)

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
