import { z } from "zod";
import { fetchMapsExternalAPI, fetchMapsAPI } from "../../client/maps.js";

/**
 * Nextcloud Maps App Tools
 * Manages favorites, devices, tracks, photos, custom maps, routing, and import/export.
 */

// ── Interfaces ──────────────────────────────────────────────────────────────

interface Favorite {
  id: number;
  name: string;
  date_created: number;
  date_modified: number;
  lat: number;
  lng: number;
  category: string;
  comment: string;
  extensions: string;
}

interface Device {
  id: number;
  user_agent: string;
  color: string;
  isShareable?: boolean;
  isDeleteable?: boolean;
  isUpdateable?: boolean;
  isReadable?: boolean;
}

interface DevicePoint {
  id: number;
  lat: number;
  lng: number;
  timestamp: number;
  altitude?: number;
  battery?: number;
  accuracy?: number;
}

interface Track {
  id: number;
  file_id?: number;
  color?: string;
  metadata?: string;
  etag?: string;
  [key: string]: unknown;
}

interface TrackDetail {
  metadata: Record<string, unknown>;
  content: string;
}

interface Photo {
  fileId: number;
  lat: number;
  lng: number;
  dateTaken?: number;
  path?: string;
  [key: string]: unknown;
}

interface NonLocalizedPhoto {
  fileId: number;
  path: string;
  dateTaken?: number;
  [key: string]: unknown;
}

interface MyMap {
  id: number;
  [key: string]: unknown;
}

// ── Formatters ──────────────────────────────────────────────────────────────

function formatFavorite(f: Favorite): string {
  const lines = [`- **${f.name || "(unnamed)"}** (ID: ${f.id})`];
  lines.push(`  Coords: ${f.lat}, ${f.lng}`);
  if (f.category) lines.push(`  Category: ${f.category}`);
  if (f.comment) lines.push(`  Comment: ${f.comment}`);
  if (f.extensions) lines.push(`  Extensions: ${f.extensions}`);
  lines.push(`  Created: ${new Date(f.date_created * 1000).toISOString()}`);
  return lines.join("\n");
}

function formatDevice(d: Device): string {
  const lines = [`- **${d.user_agent || "(unknown)"}** (ID: ${d.id})`];
  if (d.color) lines.push(`  Color: ${d.color}`);
  return lines.join("\n");
}

function formatDevicePoint(p: DevicePoint): string {
  const parts = [`  ${p.lat}, ${p.lng}`];
  parts.push(`@ ${new Date(p.timestamp * 1000).toISOString()}`);
  if (p.altitude !== undefined) parts.push(`alt: ${p.altitude}m`);
  if (p.accuracy !== undefined) parts.push(`acc: ${p.accuracy}m`);
  if (p.battery !== undefined) parts.push(`bat: ${p.battery}%`);
  return `- ${parts.join(" | ")}`;
}

function formatTrack(t: Track): string {
  const lines = [`- Track ID: ${t.id}`];
  if (t.file_id) lines.push(`  File ID: ${t.file_id}`);
  if (t.color) lines.push(`  Color: ${t.color}`);
  return lines.join("\n");
}

function formatPhoto(p: Photo): string {
  const lines = [`- File ID: ${p.fileId} — ${p.lat}, ${p.lng}`];
  if (p.path) lines.push(`  Path: ${p.path}`);
  if (p.dateTaken) lines.push(`  Taken: ${new Date(p.dateTaken * 1000).toISOString()}`);
  return lines.join("\n");
}

function formatNonLocalizedPhoto(p: NonLocalizedPhoto): string {
  const lines = [`- File ID: ${p.fileId} — ${p.path}`];
  if (p.dateTaken) lines.push(`  Taken: ${new Date(p.dateTaken * 1000).toISOString()}`);
  return lines.join("\n");
}

function formatMyMap(m: MyMap): string {
  const entries = Object.entries(m)
    .filter(([k]) => k !== "id")
    .map(([k, v]) => `  ${k}: ${v}`);
  return [`- Map ID: ${m.id}`, ...entries].join("\n");
}

// ── Favorites Tools (External API) ──────────────────────────────────────────

export const listMapFavoritesTool = {
  name: "list_map_favorites",
  description:
    "List map favorites (saved locations/pins) from Nextcloud Maps. Optionally filter by modification time.",
  inputSchema: z.object({
    pruneBefore: z
      .number()
      .optional()
      .describe("Unix timestamp — only return favorites modified after this time"),
  }),
  handler: async (args: { pruneBefore?: number }) => {
    try {
      const queryParams: Record<string, string> = {};
      if (args.pruneBefore !== undefined) queryParams["pruneBefore"] = String(args.pruneBefore);

      const result = await fetchMapsExternalAPI<Favorite[]>("/favorites", { queryParams });

      if (result.length === 0) {
        return { content: [{ type: "text" as const, text: "No map favorites found." }] };
      }

      const formatted = result.map(formatFavorite).join("\n");
      return {
        content: [{ type: "text" as const, text: `Map favorites (${result.length}):\n\n${formatted}` }],
      };
    } catch (error) {
      return {
        content: [{ type: "text" as const, text: `Error listing map favorites: ${error instanceof Error ? error.message : String(error)}` }],
        isError: true,
      };
    }
  },
};

export const createMapFavoriteTool = {
  name: "create_map_favorite",
  description:
    "Create a new map favorite (saved location/pin) in Nextcloud Maps. Latitude and longitude are required.",
  inputSchema: z.object({
    name: z.string().optional().describe("Name of the location"),
    lat: z.number().describe("Latitude"),
    lng: z.number().describe("Longitude"),
    category: z.string().optional().describe("Category name (e.g. 'Restaurant', 'Home')"),
    comment: z.string().optional().describe("A comment or note"),
    extensions: z.string().optional().describe("Extra data as a string"),
  }),
  handler: async (args: {
    name?: string;
    lat: number;
    lng: number;
    category?: string;
    comment?: string;
    extensions?: string;
  }) => {
    try {
      const body: Record<string, unknown> = { lat: args.lat, lng: args.lng };
      if (args.name !== undefined) body.name = args.name;
      if (args.category !== undefined) body.category = args.category;
      if (args.comment !== undefined) body.comment = args.comment;
      if (args.extensions !== undefined) body.extensions = args.extensions;

      const result = await fetchMapsExternalAPI<Favorite>("/favorites", {
        method: "POST",
        body,
      });

      return {
        content: [{ type: "text" as const, text: `Favorite created (ID: ${result.id}).\n\n${formatFavorite(result)}` }],
      };
    } catch (error) {
      return {
        content: [{ type: "text" as const, text: `Error creating map favorite: ${error instanceof Error ? error.message : String(error)}` }],
        isError: true,
      };
    }
  },
};

export const updateMapFavoriteTool = {
  name: "update_map_favorite",
  description: "Update an existing map favorite. Only provided fields are changed.",
  inputSchema: z.object({
    id: z.number().describe("Favorite ID"),
    name: z.string().optional().describe("New name"),
    lat: z.number().optional().describe("New latitude"),
    lng: z.number().optional().describe("New longitude"),
    category: z.string().optional().describe("New category"),
    comment: z.string().optional().describe("New comment"),
    extensions: z.string().optional().describe("New extensions data"),
  }),
  handler: async (args: {
    id: number;
    name?: string;
    lat?: number;
    lng?: number;
    category?: string;
    comment?: string;
    extensions?: string;
  }) => {
    try {
      const body: Record<string, unknown> = {};
      if (args.name !== undefined) body.name = args.name;
      if (args.lat !== undefined) body.lat = args.lat;
      if (args.lng !== undefined) body.lng = args.lng;
      if (args.category !== undefined) body.category = args.category;
      if (args.comment !== undefined) body.comment = args.comment;
      if (args.extensions !== undefined) body.extensions = args.extensions;

      const result = await fetchMapsExternalAPI<Favorite>(`/favorites/${args.id}`, {
        method: "PUT",
        body,
      });

      return {
        content: [{ type: "text" as const, text: `Favorite ${args.id} updated.\n\n${formatFavorite(result)}` }],
      };
    } catch (error) {
      return {
        content: [{ type: "text" as const, text: `Error updating map favorite ${args.id}: ${error instanceof Error ? error.message : String(error)}` }],
        isError: true,
      };
    }
  },
};

export const deleteMapFavoriteTool = {
  name: "delete_map_favorite",
  description: "Delete a map favorite by its ID. This action is irreversible.",
  inputSchema: z.object({
    id: z.number().describe("Favorite ID to delete"),
  }),
  handler: async (args: { id: number }) => {
    try {
      await fetchMapsExternalAPI(`/favorites/${args.id}`, { method: "DELETE" });
      return {
        content: [{ type: "text" as const, text: `Favorite ${args.id} deleted.` }],
      };
    } catch (error) {
      return {
        content: [{ type: "text" as const, text: `Error deleting map favorite ${args.id}: ${error instanceof Error ? error.message : String(error)}` }],
        isError: true,
      };
    }
  },
};

// ── Devices Tools (External API) ────────────────────────────────────────────

export const listMapDevicesTool = {
  name: "list_map_devices",
  description: "List GPS tracking devices registered in Nextcloud Maps.",
  inputSchema: z.object({}),
  handler: async () => {
    try {
      const result = await fetchMapsExternalAPI<Device[]>("/devices");

      if (result.length === 0) {
        return { content: [{ type: "text" as const, text: "No map devices found." }] };
      }

      const formatted = result.map(formatDevice).join("\n");
      return {
        content: [{ type: "text" as const, text: `Map devices (${result.length}):\n\n${formatted}` }],
      };
    } catch (error) {
      return {
        content: [{ type: "text" as const, text: `Error listing map devices: ${error instanceof Error ? error.message : String(error)}` }],
        isError: true,
      };
    }
  },
};

export const getMapDevicePointsTool = {
  name: "get_map_device_points",
  description:
    "Get GPS location points for a specific device. Supports time filtering and pagination.",
  inputSchema: z.object({
    id: z.number().describe("Device ID"),
    pruneBefore: z
      .number()
      .optional()
      .describe("Unix timestamp — only return points after this time"),
  }),
  handler: async (args: { id: number; pruneBefore?: number }) => {
    try {
      const queryParams: Record<string, string> = {};
      if (args.pruneBefore !== undefined) queryParams["pruneBefore"] = String(args.pruneBefore);

      const result = await fetchMapsExternalAPI<DevicePoint[]>(`/devices/${args.id}`, {
        queryParams,
      });

      if (result.length === 0) {
        return { content: [{ type: "text" as const, text: `No points found for device ${args.id}.` }] };
      }

      const formatted = result.map(formatDevicePoint).join("\n");
      return {
        content: [{ type: "text" as const, text: `Device ${args.id} points (${result.length}):\n\n${formatted}` }],
      };
    } catch (error) {
      return {
        content: [{ type: "text" as const, text: `Error getting device points: ${error instanceof Error ? error.message : String(error)}` }],
        isError: true,
      };
    }
  },
};

export const addMapDevicePointTool = {
  name: "add_map_device_point",
  description:
    "Log a GPS location point for a device. The device is auto-created by user_agent if it doesn't exist.",
  inputSchema: z.object({
    lat: z.number().describe("Latitude"),
    lng: z.number().describe("Longitude"),
    timestamp: z.number().optional().describe("Unix timestamp (defaults to now)"),
    user_agent: z
      .string()
      .optional()
      .describe("Device identifier (defaults to HTTP User-Agent)"),
    altitude: z.number().optional().describe("Altitude in meters"),
    battery: z.number().optional().describe("Battery level percentage"),
    accuracy: z.number().optional().describe("GPS accuracy in meters"),
  }),
  handler: async (args: {
    lat: number;
    lng: number;
    timestamp?: number;
    user_agent?: string;
    altitude?: number;
    battery?: number;
    accuracy?: number;
  }) => {
    try {
      const body: Record<string, unknown> = { lat: args.lat, lng: args.lng };
      if (args.timestamp !== undefined) body.timestamp = args.timestamp;
      if (args.user_agent !== undefined) body.user_agent = args.user_agent;
      if (args.altitude !== undefined) body.altitude = args.altitude;
      if (args.battery !== undefined) body.battery = args.battery;
      if (args.accuracy !== undefined) body.accuracy = args.accuracy;

      const result = await fetchMapsExternalAPI<{ deviceId: number; pointId: number }>(
        "/devices",
        { method: "POST", body }
      );

      return {
        content: [
          {
            type: "text" as const,
            text: `Point logged — device ID: ${result.deviceId}, point ID: ${result.pointId}.`,
          },
        ],
      };
    } catch (error) {
      return {
        content: [{ type: "text" as const, text: `Error adding device point: ${error instanceof Error ? error.message : String(error)}` }],
        isError: true,
      };
    }
  },
};

export const updateMapDeviceTool = {
  name: "update_map_device",
  description: "Update a device's display color.",
  inputSchema: z.object({
    id: z.number().describe("Device ID"),
    color: z.string().describe("New color (e.g. '#ff0000')"),
  }),
  handler: async (args: { id: number; color: string }) => {
    try {
      const result = await fetchMapsExternalAPI<Device>(`/devices/${args.id}`, {
        method: "PUT",
        body: { color: args.color },
      });

      return {
        content: [{ type: "text" as const, text: `Device ${args.id} updated.\n\n${formatDevice(result)}` }],
      };
    } catch (error) {
      return {
        content: [{ type: "text" as const, text: `Error updating device ${args.id}: ${error instanceof Error ? error.message : String(error)}` }],
        isError: true,
      };
    }
  },
};

export const deleteMapDeviceTool = {
  name: "delete_map_device",
  description:
    "Delete a GPS tracking device and all its location points. This action is irreversible.",
  inputSchema: z.object({
    id: z.number().describe("Device ID to delete"),
  }),
  handler: async (args: { id: number }) => {
    try {
      await fetchMapsExternalAPI(`/devices/${args.id}`, { method: "DELETE" });
      return {
        content: [{ type: "text" as const, text: `Device ${args.id} deleted.` }],
      };
    } catch (error) {
      return {
        content: [{ type: "text" as const, text: `Error deleting device ${args.id}: ${error instanceof Error ? error.message : String(error)}` }],
        isError: true,
      };
    }
  },
};

// ── Tracks Tools (Internal API) ─────────────────────────────────────────────

export const listMapTracksTool = {
  name: "list_map_tracks",
  description: "List GPS tracks (GPX/KML files) from Nextcloud Maps.",
  inputSchema: z.object({
    myMapId: z.number().optional().describe("Custom map ID to scope to"),
  }),
  handler: async (args: { myMapId?: number }) => {
    try {
      const queryParams: Record<string, string> = {};
      if (args.myMapId !== undefined) queryParams["myMapId"] = String(args.myMapId);

      const result = await fetchMapsAPI<Track[]>("/tracks", { queryParams });

      if (result.length === 0) {
        return { content: [{ type: "text" as const, text: "No tracks found." }] };
      }

      const formatted = result.map(formatTrack).join("\n");
      return {
        content: [{ type: "text" as const, text: `Tracks (${result.length}):\n\n${formatted}` }],
      };
    } catch (error) {
      return {
        content: [{ type: "text" as const, text: `Error listing tracks: ${error instanceof Error ? error.message : String(error)}` }],
        isError: true,
      };
    }
  },
};

export const getMapTrackTool = {
  name: "get_map_track",
  description:
    "Get full details and content of a track by its ID. Returns metadata and the raw GPX/KML file content.",
  inputSchema: z.object({
    id: z.number().describe("Track ID"),
  }),
  handler: async (args: { id: number }) => {
    try {
      const result = await fetchMapsAPI<TrackDetail>(`/tracks/${args.id}`);

      const lines = [`# Track ${args.id}`, ""];
      if (result.metadata) {
        lines.push("## Metadata");
        for (const [k, v] of Object.entries(result.metadata)) {
          lines.push(`- ${k}: ${JSON.stringify(v)}`);
        }
        lines.push("");
      }
      lines.push("## Content");
      lines.push("```xml");
      lines.push(result.content);
      lines.push("```");

      return { content: [{ type: "text" as const, text: lines.join("\n") }] };
    } catch (error) {
      return {
        content: [{ type: "text" as const, text: `Error getting track ${args.id}: ${error instanceof Error ? error.message : String(error)}` }],
        isError: true,
      };
    }
  },
};

export const updateMapTrackTool = {
  name: "update_map_track",
  description: "Update a track's color or metadata.",
  inputSchema: z.object({
    id: z.number().describe("Track ID"),
    color: z.string().optional().describe("New color (e.g. '#00ff00')"),
    metadata: z.string().optional().describe("New metadata as JSON string"),
    etag: z.string().optional().describe("ETag for concurrency control"),
  }),
  handler: async (args: { id: number; color?: string; metadata?: string; etag?: string }) => {
    try {
      const body: Record<string, unknown> = {};
      if (args.color !== undefined) body.color = args.color;
      if (args.metadata !== undefined) body.metadata = args.metadata;
      if (args.etag !== undefined) body.etag = args.etag;

      await fetchMapsAPI(`/tracks/${args.id}`, { method: "PUT", body });

      return {
        content: [{ type: "text" as const, text: `Track ${args.id} updated.` }],
      };
    } catch (error) {
      return {
        content: [{ type: "text" as const, text: `Error updating track ${args.id}: ${error instanceof Error ? error.message : String(error)}` }],
        isError: true,
      };
    }
  },
};

// ── Photos Tools (Internal API) ─────────────────────────────────────────────

export const listMapPhotosTool = {
  name: "list_map_photos",
  description: "List geolocated photos (photos with GPS coordinates) from Nextcloud Maps.",
  inputSchema: z.object({
    myMapId: z.number().optional().describe("Custom map ID to scope to"),
  }),
  handler: async (args: { myMapId?: number }) => {
    try {
      const queryParams: Record<string, string> = {};
      if (args.myMapId !== undefined) queryParams["myMapId"] = String(args.myMapId);

      const result = await fetchMapsAPI<Photo[]>("/photos", { queryParams });

      if (result.length === 0) {
        return { content: [{ type: "text" as const, text: "No geolocated photos found." }] };
      }

      const formatted = result.map(formatPhoto).join("\n");
      return {
        content: [{ type: "text" as const, text: `Geolocated photos (${result.length}):\n\n${formatted}` }],
      };
    } catch (error) {
      return {
        content: [{ type: "text" as const, text: `Error listing photos: ${error instanceof Error ? error.message : String(error)}` }],
        isError: true,
      };
    }
  },
};

export const listMapPhotosNonlocalizedTool = {
  name: "list_map_photos_nonlocalized",
  description:
    "List photos that don't have GPS coordinates. Useful for finding photos that can be placed on the map.",
  inputSchema: z.object({
    myMapId: z.number().optional().describe("Custom map ID to scope to"),
    limit: z.number().optional().describe("Max photos to return (default 250)"),
    offset: z.number().optional().describe("Pagination offset (default 0)"),
    timezone: z.string().optional().describe("Timezone string (e.g. 'Europe/Berlin')"),
  }),
  handler: async (args: {
    myMapId?: number;
    limit?: number;
    offset?: number;
    timezone?: string;
  }) => {
    try {
      const queryParams: Record<string, string> = {};
      if (args.myMapId !== undefined) queryParams["myMapId"] = String(args.myMapId);
      if (args.limit !== undefined) queryParams["limit"] = String(args.limit);
      if (args.offset !== undefined) queryParams["offset"] = String(args.offset);
      if (args.timezone !== undefined) queryParams["timezone"] = args.timezone;

      const result = await fetchMapsAPI<NonLocalizedPhoto[]>("/photos/nonlocalized", {
        queryParams,
      });

      if (result.length === 0) {
        return { content: [{ type: "text" as const, text: "No non-localized photos found." }] };
      }

      const formatted = result.map(formatNonLocalizedPhoto).join("\n");
      return {
        content: [
          {
            type: "text" as const,
            text: `Non-localized photos (${result.length}):\n\n${formatted}`,
          },
        ],
      };
    } catch (error) {
      return {
        content: [{ type: "text" as const, text: `Error listing non-localized photos: ${error instanceof Error ? error.message : String(error)}` }],
        isError: true,
      };
    }
  },
};

export const placeMapPhotosTool = {
  name: "place_map_photos",
  description:
    "Set GPS coordinates on one or more photos. Each photo path is paired with a lat/lng.",
  inputSchema: z.object({
    paths: z.array(z.string()).describe("File paths in Nextcloud storage"),
    lats: z.array(z.number()).describe("Latitudes (parallel to paths)"),
    lngs: z.array(z.number()).describe("Longitudes (parallel to paths)"),
    myMapId: z.number().optional().describe("Custom map ID to scope to"),
  }),
  handler: async (args: {
    paths: string[];
    lats: number[];
    lngs: number[];
    myMapId?: number;
  }) => {
    try {
      const body: Record<string, unknown> = {
        paths: args.paths,
        lats: args.lats,
        lngs: args.lngs,
      };
      if (args.myMapId !== undefined) body.myMapId = args.myMapId;

      await fetchMapsAPI("/photos", { method: "POST", body });

      return {
        content: [
          { type: "text" as const, text: `Coordinates set on ${args.paths.length} photo(s).` },
        ],
      };
    } catch (error) {
      return {
        content: [{ type: "text" as const, text: `Error placing photos: ${error instanceof Error ? error.message : String(error)}` }],
        isError: true,
      };
    }
  },
};

export const resetMapPhotoCoordsTool = {
  name: "reset_map_photo_coords",
  description: "Remove GPS coordinates from one or more photos.",
  inputSchema: z.object({
    paths: z.array(z.string()).describe("File paths in Nextcloud storage"),
    myMapId: z.number().optional().describe("Custom map ID to scope to"),
  }),
  handler: async (args: { paths: string[]; myMapId?: number }) => {
    try {
      const body: Record<string, unknown> = { paths: args.paths };
      if (args.myMapId !== undefined) body.myMapId = args.myMapId;

      await fetchMapsAPI("/photos", { method: "DELETE", body });

      return {
        content: [
          {
            type: "text" as const,
            text: `Coordinates removed from ${args.paths.length} photo(s).`,
          },
        ],
      };
    } catch (error) {
      return {
        content: [{ type: "text" as const, text: `Error resetting photo coordinates: ${error instanceof Error ? error.message : String(error)}` }],
        isError: true,
      };
    }
  },
};

// ── My Maps Tools (Internal API) ────────────────────────────────────────────

export const listMapsTool = {
  name: "list_maps",
  description: "List all custom maps created by the user in Nextcloud Maps.",
  inputSchema: z.object({}),
  handler: async () => {
    try {
      const result = await fetchMapsAPI<MyMap[]>("/maps");

      if (result.length === 0) {
        return { content: [{ type: "text" as const, text: "No custom maps found." }] };
      }

      const formatted = result.map(formatMyMap).join("\n");
      return {
        content: [{ type: "text" as const, text: `Custom maps (${result.length}):\n\n${formatted}` }],
      };
    } catch (error) {
      return {
        content: [{ type: "text" as const, text: `Error listing maps: ${error instanceof Error ? error.message : String(error)}` }],
        isError: true,
      };
    }
  },
};

export const createMapTool = {
  name: "create_map",
  description: "Create a new custom map in Nextcloud Maps.",
  inputSchema: z.object({
    name: z.string().optional().describe("Map name (default: 'New Map')"),
  }),
  handler: async (args: { name?: string }) => {
    try {
      const body: Record<string, unknown> = { values: { newName: args.name || "New Map" } };

      const result = await fetchMapsAPI<MyMap>("/maps", { method: "POST", body });

      return {
        content: [{ type: "text" as const, text: `Map created (ID: ${result.id}).\n\n${formatMyMap(result)}` }],
      };
    } catch (error) {
      return {
        content: [{ type: "text" as const, text: `Error creating map: ${error instanceof Error ? error.message : String(error)}` }],
        isError: true,
      };
    }
  },
};

export const updateMapTool = {
  name: "update_map",
  description: "Update a custom map's properties (e.g. rename).",
  inputSchema: z.object({
    id: z.number().describe("Map ID"),
    values: z
      .record(z.unknown())
      .describe("Key-value pairs to update (e.g. { newName: 'My Trip' })"),
  }),
  handler: async (args: { id: number; values: Record<string, unknown> }) => {
    try {
      const result = await fetchMapsAPI<MyMap>(`/maps/${args.id}`, {
        method: "PUT",
        body: { values: args.values },
      });

      return {
        content: [{ type: "text" as const, text: `Map ${args.id} updated.\n\n${formatMyMap(result)}` }],
      };
    } catch (error) {
      return {
        content: [{ type: "text" as const, text: `Error updating map ${args.id}: ${error instanceof Error ? error.message : String(error)}` }],
        isError: true,
      };
    }
  },
};

export const deleteMapTool = {
  name: "delete_map",
  description: "Delete a custom map. This action is irreversible.",
  inputSchema: z.object({
    id: z.number().describe("Map ID to delete"),
  }),
  handler: async (args: { id: number }) => {
    try {
      await fetchMapsAPI(`/maps/${args.id}`, { method: "DELETE" });
      return {
        content: [{ type: "text" as const, text: `Map ${args.id} deleted.` }],
      };
    } catch (error) {
      return {
        content: [{ type: "text" as const, text: `Error deleting map ${args.id}: ${error instanceof Error ? error.message : String(error)}` }],
        isError: true,
      };
    }
  },
};

// ── Routing Tool (Internal API) ─────────────────────────────────────────────

export const exportMapRouteTool = {
  name: "export_map_route",
  description:
    "Export a route or track as a GPX file to the user's Nextcloud /Maps folder.",
  inputSchema: z.object({
    name: z.string().describe("Route name (used as filename)"),
    type: z
      .enum(["route", "track"])
      .describe("GPX structure type: 'route' (<rte>) or 'track' (<trk>)"),
    coords: z
      .array(
        z.object({
          lat: z.number().describe("Latitude"),
          lng: z.number().describe("Longitude"),
        })
      )
      .describe("Ordered list of coordinates"),
    totDist: z.number().optional().describe("Total distance (for metadata)"),
    totTime: z.number().optional().describe("Total time (for metadata)"),
    myMapId: z.number().optional().describe("Target map ID (defaults to /Maps folder)"),
  }),
  handler: async (args: {
    name: string;
    type: string;
    coords: Array<{ lat: number; lng: number }>;
    totDist?: number;
    totTime?: number;
    myMapId?: number;
  }) => {
    try {
      const body: Record<string, unknown> = {
        name: args.name,
        type: args.type,
        coords: args.coords,
      };
      if (args.totDist !== undefined) body.totDist = args.totDist;
      if (args.totTime !== undefined) body.totTime = args.totTime;
      if (args.myMapId !== undefined) body.myMapId = args.myMapId;

      const result = await fetchMapsAPI<Track>("/exportRoute", {
        method: "POST",
        body,
      });

      return {
        content: [
          {
            type: "text" as const,
            text: `Route "${args.name}" exported as GPX.\n\n${formatTrack(result)}`,
          },
        ],
      };
    } catch (error) {
      return {
        content: [{ type: "text" as const, text: `Error exporting route: ${error instanceof Error ? error.message : String(error)}` }],
        isError: true,
      };
    }
  },
};

// ── Import/Export Tools (Internal API) ───────────────────────────────────────

export const exportMapFavoritesTool = {
  name: "export_map_favorites",
  description:
    "Export favorites as a GPX file to the user's Nextcloud /Maps folder. Filter by categories and time range.",
  inputSchema: z.object({
    categoryList: z
      .array(z.string())
      .describe("Categories to export (e.g. ['Restaurant', 'Home'])"),
    begin: z.number().optional().describe("Start timestamp filter"),
    end: z.number().optional().describe("End timestamp filter"),
    all: z
      .boolean()
      .optional()
      .describe("If true, filename is not prefixed with 'filtered-'"),
  }),
  handler: async (args: {
    categoryList: string[];
    begin?: number;
    end?: number;
    all?: boolean;
  }) => {
    try {
      const body: Record<string, unknown> = { categoryList: args.categoryList };
      if (args.begin !== undefined) body.begin = args.begin;
      if (args.end !== undefined) body.end = args.end;
      if (args.all !== undefined) body.all = args.all;

      const result = await fetchMapsAPI<string>("/export/favorites", {
        method: "POST",
        body,
      });

      return {
        content: [{ type: "text" as const, text: `Favorites exported to: ${result}` }],
      };
    } catch (error) {
      return {
        content: [{ type: "text" as const, text: `Error exporting favorites: ${error instanceof Error ? error.message : String(error)}` }],
        isError: true,
      };
    }
  },
};

export const importMapFavoritesTool = {
  name: "import_map_favorites",
  description:
    "Import favorites from a file in Nextcloud storage. Supports GPX, KML, KMZ, JSON, and GeoJSON.",
  inputSchema: z.object({
    path: z
      .string()
      .describe("Relative path to the file in Nextcloud storage (e.g. '/Maps/favorites.gpx')"),
  }),
  handler: async (args: { path: string }) => {
    try {
      const result = await fetchMapsAPI<unknown>("/import/favorites", {
        method: "POST",
        body: { path: args.path },
      });

      return {
        content: [{ type: "text" as const, text: `Favorites imported from ${args.path}.\n\n${JSON.stringify(result, null, 2)}` }],
      };
    } catch (error) {
      return {
        content: [{ type: "text" as const, text: `Error importing favorites: ${error instanceof Error ? error.message : String(error)}` }],
        isError: true,
      };
    }
  },
};

export const exportMapDevicesTool = {
  name: "export_map_devices",
  description:
    "Export device location data as a GPX file to the user's Nextcloud /Maps folder.",
  inputSchema: z.object({
    deviceIdList: z.array(z.number()).describe("Device IDs to export"),
    begin: z.number().optional().describe("Start timestamp filter"),
    end: z.number().optional().describe("End timestamp filter"),
    all: z
      .boolean()
      .optional()
      .describe("If true, filename is not prefixed with 'filtered-'"),
  }),
  handler: async (args: {
    deviceIdList: number[];
    begin?: number;
    end?: number;
    all?: boolean;
  }) => {
    try {
      const body: Record<string, unknown> = { deviceIdList: args.deviceIdList };
      if (args.begin !== undefined) body.begin = args.begin;
      if (args.end !== undefined) body.end = args.end;
      if (args.all !== undefined) body.all = args.all;

      const result = await fetchMapsAPI<string>("/export/devices", {
        method: "POST",
        body,
      });

      return {
        content: [{ type: "text" as const, text: `Devices exported to: ${result}` }],
      };
    } catch (error) {
      return {
        content: [{ type: "text" as const, text: `Error exporting devices: ${error instanceof Error ? error.message : String(error)}` }],
        isError: true,
      };
    }
  },
};

export const importMapDevicesTool = {
  name: "import_map_devices",
  description:
    "Import device location data from a file in Nextcloud storage. Supports GPX, KML, and KMZ.",
  inputSchema: z.object({
    path: z
      .string()
      .describe("Relative path to the file in Nextcloud storage (e.g. '/Maps/track.gpx')"),
  }),
  handler: async (args: { path: string }) => {
    try {
      const result = await fetchMapsAPI<number>("/import/devices", {
        method: "POST",
        body: { path: args.path },
      });

      return {
        content: [{ type: "text" as const, text: `Imported ${result} device point(s) from ${args.path}.` }],
      };
    } catch (error) {
      return {
        content: [{ type: "text" as const, text: `Error importing devices: ${error instanceof Error ? error.message : String(error)}` }],
        isError: true,
      };
    }
  },
};

// ── Export ───────────────────────────────────────────────────────────────────

export const mapsTools = [
  // Favorites
  listMapFavoritesTool,
  createMapFavoriteTool,
  updateMapFavoriteTool,
  deleteMapFavoriteTool,
  // Devices
  listMapDevicesTool,
  getMapDevicePointsTool,
  addMapDevicePointTool,
  updateMapDeviceTool,
  deleteMapDeviceTool,
  // Tracks
  listMapTracksTool,
  getMapTrackTool,
  updateMapTrackTool,
  // Photos
  listMapPhotosTool,
  listMapPhotosNonlocalizedTool,
  placeMapPhotosTool,
  resetMapPhotoCoordsTool,
  // My Maps
  listMapsTool,
  createMapTool,
  updateMapTool,
  deleteMapTool,
  // Routing
  exportMapRouteTool,
  // Import/Export
  exportMapFavoritesTool,
  importMapFavoritesTool,
  exportMapDevicesTool,
  importMapDevicesTool,
];
