# Nextcloud Social Tools

Read and write the fediverse from a Nextcloud Social account: timelines, posts, favourites
and boosts, follows, notifications and search. Social speaks the Mastodon client API, so the
entities these tools return are Mastodon's — Status, Account, MediaAttachment, Poll.

> **Posting is public and irreversible.** A post is delivered to other servers as soon as it
> is made, and a later delete reaches only the servers that received it — copies already
> shown or cached elsewhere may remain. Confirm the wording and the visibility with the user
> before calling `social_post_status` or `social_boost`.

These tools are not related to [Social Sharing](../../README.md), which generates share URLs
for a Nextcloud file and needs no fediverse account.

## Prerequisites

- The Nextcloud **Social** app must be installed and enabled. The `social` tool category
  registers only when it is.
- Written against **Social v0.24.1**. The app is pre-1.0 and its API is still moving; check
  the routes against the installed version if something 404s.
- Social serves the Mastodon routes under `/index.php/apps/social/` rather than at the domain
  root, which is why third-party Mastodon clients cannot reach it yet. The base path lives in
  one constant in `mcp-server/src/client/social.ts`; upstream intends to move it.
- Authentication is the usual `NEXTCLOUD_USER` / `NEXTCLOUD_PASSWORD` app password. No OAuth
  registration is needed — Social accepts the Nextcloud session, and the `OCS-APIRequest`
  header every AIquila client sends satisfies its CSRF check.
- The account acts as the Nextcloud user's own fediverse actor, which Social creates on first
  use.

## Available Tools

| Tool | Description |
|------|-------------|
| `social_home_timeline` | Posts from the accounts this user follows |
| `social_public_timeline` | Every public post this server knows about |
| `social_hashtag_timeline` | Public posts carrying a hashtag |
| `social_list_timeline` | One list's timeline, or the available lists |
| `social_list_saved_statuses` | This user's favourites or bookmarks |
| `social_get_status` | One post, optionally with its thread |
| `social_status_history` | The successive versions of an edited post |
| `social_upload_media` | Stage a Nextcloud file as a media attachment |
| `social_post_status` | Publish a post to the fediverse |
| `social_delete_status` | Delete one of this user's own posts |
| `social_favourite` / `social_unfavourite` | Star a post, or unstar it |
| `social_boost` / `social_unboost` | Republish a post to this user's followers, or undo |
| `social_bookmark` / `social_unbookmark` | Save a post privately, or unsave it |
| `social_lookup_account` | One account's profile by handle |
| `social_account_statuses` | The posts of one account |
| `social_list_account_follows` | An account's followers, or who it follows |
| `social_follow_account` / `social_unfollow_account` | Follow or unfollow an account |
| `social_list_follow_requests` | Accounts waiting for approval |
| `social_respond_follow_request` | Approve or reject a follow request |
| `social_list_notifications` | Grouped mentions, follows, favourites and boosts |
| `social_dismiss_notifications` | Dismiss one notification, or clear all |
| `social_search` | Search accounts, hashtags and post content |

---

## Timeline Tools

Every timeline tool takes the same pagination parameters. Ids are Social's numeric status
ids, used as cursors.

**Common parameters:**

- `limit` (number, optional): Statuses to return (default 20, max 50 — the server's own cap)
- `max_id` (number, optional): Only statuses older than this id — page backwards
- `min_id` (number, optional): Only statuses newer than this id — page forwards

### social_home_timeline

Posts from the accounts this user follows, newest first.

**Parameters:** the common pagination parameters only.

**Returns:** a rendered list of statuses — author, id, timestamp, visibility, content
warning, text (HTML stripped, truncated at 280 characters), attachments, poll and the
reply/boost/favourite counts.

### social_public_timeline

Every public post this server knows about.

**Parameters:**

- `local` (boolean, optional): Only posts from this Nextcloud server (default `false`)
- plus the common pagination parameters

### social_hashtag_timeline

**Parameters:**

- `hashtag` (string, required): The hashtag, with or without the leading `#`
- `local` (boolean, optional): Only posts from this Nextcloud server (default `false`)
- plus the common pagination parameters

### social_list_timeline

**Parameters:**

- `list_id` (number, optional): The list to read. **Omit it to list the available lists and
  their ids** — there is no separate tool for that.
- plus the common pagination parameters

Creating and editing lists is not covered; do that in the Social app.

### social_list_saved_statuses

**Parameters:**

- `kind` (string, required): `favourites` (starred) or `bookmarks` (privately saved)
- plus the common pagination parameters

---

## Status Tools

### social_get_status

**Parameters:**

- `status_id` (number, required): The numeric status id
- `include_context` (boolean, optional): Also fetch the thread — the posts it replies to and
  the replies to it (default `false`, one extra request when `true`)

### social_status_history

**Parameters:**

- `status_id` (number, required): The numeric status id

**Returns:** each version with its timestamp, or a note that the post has never been edited.

### social_upload_media

Stages a file from the user's own Nextcloud storage as a media attachment and returns the
media id. Nothing is published until `social_post_status` is called with that id. The file is
copied at upload time, so moving or deleting the original afterwards does not empty the post.

**Parameters:**

- `path` (string, required): Path inside the user's Nextcloud files, e.g. `Photos/sunset.jpg`
- `description` (string, optional): Alt text, for accessibility

**Returns:** the media id to pass to `social_post_status`.

The path is resolved inside the user's own files and nowhere else; anything outside is a 404.

### social_post_status

Publishes a post. **Public and irreversible** — see the warning at the top of this page.

**Parameters:**

- `status` (string, required): The text of the post
- `visibility` (string, optional): `public` (default — world-readable and listed), `unlisted`,
  `private` (followers only) or `direct` (mentioned accounts only). Social treats an
  unrecognised value as `direct`.
- `in_reply_to_id` (number, optional): The status this replies to
- `media_ids` (string[], optional): Media ids from `social_upload_media`
- `poll` (object, optional): `{ options: string[], expires_in: number, multiple?: boolean }`
  — `expires_in` is in seconds, e.g. `86400` for a day
- `spoiler_text` (string, optional): Content warning shown in place of the post
- `sensitive` (boolean, optional): Mark attached media as sensitive
- `language` (string, optional): ISO 639 code, e.g. `en`

Social rate-limits posting to 30 per minute per user.

### social_delete_status

**Parameters:**

- `status_id` (number, required): The numeric status id

Only the author can delete a post.

---

## Interaction Tools

All six take a single `status_id` (number, required) and are idempotent.

### social_favourite / social_unfavourite

Star a post. Federated: the author's server is told who favourited it.

### social_boost / social_unboost

Republish a post to this user's followers. Federated and public — confirm before boosting.

### social_bookmark / social_unbookmark

Save a post so it can be found again. Bookmarks are private to this user and are **not**
federated.

---

## Account and Follow Tools

### social_lookup_account

**Parameters:**

- `acct` (string, required): `user` for a local account, `user@server` for a remote one. A
  leading `@` is stripped.

**Returns:** display name, handle, follower/following/post counts, bio and profile URL.

### social_account_statuses

**Parameters:**

- `account` (string, required): Account id, or the handle
- `only_media` (boolean, optional): Only posts carrying media (default `false`)
- plus the common pagination parameters

### social_list_account_follows

**Parameters:**

- `account` (string, required): Account id, or the handle
- `direction` (string, required): `followers` (who follows this account) or `following`
  (who this account follows)
- plus the common pagination parameters

### social_follow_account / social_unfollow_account

**Parameters:**

- `account` (string, required): Account id, or the handle

Following is federated. A locked account has to approve the request before it takes effect.

### social_list_follow_requests

No parameters. Lists the accounts waiting for this user to approve them.

### social_respond_follow_request

**Parameters:**

- `account_id` (string, required): The id from `social_list_follow_requests`
- `action` (string, required): `authorize` to approve, `reject` to decline

Approving lets that account see this user's followers-only posts.

---

## Notification Tools

### social_list_notifications

Uses Social's grouped (v2) notifications, so forty favourites of one post read as one line
rather than forty.

**Parameters:**

- `limit` (number, optional): Notifications to read before grouping (default 20, max 50)
- `max_id` (number, optional): Only notifications older than this id
- `types` (string[], optional): Only these types, e.g. `["mention", "follow"]`
- `exclude_types` (string[], optional): Skip these types

**Returns:** one line per group — type, the sample accounts, how many more, the timestamp,
the notification id and the post it refers to.

### social_dismiss_notifications

**Parameters:**

- `notification_id` (number, optional): The notification to dismiss. **Omit it to clear every
  notification.**

Dismissed notifications cannot be brought back.

---

## Search Tool

### social_search

**Parameters:**

- `q` (string, required): Text, a handle, a hashtag or a post URL
- `type` (string, optional): `accounts`, `hashtags` or `statuses`; omit to search all three
- `limit` (number, optional): Results per kind (default 20, max 40)
- `resolve` (boolean, optional): Fetch an unknown handle or URL from its home server
  (default `false`). This contacts the remote server, so only set it when the user has
  supplied the address.

---

## Example Usage

Ask Claude:

- "What's new on my fediverse timeline?"
- "Show me the thread around post 1423."
- "Who's waiting for me to approve a follow?"
- "Find accounts posting about #nextcloud."
- "Post 'Release day' to my fediverse account, followers only."
- "Attach Photos/release.png with alt text 'the release notes', then post it."

## Limitations

- **Read caps.** Social clamps a timeline read to 50 statuses; the tools pass a larger
  `limit` through as 50. Page with `max_id` rather than asking for more.
- **Out of scope in this module:** reports, blocks, mutes and flags; the Pixelfed and
  PeerTube routes; admin and moderation endpoints; filters; creating and editing lists;
  scheduled statuses; account migration. Use the Social web UI for those.
- **Editing a post** is not exposed — only reading its edit history.
- **Post text is truncated** at 280 characters in the rendered output. Use
  `social_get_status` for the full text of one post.
- Nextcloud Social is pre-1.0. Routes and entity fields may change between releases.

## Troubleshooting

**"Not authenticated to Nextcloud Social"** — the app password is wrong, or the Social app is
not enabled for that user. Check with:

```bash
curl -s -u "$NEXTCLOUD_USER:$NEXTCLOUD_PASSWORD" -H 'OCS-APIRequest: true' \
  "$NEXTCLOUD_URL/index.php/apps/social/api/v1/timelines/home/?limit=1"
```

**The tools are missing entirely** — the `social` app is not enabled, so the category did not
register. Either enable it, or set `MCP_TOOLS=social` to register the category regardless.

**A remote account or post is not found** — pass the full `user@server` handle, and set
`resolve: true` on `social_search` so this server fetches it from its home server first.

## References

- [Nextcloud Social](https://github.com/nextcloud/social)
- [Mastodon client API](https://docs.joinmastodon.org/client/intro/) — the entities and
  parameters Social follows
- [Adding App Tools](../../development/adding-apps.md)
