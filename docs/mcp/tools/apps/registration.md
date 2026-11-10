# Registration Tools

Read and change the self-service sign-up settings of the [Registration](https://apps.nextcloud.com/apps/registration) app.

## Prerequisites

- Registration installed and enabled (app ID: `registration`)
- An administrator account. The settings are app configuration, which only administrators can read or write.

## API

The Registration app has no API of its own for its settings. The tools read and write its app configuration through the provisioning API at `/ocs/v2.php/apps/provisioning_api/api/v1/config/apps/registration`.

## Settings

| Key | Value | Meaning |
|---|---|---|
| `allowed_domains` | JSON array string, such as `["example.com"]` | Email domains allowed to register, or blocked with `domains_is_blocklist` |
| `domains_is_blocklist` | `yes` / `no` | Treat `allowed_domains` as a blocklist |
| `show_domains` | `yes` / `no` | Show the domain list on the form |
| `admin_approval_required` | `yes` / `no` | An administrator must enable new accounts |
| `registered_user_group` | group ID | Group new accounts join |
| `email_is_optional` | `yes` / `no` | The email address may be left out |
| `email_is_login` | `yes` / `no` | The email address becomes the login name |
| `disable_email_verification` | `yes` / `no` | Skip the verification email |
| `email_verification_hint` | text | Added to the verification email |
| `additional_hint` | text | Shown on the sign-up form |
| `username_policy_regex` | regular expression | A chosen user name must match it |
| `show_fullname` / `enforce_fullname` | `yes` / `no` | Show, or require, the full name field |
| `show_phone` / `enforce_phone` | `yes` / `no` | Show, or require, the phone field |

## Available Tools

#### `get_registration_settings`
Read all the settings above.

#### `update_registration_settings`
Change one or more settings.

**Parameters:**
- `settings` (array, required) — `{ key, value }` pairs, with keys from the table.

**Example:** "Only let people with an @example.com address sign up, and require admin approval."

#### `reset_registration_setting`
Reset a setting to the app's default by deleting the stored value.

**Parameters:**
- `key` (enum, required) — a key from the table.
