# Tool upstream matrix

Every MCP tool category wraps an API that belongs to someone else: a Nextcloud server
component, a Nextcloud app, or the AIquila Nextcloud app in this repository. Categories
were added at different times, against whatever upstream versions were current then.
This page records, per category, what it talks to, when it shipped, and when it was
last checked against upstream — so a stale integration can be spotted before a user
reports it.

One row per `MCP_TOOLS` category, matching `TOOL_REGISTRY` in
`mcp-server/src/tool-registry.ts`.

## Columns

| Column | Meaning |
|---|---|
| Category | The `MCP_TOOLS` category name |
| Source | The module under `mcp-server/src/tools/` that defines the tools |
| Upstream | The Nextcloud app id(s) from `appIds`, or the server component for always-on categories |
| API | The surface the tools call: OCS, WebDAV, CalDAV, CardDAV, an app's own REST API, or OCC through the AIquila app |
| Reference | The upstream API documentation the implementation follows |
| Introduced | The MCP server release, commit and date the category first shipped |
| Last verified | The upstream app version, Nextcloud version and date the category was last checked against its reference |

`Last verified` is `—` until someone has checked the category against a named upstream
version. Being installed on the dev stack does not count; reading the reference and
exercising the tools does.

## Keeping it current

When a change adds a category, changes which endpoints a category calls, or fixes a
behaviour that depends on upstream:

1. Re-read the reference for the upstream version you are targeting. If the link has
   moved, fix it.
2. Exercise the affected tools against that version (the dev stack, or the standalone
   stack's `make test-tools`).
3. Update the row's `Last verified` with the upstream app version (`occ app:list`),
   the Nextcloud version (`occ status`) and the date, in the same PR.

A new category also fills in `Introduced`. Its commit is the one that first adds the
category to `TOOL_REGISTRY`; after a squash merge, that is the merge commit on `main`.

## Matrix

### Server components (always registered)

| Category | Source | Upstream | API | Reference | Introduced | Last verified |
|---|---|---|---|---|---|---|
| `files` | `system/files.ts` | `dav`, `aiquila` | WebDAV, App REST | [WebDAV basics](https://docs.nextcloud.com/server/latest/developer_manual/client_apis/WebDAV/basic.html), [OpenAPI](../dev/openapi.md) | 0.1.2 · `33a6ce8` · 2025-11-21 | dav 1.40.0, aiquila 0.5.1 · NC 34.0.5 · 2026-10-30 |
| `status` | `system/status.ts` | core | OCS, OCC | [OCS API overview](https://docs.nextcloud.com/server/latest/developer_manual/client_apis/OCS/ocs-api-overview.html) | 0.1.5 · `8f5df8b` · 2026-02-07 | core, aiquila 0.5.1 · NC 34.0.5 · 2026-10-30 |
| `apps` | `system/apps.ts` | `provisioning_api` | OCS, OCC | [Apps provisioning](https://docs.nextcloud.com/server/latest/admin_manual/configuration_user/instruction_set_for_apps.html) | 0.1.5 · `8f5df8b` · 2026-02-07 | provisioning_api 1.24.0, aiquila 0.5.1 · NC 34.0.5 · 2026-10-30 |
| `tags` | `system/tags.ts` | `systemtags` | WebDAV | [WebDAV](https://docs.nextcloud.com/server/latest/developer_manual/client_apis/WebDAV/index.html) | 0.1.12 · `ab9c5aa` · 2026-02-10 | systemtags 1.24.0, dav 1.40.0 · NC 34.0.5 · 2026-10-30 |
| `search` | `system/search.ts` | core | OCS | [Unified search](https://docs.nextcloud.com/server/latest/developer_manual/digging_deeper/search.html) | 0.1.64 · `11ba400` · 2026-03-19 | core · NC 34.0.5 · 2026-10-30 |
| `users` | `apps/users.ts` | `provisioning_api` | OCS | [User provisioning](https://docs.nextcloud.com/server/latest/admin_manual/configuration_user/instruction_set_for_users.html) | 0.1.5 · `8f5df8b` · 2026-02-07 | provisioning_api 1.24.0 · NC 34.0.5 · 2026-10-30 |
| `groups` | `apps/groups.ts` | `provisioning_api` | OCS | [Group provisioning](https://docs.nextcloud.com/server/latest/admin_manual/configuration_user/instruction_set_for_groups.html) | 0.1.5 · `8f5df8b` · 2026-02-07 | provisioning_api 1.24.0 · NC 34.0.5 · 2026-10-30 |
| `shares` | `apps/shares.ts` | `files_sharing` | OCS | [OCS Share API](https://docs.nextcloud.com/server/latest/developer_manual/client_apis/OCS/ocs-share-api.html) | 0.1.5 · `8f5df8b` · 2026-02-07 | files_sharing 1.26.0 · NC 34.0.5 · 2026-10-30 |
| `absence` | `apps/absence.ts` | `dav` | OCS | [Out-of-office API](https://docs.nextcloud.com/server/latest/developer_manual/client_apis/OCS/ocs-out-of-office-api.html) | 0.2.8 · `bacc1f2` · 2026-03-24 | dav 1.40.0 · NC 34.0.5 · 2026-10-30 |
| `trash` | `apps/trash.ts` | `files_trashbin` | WebDAV | [Trashbin](https://docs.nextcloud.com/server/latest/developer_manual/client_apis/WebDAV/trashbin.html) | 0.2.8 · `bacc1f2` · 2026-03-24 | files_trashbin 1.24.0 · NC 34.0.5 · 2026-10-30 |
| `versions` | `apps/versions.ts` | `files_versions` | WebDAV | [Versions](https://docs.nextcloud.com/server/latest/developer_manual/client_apis/WebDAV/versions.html) | 0.2.8 · `bacc1f2` · 2026-03-24 | files_versions 1.27.0 · NC 34.0.5 · 2026-10-30 |

### AIquila Nextcloud app

These call the app in `nextcloud-app/`, so its API is described by the OpenAPI spec in
this repository ([OpenAPI](../dev/openapi.md)) rather than an external reference.
`Last verified` names the AIquila app version.

| Category | Source | Upstream | API | Reference | Introduced | Last verified |
|---|---|---|---|---|---|---|
| `aiquila` | `apps/aiquila.ts` | `aiquila` | OCC | [OpenAPI](../dev/openapi.md) | 0.1.2 · `a3fccfc` · 2026-02-01 | aiquila 0.5.1 · NC 34.0.5 · 2026-10-30 |
| `security` | `system/security.ts` | `aiquila` | OCC | [OpenAPI](../dev/openapi.md) | 0.1.5 · `8f5df8b` · 2026-02-07 | aiquila 0.5.1 · NC 34.0.5 · 2026-10-30 |
| `occ` | `system/occ.ts` | `aiquila` | OCC | [OpenAPI](../dev/openapi.md) | 0.1.8 · `1962222` · 2026-02-08 | aiquila 0.5.1 · NC 34.0.5 · 2026-10-30 |
| `projects` | `apps/projects.ts` | `aiquila` | App REST | [OpenAPI](../dev/openapi.md) | 0.2.11 · `6f8e108` · 2026-04-02 | aiquila 0.5.1 · NC 34.0.5 · 2026-10-30 |
| `cowork` | `apps/cowork.ts` | `aiquila` | App REST | [OpenAPI](../dev/openapi.md) | 0.3.20 · `d80fd73` · 2026-06-13 | aiquila 0.5.1 · NC 34.0.5 · 2026-10-30 |

### Nextcloud apps

| Category | Source | Upstream | API | Reference | Introduced | Last verified |
|---|---|---|---|---|---|---|
| `calendar` | `apps/calendar.ts` | `calendar` | CalDAV | [RFC 4791](https://www.rfc-editor.org/rfc/rfc4791), [RFC 5545](https://www.rfc-editor.org/rfc/rfc5545) | 0.1.7 · `b98bd51` · 2026-02-08 | — |
| `tasks` | `apps/tasks.ts` | `tasks` | CalDAV | [RFC 4791](https://www.rfc-editor.org/rfc/rfc4791), [RFC 5545](https://www.rfc-editor.org/rfc/rfc5545) | 0.1.2 · `33a6ce8` · 2025-11-21 | — |
| `contacts` | `apps/contacts.ts` | `contacts` | CardDAV | [RFC 6352](https://www.rfc-editor.org/rfc/rfc6352), [RFC 6350](https://www.rfc-editor.org/rfc/rfc6350) | 0.1.7 · `b98bd51` · 2026-02-08 | — |
| `notes` | `apps/notes.ts` | `notes` | App REST v1 | [Notes API v1](https://github.com/nextcloud/notes/blob/main/docs/api/v1.md) | 0.1.2 · `33a6ce8` · 2025-11-21 | notes 6.1.0 · NC 34.0.5 · 2026-10-30 |
| `news` | `apps/news.ts` | `news` | App REST v1-3 | [News API v1-3](https://github.com/nextcloud/news/blob/master/docs/development/api/api-v1-3.md) | 0.3.18 · `0a6ce5e` · 2026-06-12 | news 29.0.0 · NC 34.0.5 · 2026-10-30 |
| `mail` | `apps/mail.ts` | `mail` | App REST, OCS (send) | [nextcloud/mail](https://github.com/nextcloud/mail) | 0.1.7 · `b98bd51` · 2026-02-08 | mail 5.12.6 · NC 34.0.5 · 2026-10-30 |
| `deck` | `apps/deck.ts` | `deck` | App REST v1.0 | [Deck API](https://deck.readthedocs.io/en/latest/API/) | 0.2.10 · `5ac75d0` · 2026-03-28 | deck 1.18.6 · NC 34.0.5 · 2026-10-30 |
| `passman` | `apps/passman.ts` | `passman` | App REST v2 | [Passman API](https://github.com/nextcloud/passman/blob/master/docs/api.md) | 0.3.24 · `a1c8f7e` · 2026-06-27 | passman 2.6.5 · NC 34.0.5 · 2026-10-30 |
| `cookbook` | `apps/cookbook.ts` | `cookbook` | WebDAV (recipe files), App REST (config, reindex) | [nextcloud/cookbook](https://github.com/nextcloud/cookbook) | 0.1.2 · `33a6ce8` · 2025-11-21 | cookbook 0.11.12 · NC 34.0.5 · 2026-10-30 |
| `maps` | `apps/maps.ts` | `maps` | App REST | [nextcloud/maps](https://github.com/nextcloud/maps) | 0.1.13 · `1915054` · 2026-02-12 | maps 1.8.0 · NC 34.0.5 · 2026-10-30 |
| `photos` | `apps/photos.ts` | `photos` | WebDAV | [nextcloud/photos](https://github.com/nextcloud/photos) | 0.2.10 · `500fc1c` · 2026-03-28 | — |
| `talk` | `apps/talk.ts` | `spreed` | OCS | [Talk API](https://nextcloud-talk.readthedocs.io/en/latest/) | 0.2.7 · `f6ca299` · 2026-03-21 | spreed 24.0.6 · NC 34.0.5 · 2026-10-30 |
| `circles` | `apps/circles.ts` | `circles` | OCS | [routes](https://github.com/nextcloud/circles/blob/master/appinfo/routes.php) | 0.2.10 · `3741789` · 2026-03-28 | circles 34.0.0 · NC 34.0.5 · 2026-10-30 |
| `bookmarks` | `apps/bookmarks.ts` | `bookmarks` | App REST v2 | [Bookmarks API](https://nextcloud-bookmarks.readthedocs.io/en/latest/) | 0.1.13 · `1915054` · 2026-02-12 | bookmarks 16.2.7 · NC 34.0.5 · 2026-10-30 |
| `polls` | `apps/polls.ts` | `polls` | OCS v1.0 | [Polls API v1.0](https://github.com/nextcloud/polls/blob/main/docs/API_v1.0.md) (OpenAPI) | 0.3.4 · `fbf3f55` · 2026-04-22 | polls 9.3.0 · NC 34.0.5 · 2026-10-30 |
| `forms` | `apps/forms.ts` | `forms` | OCS v3 | [Forms API v3](https://github.com/nextcloud/forms/blob/main/docs/API_v3.md) | 0.3.5 · `54d1470` · 2026-04-22 | forms 5.4.1 · NC 34.0.5 · 2026-10-30 |
| `text` | `apps/text.ts` | `text` | OCS, WebDAV | [nextcloud/text](https://github.com/nextcloud/text) | 0.3.6 · `45b96df` · 2026-05-06 | text 8.0.0, dav 1.40.0 · NC 34.0.5 · 2026-10-30 |
| `assistant` | `apps/assistant.ts` | `assistant` | OCS | [Task processing API](https://docs.nextcloud.com/server/latest/developer_manual/client_apis/OCS/ocs-taskprocessing-api.html) | 0.1.55 · `b66ad3c` · 2026-03-10 | assistant 3.5.1, core · NC 34.0.5 · 2026-10-30 |
| `translate` | `apps/translate.ts` | `translate2`, `aiquila`, `text_translate`, `translate` | OCS (TaskProcessing, Translation API fallback) | [Task processing API](https://docs.nextcloud.com/server/latest/developer_manual/client_apis/OCS/ocs-taskprocessing-api.html), [Translation API](https://docs.nextcloud.com/server/latest/developer_manual/client_apis/OCS/ocs-translation-api.html) | 0.2.7 · `f6ca299` · 2026-03-21 | aiquila 0.5.1 (TaskProcessing), core · NC 34.0.5 · 2026-10-30 |
| `user_status` | `apps/user-status.ts` | `user_status` | OCS | [Status API](https://docs.nextcloud.com/server/latest/developer_manual/client_apis/OCS/ocs-status-api.html) | 0.2.8 · `bacc1f2` · 2026-03-24 | user_status 1.14.0 · NC 34.0.5 · 2026-10-30 |
| `notifications` | `apps/notifications.ts` | `notifications` | OCS | [Notifications endpoint v2](https://github.com/nextcloud/notifications/blob/master/docs/ocs-endpoint-v2.md) | 0.2.8 · `bacc1f2` · 2026-03-24 | notifications 7.0.0-dev.1 · NC 34.0.5 · 2026-10-30 |
| `activity` | `apps/activity.ts` | `activity` | OCS | [Activity endpoint v2](https://github.com/nextcloud/activity/blob/master/docs/endpoint-v2.md) | 0.3.22 · `f100d46` · 2026-06-13 | activity 7.0.0 · NC 34.0.5 · 2026-10-30 |
| `announcements` | `apps/announcements.ts` | `announcementcenter` | OCS | [routes](https://github.com/nextcloud/announcementcenter/blob/main/appinfo/routes.php) | 0.3.23 · `d228c35` · 2026-06-14 | announcementcenter 7.5.1 · NC 34.0.5 · 2026-10-30 |
| `registration` | `apps/registration.ts` | `registration`, `provisioning_api` | OCS (app config) | [nextcloud/registration](https://github.com/nextcloud/registration) | 0.3.23 · `e46df78` · 2026-06-14 | registration 3.1.1, provisioning_api 1.24.0 · NC 34.0.5 · 2026-10-30 |
| `terms_of_service` | `apps/terms-of-service.ts` | `terms_of_service`, `provisioning_api` | OCS | [OpenAPI](https://github.com/nextcloud/terms_of_service/blob/master/openapi-full.json) | 0.3.23 · `9ba9bc6` · 2026-06-20 | terms_of_service 4.8.0, provisioning_api 1.24.0 · NC 34.0.5 · 2026-10-30 |
| `recommendations` | `apps/recommendations.ts` | `recommendations` | OCS | [Recommendations API](https://docs.nextcloud.com/server/latest/developer_manual/client_apis/OCS/ocs-recommendations-api.html) | 0.3.24 · `fcab4f5` · 2026-06-20 | recommendations 7.0.0 · NC 34.0.5 · 2026-10-30 |
| `social` | `apps/social.ts` | `social` | App REST (Mastodon-style `/api/v1`) | [AlohaSocial/social](https://github.com/AlohaSocial/social) | 0.4.15 · `007e921` · 2026-09-19 | — |
| `social_sharing` | `apps/social-sharing.ts` | `socialsharing_*` | OCS (shares), share URLs built locally | [nextcloud/socialsharing](https://github.com/nextcloud/socialsharing) | 0.3.24 · `82288dd` · 2026-06-27 | socialsharing_email 4.1.0, files_sharing 1.26.0 · NC 34.0.5 · 2026-10-30 |
