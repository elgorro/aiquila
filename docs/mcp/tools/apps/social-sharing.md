# Social Sharing Tools

Build the URLs that share a public link on email and social networks, as the [Social sharing](https://github.com/nextcloud/socialsharing) apps do in the Files sidebar.

## Prerequisites

- At least one of the network apps enabled: `socialsharing_email`, `socialsharing_twitter`, `socialsharing_facebook`, `socialsharing_telegram`, `socialsharing_whatsapp`, `socialsharing_bluesky` or `socialsharing_diaspora`
- A public link share on the file or folder, for example from `create_share`

## API

The Social sharing apps have no server API: each only adds a button to the browser. The tool reads the public link through the Files sharing OCS API (`/ocs/v2.php/apps/files_sharing/api/v1/shares`) and builds the same URLs the apps would.

## Available Tools

#### `generate_social_share_links`
Return one share URL per network.

**Parameters:**
- `share_id` (number) — the ID of a public link share.
- `path` (string) — a file or folder; its public link share is used. One of `share_id` and `path` is required.
- `networks` (string[], optional) — limit the result, such as `["email", "bluesky"]`. Default: every enabled network.

Only an administrator can see which apps are enabled. For an administrator the tool returns the enabled networks; for anyone else it returns every network and says so.

**Example:** "Give me a Bluesky link for the event flyer."
