# Request metadata (`metadata.user_id`)

The Anthropic Messages API accepts a `metadata` object whose `user_id` is an
opaque per-user identifier. Anthropic uses it for abuse detection: when a key
is flagged, the report names the `user_id` rather than the whole instance, so
an admin can tell *which* account caused it instead of losing API access for
everyone.

AIquila can attach that field, but **never sends the Nextcloud login name** —
a UID is personal data. It sends a keyed hash instead.

This applies to the **Anthropic provider only** (`ClaudeSDKService`). No other
provider's API has an equivalent field.

## What is sent

```
metadata.user_id = hash_hmac('sha256', <nextcloud uid>, <instance salt>)
```

A 64-character lowercase hex string. The salt is 32 random bytes, generated
once per instance and stored encrypted in Nextcloud's credential manager under
`aiquila/secret/metadata_salt`.

Two properties follow from that:

- **Not reversible by Anthropic.** The salt is secret, so the hash cannot be
  brute-forced back to a login name from the outside — unlike a plain
  `sha256(uid)`, which a wordlist of common usernames would crack instantly.
- **Not correlatable across deployments.** Two AIquila instances produce
  different hashes for the same login name, so the field cannot be used to
  link one person's activity across servers.

The field is **omitted entirely** — not sent empty — when sending is off, and
whenever there is no user attached to the request. Background TaskProcessing
jobs, the `occ` commands and the setup checks all fall into that second case.

## Turning it on

Off by default. Admin settings → **AIquila** → the Claude (Anthropic) provider
card → *Advanced* → **Send a pseudonymous user id with each request**.

Stored as the app config value `send_user_metadata`:

```bash
occ config:app:set aiquila send_user_metadata --value=true
```

## Coverage

Every Anthropic request path carries the field once enabled. They all build
their parameters through `ClaudeSDKService::buildRequestParams()`:

| Path | Dispatcher | SDK type |
|------|-----------|----------|
| Non-streaming | `callCreate()` | `Anthropic\Messages\Metadata` |
| Streaming | `callCreateStream()` | `Anthropic\Messages\Metadata` |
| Message batches | `toBatchParams()` → `callBatchCreate()` | `Anthropic\Messages\Metadata` |
| Native MCP connector (beta) | `callBetaCreateStreamWithMcp()` | `Anthropic\Beta\Messages\BetaMetadata` |

## Reading and rotating the salt

From the provider card: **Reveal** prints the current salt, **Rotate** issues a
new one (with a confirmation prompt). Both are admin-only — they go through
`POST /api/admin/providers/anthropic/action/{actionId}`.

From the command line:

```bash
occ aiquila:metadata-salt              # sending on/off + the current salt
occ aiquila:metadata-salt --rotate     # issue a new salt
occ aiquila:metadata-salt --hash alice # the hash AIquila would send for 'alice'
```

Rotating **permanently** breaks the mapping for hashes already sent: an abuse
report that predates the rotation can no longer be resolved. Rotate when the
salt has leaked, or deliberately to sever the link to past traffic — not as
routine hygiene.

## Resolving a hash back to an account

Given a `user_id` from an Anthropic abuse report, compare it against every
account. `--hash` does the computation for one uid:

```bash
for uid in $(occ user:list --output=json | jq -r 'keys[]'); do
  echo "$uid $(occ aiquila:metadata-salt --hash "$uid")"
done | grep "<user_id from the report>"
```

Or compute it yourself from the salt — the scheme is plain HMAC-SHA256 with no
encoding tricks:

```bash
printf '%s' 'alice' | openssl dgst -sha256 -hmac "$(occ aiquila:metadata-salt | awk '/Salt:/ {print $2}')"
```

`--hash` works regardless of whether sending is currently enabled, so an admin
who turned the feature off can still answer a report about traffic sent while
it was on.

## Implementation

- `nextcloud-app/lib/Service/RequestMetadataService.php` — the switch, the salt,
  and the hash.
- `nextcloud-app/lib/Service/ClaudeSDKService.php` — `buildRequestParams()`
  attaches it; `metadataParam()` / `betaMetadataParam()` convert it for the SDK.
- `nextcloud-app/lib/Command/MetadataSaltCommand.php` — the `occ` command.
- The card's two buttons are schema-declared
  (`ProviderSettingsSchema::action()`) and run through
  `ProviderActionsInterface::runAction()`; see
  [Provider settings schema](provider-settings.md).
