# Photos Tools

Manage albums in [Photos](https://apps.nextcloud.com/apps/photos), read photo metadata and mark favourites.

## Prerequisites

- Photos enabled (app ID: `photos`), which ships with Nextcloud

## API

Photos has no REST API. Albums are WebDAV collections at `/remote.php/dav/photos/<user>/albums/`, and metadata and favourites are WebDAV properties of the files, as the [Photos app](https://github.com/nextcloud/photos) itself uses them. The tools cover the albums the user owns, not albums shared with them.

## Available Tools

### Albums

#### `photos_list_albums`
List the user's albums with item count, location and date range.

#### `photos_get_album`
Get an album and its files, with their file IDs, names, types and sizes.

**Parameters:**
- `name` (string, required) — the album name.

#### `photos_create_album`
Create an empty album.

**Parameters:**
- `name` (string, required)

#### `photos_rename_album`
**Parameters:**
- `name` (string, required) — the current name.
- `newName` (string, required)

#### `photos_set_album_location`
Set the location shown for an album.

**Parameters:**
- `name` (string, required)
- `location` (string, required) — free text, such as `Paris, France`.

#### `photos_delete_album`
Delete an album. The photos in it are not deleted.

**Parameters:**
- `name` (string, required)

### Album contents

#### `photos_add_to_album`
Add files to an album. The files stay where they are; the album only refers to them.

**Parameters:**
- `albumName` (string, required)
- `filePaths` (string[], required) — such as `["/Photos/sunset.jpg"]`.

**Example:** "Put every photo from the Lisbon folder into a new album called Lisbon 2026."

#### `photos_remove_from_album`
Take files out of an album. The files are not deleted.

**Parameters:**
- `albumName` (string, required)
- `fileIds` (string[], required) — from `photos_get_album`.

#### `photos_add_collaborators`
Share an album with users or groups, who can then view it and add photos. Existing collaborators are kept.

**Parameters:**
- `albumName` (string, required)
- `collaborators` (array, required) — `{ id, type }` objects, where `type` is `0` for a user and `1` for a group.

### Files

#### `photos_get_metadata`
Read the metadata Nextcloud extracted from a photo or video: camera, lens, ISO, aperture, shutter speed, focal length, GPS position, dimensions and the date taken, where present.

**Parameters:**
- `path` (string, required)

#### `photos_set_favorite`
Mark a file as a favourite, or remove the mark.

**Parameters:**
- `path` (string, required)
- `favorite` (boolean, required)
