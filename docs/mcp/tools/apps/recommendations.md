# Recommendations Tools

The files Nextcloud suggests to the signed-in user, from the [Recommendations](https://apps.nextcloud.com/apps/recommendations) app.

## Prerequisites

- Recommendations app installed and enabled (app ID: `recommendations`)

## API

Uses the [Recommendations OCS API](https://docs.nextcloud.com/server/latest/developer_manual/client_apis/OCS/ocs-recommendations-api.html) at `/ocs/v2.php/apps/recommendations/api/v1/recommendations`.

## Available Tools

#### `list_recommendations`
List the recommended files with their path, time and the reason for each, such as recently edited or recently shared. A user who has turned recommendations off in their settings gets a message saying so rather than a list.

**Example:** "What was I working on recently?"
