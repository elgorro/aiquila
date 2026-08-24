# Nextcloud Maps Tools

Integration with Nextcloud Maps app. Manage favorites, favorite/device shares, GPS devices,
tracks, photos, contacts, and custom maps through Claude.

Tested against Nextcloud Maps 1.8.0.

## Prerequisites

- Nextcloud Maps app must be installed and enabled
- For photo features, photos must be stored in Nextcloud
- For contact features, the Contacts app must be installed

## Custom maps and `myMapId`

Maps stores data in two places: the user's default store (the database) and *custom maps*,
which are folders holding JSON files. Most tools take an optional `myMapId` — the ID of a
custom map, as returned by `list_maps` — to operate on that map instead of the default
store. Omit it to work with the default store.

## Available Tools

### Favorites
| Tool | Description |
|------|-------------|
| `list_map_favorites` | List saved locations/pins |
| `create_map_favorite` | Create a new favorite |
| `update_map_favorite` | Update a favorite |
| `delete_map_favorite` | Delete a favorite |
| `rename_map_favorite_category` | Rename one or more favorite categories |
| `export_map_favorites` | Export favorites as GPX |
| `import_map_favorites` | Import favorites from file |

### Favorite Category Sharing
| Tool | Description |
|------|-------------|
| `list_shared_map_categories` | List categories shared via public link |
| `share_map_category` | Share a category via public link |
| `unshare_map_category` | Revoke a category share |
| `add_shared_category_to_map` | Add a shared category to a custom map |

### Devices & GPS
| Tool | Description |
|------|-------------|
| `list_map_devices` | List GPS tracking devices |
| `get_map_device_points` | Get device location history |
| `add_map_device_point` | Log a GPS location point |
| `update_map_device` | Update device display color |
| `delete_map_device` | Delete a device and its points |
| `export_map_devices` | Export device data as GPX |
| `import_map_devices` | Import device data from file |

### Device Sharing
| Tool | Description |
|------|-------------|
| `share_map_device` | Share a device via public link for a time window |
| `list_shared_map_devices` | List device shares added to a custom map |
| `remove_map_device_share` | Revoke a device share |
| `add_shared_device_to_map` | Add a shared device to a custom map |

### Tracks
| Tool | Description |
|------|-------------|
| `list_map_tracks` | List GPS tracks (GPX/KML) |
| `get_map_track` | Get track details and content |
| `update_map_track` | Update track color/metadata |
| `export_map_route` | Export a route as GPX |

### Photos
| Tool | Description |
|------|-------------|
| `list_map_photos` | List geolocated photos |
| `list_map_photos_nonlocalized` | List photos without GPS data |
| `place_map_photos` | Set GPS coordinates on photos |
| `reset_map_photo_coords` | Remove GPS coordinates from photos |
| `get_map_photo_job_status` | Status of the photo geolocation background job |

### Contacts
| Tool | Description |
|------|-------------|
| `list_map_contacts` | List contacts that have a geographic address |
| `search_map_contacts` | Search contacts by name |
| `place_map_contact` | Set a contact's address and coordinates |
| `add_contact_to_map` | Copy a contact into a custom map |
| `delete_map_contact_address` | Remove a contact's address and coordinates |

### Custom Maps
| Tool | Description |
|------|-------------|
| `list_maps` | List all custom maps |
| `create_map` | Create a new custom map |
| `update_map` | Update a custom map |
| `delete_map` | Delete a custom map |

---

## Favorites

### list_map_favorites

List map favorites (saved locations/pins) from Nextcloud Maps.

**Parameters:**
- `pruneBefore` (number, optional): Unix timestamp — only return favorites modified after this time
- `myMapId` (number, optional): Custom map to scope to

**Returns:**
List of favorites with coordinates, name, category, and comment.

**Example Usage:**
```
Ask Claude: "List my map favorites"
Ask Claude: "Show saved locations from the last month"
```

---

### create_map_favorite

Create a new map favorite (saved location/pin) in Nextcloud Maps.

**Parameters:**
- `lat` (number, required): Latitude
- `lng` (number, required): Longitude
- `name` (string, optional): Name of the location
- `category` (string, optional): Category name
- `comment` (string, optional): A comment or note
- `extensions` (string, optional): Extra data as a string
- `myMapId` (number, optional): Custom map to create the favorite in

**Returns:**
Created favorite with ID.

**Example Usage:**
```
Ask Claude: "Save a favorite at 59.3293, 18.0686 named 'Stockholm Office'"
Ask Claude: "Pin my favorite restaurant at coordinates 48.8566, 2.3522 in category 'Food'"
```

---

### update_map_favorite

Update an existing map favorite. Only provided fields are changed.

**Parameters:**
- `id` (number, required): Favorite ID
- `name` (string, optional): New name
- `lat` (number, optional): New latitude
- `lng` (number, optional): New longitude
- `category` (string, optional): New category
- `comment` (string, optional): New comment
- `extensions` (string, optional): New extensions data
- `myMapId` (number, optional): Custom map the favorite lives in

**Returns:**
Updated favorite details.

Coordinates are always sent to the server, so when `lat` or `lng` is omitted the tool looks
up the favorite's current coordinates first.

---

### delete_map_favorite

Delete a map favorite by its ID. This action is irreversible.

**Parameters:**
- `id` (number, required): Favorite ID to delete
- `myMapId` (number, optional): Custom map the favorite lives in

**Returns:**
Confirmation message.

---

### rename_map_favorite_category

Rename one or more favorite categories. All favorites in the listed categories are moved to
the new name; an existing category share follows the rename.

**Parameters:**
- `categories` (string[], required): Existing category names to rename
- `newName` (string, required): New category name
- `myMapId` (number, optional): Custom map to scope to

**Returns:**
Confirmation message.

---

### export_map_favorites

Export favorites as a GPX file to the user's Nextcloud `/Maps` folder.

**Parameters:**
- `categoryList` (string[], required): Categories to export
- `begin` (number, optional): Start timestamp filter
- `end` (number, optional): End timestamp filter
- `all` (boolean, optional): If true, filename is not prefixed with `filtered-`

**Returns:**
File path of exported favorites.

**Example Usage:**
```
Ask Claude: "Export all my 'Food' favorites as GPX"
```

---

### import_map_favorites

Import favorites from a file in Nextcloud storage. Supports GPX, KML, KMZ, JSON, and GeoJSON.

**Parameters:**
- `path` (string, required): Relative path to the file in Nextcloud storage

**Returns:**
Import results.

**Example Usage:**
```
Ask Claude: "Import favorites from /Maps/my-places.gpx"
```

---

## Favorite Category Sharing

### list_shared_map_categories

List favorite categories that are shared via a public link.

**Parameters:**
- `myMapId` (number, optional): Custom map to scope to

**Returns:**
List of shares with category name and token.

---

### share_map_category

Share a favorite category via a public link. The category must already contain at least one
favorite, otherwise the server rejects the request.

**Parameters:**
- `category` (string, required): Category name to share

**Returns:**
The share, including its token.

**Example Usage:**
```
Ask Claude: "Share my 'Hiking' favorites as a public link"
```

---

### unshare_map_category

Remove the public link share from a favorite category.

**Parameters:**
- `category` (string, required): Category name to unshare

**Returns:**
Confirmation message, noting whether a share existed.

---

### add_shared_category_to_map

Add a shared favorite category to a custom map so its favorites show up there.

**Parameters:**
- `category` (string, required): Shared category name
- `targetMapId` (number, required): ID of the custom map to add the category to
- `myMapId` (number, optional): Custom map the share originates from

**Returns:**
Confirmation message.

---

## Devices & GPS

### list_map_devices

List GPS tracking devices registered in Nextcloud Maps.

**Parameters:**
- `myMapId` (number, optional): Custom map to scope to

**Returns:**
List of devices with IDs and metadata.

---

### get_map_device_points

Get GPS location points for a specific device. Supports time filtering and pagination.

**Parameters:**
- `id` (number, required): Device ID
- `pruneBefore` (number, optional): Unix timestamp — only return points after this time
- `limit` (number, optional): Maximum number of points to return (default 10000)
- `offset` (number, optional): Number of points to skip (default 0)

**Returns:**
List of location points with timestamps.

---

### add_map_device_point

Log a GPS location point for a device. The device is auto-created by user_agent if it doesn't exist.

**Parameters:**
- `lat` (number, required): Latitude
- `lng` (number, required): Longitude
- `timestamp` (number, optional): Unix timestamp (defaults to now)
- `user_agent` (string, optional): Device identifier
- `altitude` (number, optional): Altitude in meters
- `battery` (number, optional): Battery level percentage
- `accuracy` (number, optional): GPS accuracy in meters

**Returns:**
Device ID and point ID.

**Example Usage:**
```
Ask Claude: "Log a GPS point at 59.3293, 18.0686 for device 'my-phone'"
```

---

### update_map_device

Update a device's display color and/or name. At least one must be provided.

**Parameters:**
- `id` (number, required): Device ID
- `color` (string, optional): New color (e.g. `#ff0000`)
- `name` (string, optional): New device name (user agent)

**Returns:**
Updated device details.

---

### delete_map_device

Delete a GPS tracking device and all its location points. This action is irreversible.

**Parameters:**
- `id` (number, required): Device ID to delete

**Returns:**
Confirmation message.

---

### export_map_devices

Export device location data as a GPX file to the user's Nextcloud `/Maps` folder.

**Parameters:**
- `deviceIdList` (number[], required): Device IDs to export
- `begin` (number, optional): Start timestamp filter
- `end` (number, optional): End timestamp filter
- `all` (boolean, optional): If true, filename is not prefixed with `filtered-`

**Returns:**
File path of exported devices.

---

### import_map_devices

Import device location data from a file in Nextcloud storage. Supports GPX, KML, and KMZ.

**Parameters:**
- `path` (string, required): Relative path to the file in Nextcloud storage

**Returns:**
Number of imported device points.

---

## Device Sharing

### share_map_device

Share a GPS device via a public link, limited to a time window.

**Parameters:**
- `id` (number, required): Device ID to share
- `timestampFrom` (number, required): Unix timestamp — start of the shared window
- `timestampTo` (number, required): Unix timestamp — end of the shared window

**Returns:**
The share, including its token.

---

### list_shared_map_devices

List device shares that have been added to a custom map.

**Parameters:**
- `myMapId` (number, required): Custom map to list device shares from

**Returns:**
List of shares with tokens and time windows.

> The default store holds no device shares — this tool always needs a custom map ID.

---

### remove_map_device_share

Revoke a device share by its token.

**Parameters:**
- `token` (string, required): Share token to revoke

**Returns:**
Confirmation message.

---

### add_shared_device_to_map

Add a shared device (by token) to a custom map so its track shows up there.

**Parameters:**
- `token` (string, required): Device share token
- `targetMapId` (number, required): ID of the custom map to add the device to

**Returns:**
Confirmation message.

---

## Tracks

### list_map_tracks

List GPS tracks (GPX/KML files) from Nextcloud Maps.

**Parameters:**
- `myMapId` (number, optional): Custom map ID to scope to

**Returns:**
List of tracks with metadata.

---

### get_map_track

Get full details and content of a track by its ID. Returns metadata and the raw GPX/KML file content.

**Parameters:**
- `id` (number, required): Track ID

**Returns:**
Track metadata and raw content.

---

### update_map_track

Update a track's color or metadata.

**Parameters:**
- `id` (number, required): Track ID
- `color` (string, optional): New color
- `metadata` (string, optional): New metadata as JSON string
- `etag` (string, optional): ETag for concurrency control

**Returns:**
Confirmation message.

---

### export_map_route

Export a route or track as a GPX file to the user's Nextcloud `/Maps` folder.

**Parameters:**
- `name` (string, required): Route name (used as filename)
- `type` (enum, required): GPX structure type — `route` or `track`
- `coords` (object[], required): Ordered list of coordinates with `lat`/`lng`
- `totDist` (number, optional): Total distance (for metadata)
- `totTime` (number, optional): Total time (for metadata)
- `myMapId` (number, optional): Target map ID (defaults to `/Maps` folder)

**Returns:**
Track object with ID.

**Example Usage:**
```
Ask Claude: "Export a route called 'Morning Run' with these coordinates: [59.33, 18.07], [59.34, 18.08]"
```

---

## Photos

### list_map_photos

List geolocated photos (photos with GPS coordinates) from Nextcloud Maps.

**Parameters:**
- `myMapId` (number, optional): Custom map ID to scope to

**Returns:**
List of geolocated photos with file IDs and coordinates.

---

### list_map_photos_nonlocalized

List photos that don't have GPS coordinates. Useful for finding photos that can be placed on the map.

**Parameters:**
- `myMapId` (number, optional): Custom map ID to scope to
- `limit` (number, optional): Max photos to return (default 250)
- `offset` (number, optional): Pagination offset (default 0)
- `timezone` (string, optional): Timezone string

**Returns:**
List of non-localized photos.

---

### place_map_photos

Set GPS coordinates on one or more photos. Each photo path is paired with a lat/lng.

**Parameters:**
- `paths` (string[], required): File paths in Nextcloud storage
- `lats` (number[], required): Latitudes (parallel to paths)
- `lngs` (number[], required): Longitudes (parallel to paths)
- `myMapId` (number, optional): Custom map ID to scope to

**Returns:**
Confirmation message.

**Example Usage:**
```
Ask Claude: "Set the location of /Photos/beach.jpg to 59.33, 18.07"
```

---

### reset_map_photo_coords

Remove GPS coordinates from one or more photos.

**Parameters:**
- `paths` (string[], required): File paths in Nextcloud storage
- `myMapId` (number, optional): Custom map ID to scope to

**Returns:**
Confirmation message.

---

### get_map_photo_job_status

Get the status of the background job that scans photos for GPS coordinates. Useful after
uploading photos to see whether geolocation has finished.

**Parameters:**
None

**Returns:**
Key-value status reported by the job.

---

## Contacts

### list_map_contacts

List address book contacts that carry a geographic address, as shown on the map.

**Parameters:**
- `myMapId` (number, optional): Custom map to scope to

**Returns:**
List of contacts with name, address, coordinates, and the `BOOKID`/`URI` needed by the other
contact tools.

---

### search_map_contacts

Search address book contacts by name, to find the `bookid`/`uri`/`uid` needed to place a
contact on the map.

**Parameters:**
- `query` (string, required): Search term matched against contact display names

**Returns:**
List of matching contacts with UID, book ID, and URI.

---

### place_map_contact

Set a contact's geographic address and coordinates so it appears on the map.

**Parameters:**
- `bookid` (string, required): Address book ID
- `uri` (string, required): Contact URI (e.g. `abc123.vcf`)
- `uid` (string, required): Contact UID
- `lat` (number, optional): Latitude
- `lng` (number, optional): Longitude
- `address_string` (string, optional): Full address as one string, used instead of the fields below
- `attraction`, `house_number`, `road`, `postcode`, `city`, `state`, `country` (string, optional): Address parts
- `type` (string, optional): Address type (e.g. `HOME`, `WORK`)
- `myMapId` (number, optional): Custom map to scope to

**Returns:**
Confirmation message. A read-only address book or non-writable contact is returned as an error.

**Example Usage:**
```
Ask Claude: "Put Ada Lovelace's home address on the map at 52.52, 13.405"
```

---

### add_contact_to_map

Copy a contact into a custom map so it shows up as a pin on that map.

**Parameters:**
- `bookid` (string, required): Address book ID
- `uri` (string, required): Contact URI
- `myMapId` (number, required): ID of the custom map to add the contact to

**Returns:**
Confirmation message.

---

### delete_map_contact_address

Remove an address and its coordinates from a contact, taking it off the map. The contact
itself is kept.

**Parameters:**
- `bookid` (string, required): Address book ID
- `uri` (string, required): Contact URI
- `uid` (string, required): Contact UID
- `adr` (string, required): The `ADR` value to remove, exactly as returned by `list_map_contacts`
- `geo` (string, required): The `GEO` value to remove, exactly as returned by `list_map_contacts`
- `myMapId` (number, optional): Custom map to scope to

**Returns:**
Confirmation message.

---

## Custom Maps

### list_maps

List all custom maps created by the user in Nextcloud Maps.

**Parameters:**
None

**Returns:**
List of custom maps with IDs and names.

---

### create_map

Create a new custom map in Nextcloud Maps.

**Parameters:**
- `name` (string, optional): Map name (default: `New Map`)

**Returns:**
Created map with ID.

---

### update_map

Update a custom map's properties (e.g. rename).

**Parameters:**
- `id` (number, required): Map ID
- `values` (object, required): Key-value pairs to update

**Returns:**
Updated map details.

---

### delete_map

Delete a custom map. This action is irreversible.

**Parameters:**
- `id` (number, required): Map ID to delete

**Returns:**
Confirmation message.

---

## Workflow Examples

### Trip Planning
```
User: "Create a custom map called 'Summer Trip' and add my hotel and restaurant as favorites"
Claude: Creates map -> creates favorites with coordinates in that map's context
```

### Photo Geotagging
```
User: "Find photos without GPS data and set them all to my office location"
Claude: Lists non-localized photos -> places them at specified coordinates
```

### GPS Data Export
```
User: "Export my phone's GPS tracks from last week as GPX"
Claude: Lists devices -> exports device data with time range filter
```

## Development

To extend maps tools:
- See [Adding Tools Guide](../../development/adding-tools.md)
- Source code: [mcp-server/src/tools/apps/maps.ts](../../../../mcp-server/src/tools/apps/maps.ts)

## References

- [Nextcloud Maps App](https://apps.nextcloud.com/apps/maps)
- [GPX Format Specification](https://www.topografix.com/gpx.asp)
