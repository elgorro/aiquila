// SPDX-License-Identifier: MIT

import { z } from 'zod';
import { fetchMapsAPI } from '../../client/maps.js';
import { handleAppError } from '../error-utils.js';

/**
 * Nextcloud Maps App Tools
 * Manages favorites, favorite/device sharing, devices, tracks, photos, contacts,
 * custom maps, routing, and import/export.
 *
 * Verified against Nextcloud Maps 1.8.0.
 */

// ── Shared helpers ──────────────────────────────────────────────────────────

/**
 * Most Maps controller methods accept an optional myMapId to operate on a custom
 * "My Map" (a folder holding JSON files) instead of the user's default DB store.
 */
const MY_MAP_ID_SCHEMA = z
  .number()
  .optional()
  .describe("ID of a custom map to scope the operation to; omit for the user's default map");

function myMapIdQuery(myMapId?: number): Record<string, string> {
  return myMapId === undefined ? {} : { myMapId: String(myMapId) };
}

function errorResult(what: string, error: unknown) {
  return handleAppError(error, `Error ${what}`);
}

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
  // Favorites stored in a custom map come back with extensions as an array.
  extensions: string | string[] | null;
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

interface FavoriteShare {
  id?: number;
  token: string;
  category: string;
  owner?: string;
  [key: string]: unknown;
}

interface DeviceShare {
  id?: number;
  token: string;
  deviceId?: number;
  device_id?: number;
  timestampFrom?: number;
  timestampTo?: number;
  [key: string]: unknown;
}

interface Contact {
  FN: string;
  URI: string;
  UID: string;
  BOOKID: number | string;
  BOOKURI?: string;
  ADR?: string;
  // The Contacts app returns ADRTYPE as an array of types.
  ADRTYPE?: string | string[];
  GEO?: string;
  GROUPS?: string;
  READONLY?: boolean | string;
  [key: string]: unknown;
}

// ── Formatters ──────────────────────────────────────────────────────────────

function formatFavorite(f: Favorite): string {
  const lines = [`- **${f.name || '(unnamed)'}** (ID: ${f.id})`];
  lines.push(`  Coords: ${f.lat}, ${f.lng}`);
  if (f.category) lines.push(`  Category: ${f.category}`);
  if (f.comment) lines.push(`  Comment: ${f.comment}`);
  const extensions = Array.isArray(f.extensions) ? f.extensions.join(', ') : f.extensions;
  if (extensions) lines.push(`  Extensions: ${extensions}`);
  lines.push(`  Created: ${new Date(f.date_created * 1000).toISOString()}`);
  return lines.join('\n');
}

function formatDevice(d: Device): string {
  const lines = [`- **${d.user_agent || '(unknown)'}** (ID: ${d.id})`];
  if (d.color) lines.push(`  Color: ${d.color}`);
  return lines.join('\n');
}

function formatDevicePoint(p: DevicePoint): string {
  const parts = [`  ${p.lat}, ${p.lng}`];
  parts.push(`@ ${new Date(p.timestamp * 1000).toISOString()}`);
  if (p.altitude != null) parts.push(`alt: ${p.altitude}m`);
  if (p.accuracy != null) parts.push(`acc: ${p.accuracy}m`);
  if (p.battery != null) parts.push(`bat: ${p.battery}%`);
  return `- ${parts.join(' | ')}`;
}

function formatTrack(t: Track): string {
  const lines = [`- Track ID: ${t.id}`];
  if (t.file_id) lines.push(`  File ID: ${t.file_id}`);
  if (t.color) lines.push(`  Color: ${t.color}`);
  return lines.join('\n');
}

function formatPhoto(p: Photo): string {
  const lines = [`- File ID: ${p.fileId} — ${p.lat}, ${p.lng}`];
  if (p.path) lines.push(`  Path: ${p.path}`);
  if (p.dateTaken) lines.push(`  Taken: ${new Date(p.dateTaken * 1000).toISOString()}`);
  return lines.join('\n');
}

function formatNonLocalizedPhoto(p: NonLocalizedPhoto): string {
  const lines = [`- File ID: ${p.fileId} — ${p.path}`];
  if (p.dateTaken) lines.push(`  Taken: ${new Date(p.dateTaken * 1000).toISOString()}`);
  return lines.join('\n');
}

function formatFavoriteShare(s: FavoriteShare): string {
  const lines = [`- **${s.category}**`];
  lines.push(`  Token: ${s.token}`);
  if (s.owner) lines.push(`  Owner: ${s.owner}`);
  return lines.join('\n');
}

function formatDeviceShare(s: DeviceShare): string {
  const deviceId = s.deviceId ?? s.device_id;
  const lines = [`- Share token: ${s.token}`];
  if (deviceId !== undefined) lines.push(`  Device ID: ${deviceId}`);
  if (s.timestampFrom !== undefined) {
    lines.push(`  From: ${new Date(s.timestampFrom * 1000).toISOString()}`);
  }
  if (s.timestampTo !== undefined) {
    lines.push(`  To: ${new Date(s.timestampTo * 1000).toISOString()}`);
  }
  return lines.join('\n');
}

function formatContact(c: Contact): string {
  const lines = [`- **${c.FN || '(unnamed)'}**`];
  lines.push(`  Book ID: ${c.BOOKID} | URI: ${c.URI}`);
  if (c.GEO) lines.push(`  Coords: ${c.GEO}`);
  const adrType = Array.isArray(c.ADRTYPE) ? c.ADRTYPE.join('/') : c.ADRTYPE;
  if (c.ADR) lines.push(`  Address: ${c.ADR}${adrType ? ` (${adrType})` : ''}`);
  if (c.GROUPS) lines.push(`  Groups: ${c.GROUPS}`);
  return lines.join('\n');
}

function formatMyMap(m: MyMap): string {
  const entries = Object.entries(m)
    .filter(([k]) => k !== 'id')
    .map(([k, v]) => `  ${k}: ${typeof v === 'object' && v !== null ? JSON.stringify(v) : v}`);
  return [`- Map ID: ${m.id}`, ...entries].join('\n');
}

// ── Favorites Tools ─────────────────────────────────────────────────────────

export const listMapFavoritesTool = {
  name: 'list_map_favorites',
  title: 'List Map Favorites',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description:
    'List map favorites (saved locations/pins) from Nextcloud Maps. Optionally filter by modification time or scope to a custom map.',
  inputSchema: z.object({
    pruneBefore: z
      .number()
      .optional()
      .describe('Unix timestamp — only return favorites modified after this time'),
    myMapId: MY_MAP_ID_SCHEMA,
  }),
  handler: async (args: { pruneBefore?: number; myMapId?: number }) => {
    try {
      let result = await fetchMapsAPI<Favorite[]>('/favorites', {
        queryParams: myMapIdQuery(args.myMapId),
      });

      if (args.pruneBefore !== undefined) {
        const cutoff = args.pruneBefore;
        result = result.filter((f) => (f.date_modified ?? 0) > cutoff);
      }

      if (result.length === 0) {
        return { content: [{ type: 'text' as const, text: 'No map favorites found.' }] };
      }

      const formatted = result.map(formatFavorite).join('\n');
      return {
        content: [
          { type: 'text' as const, text: `Map favorites (${result.length}):\n\n${formatted}` },
        ],
      };
    } catch (error) {
      return errorResult('listing map favorites', error);
    }
  },
};

export const createMapFavoriteTool = {
  name: 'create_map_favorite',
  title: 'Create Map Favorite',
  annotations: {
    readOnlyHint: false,
    destructiveHint: false,
    idempotentHint: false,
    openWorldHint: false,
  },
  description:
    'Create a new map favorite (saved location/pin) in Nextcloud Maps. Latitude and longitude are required.',
  inputSchema: z.object({
    name: z.string().optional().describe('Name of the location'),
    lat: z.number().describe('Latitude'),
    lng: z.number().describe('Longitude'),
    category: z.string().optional().describe("Category name (e.g. 'Restaurant', 'Home')"),
    comment: z.string().optional().describe('A comment or note'),
    extensions: z.string().optional().describe('Extra data as a string'),
    myMapId: MY_MAP_ID_SCHEMA,
  }),
  handler: async (args: {
    name?: string;
    lat: number;
    lng: number;
    category?: string;
    comment?: string;
    extensions?: string;
    myMapId?: number;
  }) => {
    try {
      const body: Record<string, unknown> = { lat: args.lat, lng: args.lng };
      if (args.name !== undefined) body.name = args.name;
      if (args.category !== undefined) body.category = args.category;
      if (args.comment !== undefined) body.comment = args.comment;
      if (args.extensions !== undefined) body.extensions = args.extensions;
      if (args.myMapId !== undefined) body.myMapId = args.myMapId;

      // Note: the create route is /favorite (singular); /favorites is the batch route.
      const result = await fetchMapsAPI<Favorite>('/favorite', {
        method: 'POST',
        body,
      });

      return {
        content: [
          {
            type: 'text' as const,
            text: `Favorite created (ID: ${result.id}).\n\n${formatFavorite(result)}`,
          },
        ],
      };
    } catch (error) {
      return errorResult('creating map favorite', error);
    }
  },
};

export const updateMapFavoriteTool = {
  name: 'update_map_favorite',
  title: 'Update Map Favorite',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: false,
  },
  description: 'Update an existing map favorite. Only provided fields are changed.',
  inputSchema: z.object({
    id: z.number().describe('Favorite ID'),
    name: z.string().optional().describe('New name'),
    lat: z.number().optional().describe('New latitude'),
    lng: z.number().optional().describe('New longitude'),
    category: z.string().optional().describe('New category'),
    comment: z.string().optional().describe('New comment'),
    extensions: z.string().optional().describe('New extensions data'),
    myMapId: MY_MAP_ID_SCHEMA,
  }),
  handler: async (args: {
    id: number;
    name?: string;
    lat?: number;
    lng?: number;
    category?: string;
    comment?: string;
    extensions?: string;
    myMapId?: number;
  }) => {
    try {
      // The controller types lat/lng as non-nullable floats, so they must always be
      // sent — look up the current coordinates when the caller omits either one.
      let lat = args.lat;
      let lng = args.lng;
      if (lat === undefined || lng === undefined) {
        const existing = await fetchMapsAPI<Favorite[]>('/favorites', {
          queryParams: myMapIdQuery(args.myMapId),
        });
        const current = existing.find((f) => f.id === args.id);
        if (!current) {
          throw new Error(`No favorite with ID ${args.id}`);
        }
        lat = lat ?? current.lat;
        lng = lng ?? current.lng;
      }

      const body: Record<string, unknown> = { lat, lng };
      if (args.name !== undefined) body.name = args.name;
      if (args.category !== undefined) body.category = args.category;
      if (args.comment !== undefined) body.comment = args.comment;
      if (args.extensions !== undefined) body.extensions = args.extensions;
      if (args.myMapId !== undefined) body.myMapId = args.myMapId;

      const result = await fetchMapsAPI<Favorite>(`/favorites/${args.id}`, {
        method: 'PUT',
        body,
      });

      return {
        content: [
          {
            type: 'text' as const,
            text: `Favorite ${args.id} updated.\n\n${formatFavorite(result)}`,
          },
        ],
      };
    } catch (error) {
      return errorResult(`updating map favorite ${args.id}`, error);
    }
  },
};

export const deleteMapFavoriteTool = {
  name: 'delete_map_favorite',
  title: 'Delete Map Favorite',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: false,
  },
  description: 'Delete a map favorite by its ID. This action is irreversible.',
  inputSchema: z.object({
    id: z.number().describe('Favorite ID to delete'),
    myMapId: MY_MAP_ID_SCHEMA,
  }),
  handler: async (args: { id: number; myMapId?: number }) => {
    try {
      await fetchMapsAPI(`/favorites/${args.id}`, {
        method: 'DELETE',
        queryParams: myMapIdQuery(args.myMapId),
      });
      return {
        content: [{ type: 'text' as const, text: `Favorite ${args.id} deleted.` }],
      };
    } catch (error) {
      return errorResult(`deleting map favorite ${args.id}`, error);
    }
  },
};

export const renameMapFavoriteCategoryTool = {
  name: 'rename_map_favorite_category',
  title: 'Rename Map Favorite Category',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: false,
  },
  description:
    'Rename one or more favorite categories. All favorites in the listed categories are moved to the new name.',
  inputSchema: z.object({
    categories: z.array(z.string()).min(1).describe('Existing category names to rename'),
    newName: z.string().describe('New category name'),
    myMapId: MY_MAP_ID_SCHEMA,
  }),
  handler: async (args: { categories: string[]; newName: string; myMapId?: number }) => {
    try {
      const body: Record<string, unknown> = {
        categories: args.categories,
        newName: args.newName,
      };
      if (args.myMapId !== undefined) body.myMapId = args.myMapId;

      await fetchMapsAPI('/favorites-category', { method: 'PUT', body });

      return {
        content: [
          {
            type: 'text' as const,
            text: `Renamed ${args.categories.length} categor${args.categories.length === 1 ? 'y' : 'ies'} (${args.categories.join(', ')}) to "${args.newName}".`,
          },
        ],
      };
    } catch (error) {
      return errorResult('renaming favorite categories', error);
    }
  },
};

// ── Favorite Category Sharing Tools ─────────────────────────────────────────

export const listSharedMapCategoriesTool = {
  name: 'list_shared_map_categories',
  title: 'List Shared Map Categories',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description: 'List favorite categories that are shared via a public link.',
  inputSchema: z.object({
    myMapId: MY_MAP_ID_SCHEMA,
  }),
  handler: async (args: { myMapId?: number }) => {
    try {
      const result = await fetchMapsAPI<FavoriteShare[]>('/favorites-category/shared', {
        queryParams: myMapIdQuery(args.myMapId),
      });

      if (result.length === 0) {
        return { content: [{ type: 'text' as const, text: 'No shared favorite categories.' }] };
      }

      const formatted = result.map(formatFavoriteShare).join('\n');
      return {
        content: [
          {
            type: 'text' as const,
            text: `Shared favorite categories (${result.length}):\n\n${formatted}`,
          },
        ],
      };
    } catch (error) {
      return errorResult('listing shared favorite categories', error);
    }
  },
};

export const shareMapCategoryTool = {
  name: 'share_map_category',
  title: 'Share Map Category',
  annotations: {
    readOnlyHint: false,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description:
    'Share a favorite category via a public link. The category must already contain at least one favorite.',
  inputSchema: z.object({
    category: z.string().describe('Category name to share'),
  }),
  handler: async (args: { category: string }) => {
    try {
      const result = await fetchMapsAPI<FavoriteShare>(
        `/favorites-category/${encodeURIComponent(args.category)}/share`,
        { method: 'POST' }
      );

      return {
        content: [
          {
            type: 'text' as const,
            text: `Category "${args.category}" shared.\n\n${formatFavoriteShare(result)}`,
          },
        ],
      };
    } catch (error) {
      return errorResult(`sharing category "${args.category}"`, error);
    }
  },
};

export const unshareMapCategoryTool = {
  name: 'unshare_map_category',
  title: 'Unshare Map Category',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: false,
  },
  description: 'Remove the public link share from a favorite category.',
  inputSchema: z.object({
    category: z.string().describe('Category name to unshare'),
  }),
  handler: async (args: { category: string }) => {
    try {
      const result = await fetchMapsAPI<{ did_exist?: boolean }>(
        `/favorites-category/${encodeURIComponent(args.category)}/un-share`,
        { method: 'POST' }
      );

      return {
        content: [
          {
            type: 'text' as const,
            text: result.did_exist
              ? `Category "${args.category}" unshared.`
              : `Category "${args.category}" was not shared.`,
          },
        ],
      };
    } catch (error) {
      return errorResult(`unsharing category "${args.category}"`, error);
    }
  },
};

export const addSharedCategoryToMapTool = {
  name: 'add_shared_category_to_map',
  title: 'Add Shared Category To Map',
  annotations: {
    readOnlyHint: false,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description: 'Add a shared favorite category to a custom map so its favorites show up there.',
  inputSchema: z.object({
    category: z.string().describe('Shared category name'),
    targetMapId: z.number().describe('ID of the custom map to add the shared category to'),
    myMapId: MY_MAP_ID_SCHEMA,
  }),
  handler: async (args: { category: string; targetMapId: number; myMapId?: number }) => {
    try {
      const result = await fetchMapsAPI<string>(
        `/favorites-category/${encodeURIComponent(args.category)}/add-to-map/${args.targetMapId}`,
        { method: 'PUT', queryParams: myMapIdQuery(args.myMapId) }
      );

      return {
        content: [
          {
            type: 'text' as const,
            text: `Category "${args.category}" added to map ${args.targetMapId} (${result}).`,
          },
        ],
      };
    } catch (error) {
      return errorResult(`adding category "${args.category}" to map ${args.targetMapId}`, error);
    }
  },
};

// ── Devices Tools ───────────────────────────────────────────────────────────

export const listMapDevicesTool = {
  name: 'list_map_devices',
  title: 'List Map Devices',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description: 'List GPS tracking devices registered in Nextcloud Maps.',
  inputSchema: z.object({
    myMapId: MY_MAP_ID_SCHEMA,
  }),
  handler: async (args: { myMapId?: number }) => {
    try {
      const result = await fetchMapsAPI<Device[]>('/devices', {
        queryParams: myMapIdQuery(args.myMapId),
      });

      if (result.length === 0) {
        return { content: [{ type: 'text' as const, text: 'No map devices found.' }] };
      }

      const formatted = result.map(formatDevice).join('\n');
      return {
        content: [
          { type: 'text' as const, text: `Map devices (${result.length}):\n\n${formatted}` },
        ],
      };
    } catch (error) {
      return errorResult('listing map devices', error);
    }
  },
};

export const getMapDevicePointsTool = {
  name: 'get_map_device_points',
  title: 'Get Map Device Points',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description:
    'Get GPS location points for a specific device. Supports time filtering and pagination.',
  inputSchema: z.object({
    id: z.number().describe('Device ID'),
    pruneBefore: z
      .number()
      .optional()
      .describe('Unix timestamp — only return points after this time'),
    limit: z.number().optional().describe('Maximum number of points to return (default 10000)'),
    offset: z.number().optional().describe('Number of points to skip (default 0)'),
  }),
  handler: async (args: { id: number; pruneBefore?: number; limit?: number; offset?: number }) => {
    try {
      const queryParams: Record<string, string> = {};
      if (args.pruneBefore !== undefined) queryParams['pruneBefore'] = String(args.pruneBefore);
      if (args.limit !== undefined) queryParams['limit'] = String(args.limit);
      if (args.offset !== undefined) queryParams['offset'] = String(args.offset);

      const result = await fetchMapsAPI<DevicePoint[]>(`/devices/${args.id}`, {
        queryParams,
      });

      if (result.length === 0) {
        return {
          content: [{ type: 'text' as const, text: `No points found for device ${args.id}.` }],
        };
      }

      const formatted = result.map(formatDevicePoint).join('\n');
      return {
        content: [
          {
            type: 'text' as const,
            text: `Device ${args.id} points (${result.length}):\n\n${formatted}`,
          },
        ],
      };
    } catch (error) {
      return errorResult('getting device points', error);
    }
  },
};

export const addMapDevicePointTool = {
  name: 'add_map_device_point',
  title: 'Add Map Device Point',
  annotations: {
    readOnlyHint: false,
    destructiveHint: false,
    idempotentHint: false,
    openWorldHint: false,
  },
  description:
    "Log a GPS location point for a device. The device is auto-created by user_agent if it doesn't exist.",
  inputSchema: z.object({
    lat: z.number().describe('Latitude'),
    lng: z.number().describe('Longitude'),
    timestamp: z.number().optional().describe('Unix timestamp (defaults to now)'),
    user_agent: z.string().optional().describe('Device identifier (defaults to HTTP User-Agent)'),
    altitude: z.number().optional().describe('Altitude in meters'),
    battery: z.number().optional().describe('Battery level percentage'),
    accuracy: z.number().optional().describe('GPS accuracy in meters'),
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

      const result = await fetchMapsAPI<{ deviceId: number; pointId: number }>('/devices', {
        method: 'POST',
        body,
      });

      return {
        content: [
          {
            type: 'text' as const,
            text: `Point logged — device ID: ${result.deviceId}, point ID: ${result.pointId}.`,
          },
        ],
      };
    } catch (error) {
      return errorResult('adding device point', error);
    }
  },
};

export const updateMapDeviceTool = {
  name: 'update_map_device',
  title: 'Update Map Device',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: false,
  },
  description: "Update a device's display color and/or name. At least one must be provided.",
  inputSchema: z.object({
    id: z.number().describe('Device ID'),
    color: z.string().optional().describe("New color (e.g. '#ff0000')"),
    name: z.string().optional().describe('New device name (user agent)'),
  }),
  handler: async (args: { id: number; color?: string; name?: string }) => {
    try {
      // The controller types both as non-nullable strings and ignores empty ones,
      // so always send both and let it skip whatever the caller left out.
      const result = await fetchMapsAPI<Device>(`/devices/${args.id}`, {
        method: 'PUT',
        body: { color: args.color ?? '', name: args.name ?? '' },
      });

      return {
        content: [
          { type: 'text' as const, text: `Device ${args.id} updated.\n\n${formatDevice(result)}` },
        ],
      };
    } catch (error) {
      return errorResult(`updating device ${args.id}`, error);
    }
  },
};

export const deleteMapDeviceTool = {
  name: 'delete_map_device',
  title: 'Delete Map Device',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: false,
  },
  description:
    'Delete a GPS tracking device and all its location points. This action is irreversible.',
  inputSchema: z.object({
    id: z.number().describe('Device ID to delete'),
  }),
  handler: async (args: { id: number }) => {
    try {
      await fetchMapsAPI(`/devices/${args.id}`, { method: 'DELETE' });
      return {
        content: [{ type: 'text' as const, text: `Device ${args.id} deleted.` }],
      };
    } catch (error) {
      return errorResult(`deleting device ${args.id}`, error);
    }
  },
};

// ── Device Sharing Tools ────────────────────────────────────────────────────

export const shareMapDeviceTool = {
  name: 'share_map_device',
  title: 'Share Map Device',
  annotations: {
    readOnlyHint: false,
    destructiveHint: false,
    idempotentHint: false,
    openWorldHint: false,
  },
  description:
    'Share a GPS device via a public link, limited to a time window. Returns the share token.',
  inputSchema: z.object({
    id: z.number().describe('Device ID to share'),
    timestampFrom: z.number().describe('Unix timestamp — start of the shared time window'),
    timestampTo: z.number().describe('Unix timestamp — end of the shared time window'),
  }),
  handler: async (args: { id: number; timestampFrom: number; timestampTo: number }) => {
    try {
      const result = await fetchMapsAPI<DeviceShare>(`/devices/${args.id}/share`, {
        method: 'POST',
        body: { timestampFrom: args.timestampFrom, timestampTo: args.timestampTo },
      });

      return {
        content: [
          {
            type: 'text' as const,
            text: `Device ${args.id} shared.\n\n${formatDeviceShare(result)}`,
          },
        ],
      };
    } catch (error) {
      return errorResult(`sharing device ${args.id}`, error);
    }
  },
};

export const listSharedMapDevicesTool = {
  name: 'list_shared_map_devices',
  title: 'List Shared Map Devices',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description:
    'List device shares that have been added to a custom map. Requires myMapId — the default map holds no device shares.',
  inputSchema: z.object({
    myMapId: z.number().describe('Custom map ID to list device shares from'),
  }),
  handler: async (args: { myMapId: number }) => {
    try {
      const result = await fetchMapsAPI<DeviceShare[]>('/devices/s/', {
        queryParams: { myMapId: String(args.myMapId) },
      });

      if (result.length === 0) {
        return {
          content: [{ type: 'text' as const, text: `No shared devices on map ${args.myMapId}.` }],
        };
      }

      const formatted = result.map(formatDeviceShare).join('\n');
      return {
        content: [
          {
            type: 'text' as const,
            text: `Shared devices on map ${args.myMapId} (${result.length}):\n\n${formatted}`,
          },
        ],
      };
    } catch (error) {
      return errorResult('listing shared devices', error);
    }
  },
};

export const removeMapDeviceShareTool = {
  name: 'remove_map_device_share',
  title: 'Remove Map Device Share',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: false,
  },
  description: 'Revoke a device share by its token.',
  inputSchema: z.object({
    token: z.string().describe('Share token to revoke'),
  }),
  handler: async (args: { token: string }) => {
    try {
      await fetchMapsAPI(`/devices/s/${encodeURIComponent(args.token)}`, { method: 'DELETE' });
      return {
        content: [{ type: 'text' as const, text: `Device share ${args.token} removed.` }],
      };
    } catch (error) {
      return errorResult(`removing device share ${args.token}`, error);
    }
  },
};

export const addSharedDeviceToMapTool = {
  name: 'add_shared_device_to_map',
  title: 'Add Shared Device To Map',
  annotations: {
    readOnlyHint: false,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description: 'Add a shared device (by token) to a custom map so its track shows up there.',
  inputSchema: z.object({
    token: z.string().describe('Device share token'),
    targetMapId: z.number().describe('ID of the custom map to add the shared device to'),
  }),
  handler: async (args: { token: string; targetMapId: number }) => {
    try {
      const result = await fetchMapsAPI<string>(
        `/devices/s/${encodeURIComponent(args.token)}/map-link/${args.targetMapId}`,
        { method: 'POST' }
      );

      return {
        content: [
          {
            type: 'text' as const,
            text: `Shared device added to map ${args.targetMapId} (${result}).`,
          },
        ],
      };
    } catch (error) {
      return errorResult(`adding shared device to map ${args.targetMapId}`, error);
    }
  },
};

// ── Tracks Tools ─────────────────────────────────────────────

export const listMapTracksTool = {
  name: 'list_map_tracks',
  title: 'List Map Tracks',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description: 'List GPS tracks (GPX/KML files) from Nextcloud Maps.',
  inputSchema: z.object({
    myMapId: z.number().optional().describe('Custom map ID to scope to'),
  }),
  handler: async (args: { myMapId?: number }) => {
    try {
      const queryParams: Record<string, string> = {};
      if (args.myMapId !== undefined) queryParams['myMapId'] = String(args.myMapId);

      const result = await fetchMapsAPI<Track[]>('/tracks', { queryParams });

      if (result.length === 0) {
        return { content: [{ type: 'text' as const, text: 'No tracks found.' }] };
      }

      const formatted = result.map(formatTrack).join('\n');
      return {
        content: [{ type: 'text' as const, text: `Tracks (${result.length}):\n\n${formatted}` }],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error listing tracks: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

export const getMapTrackTool = {
  name: 'get_map_track',
  title: 'Get Map Track',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description:
    'Get full details and content of a track by its ID. Returns metadata and the raw GPX/KML file content.',
  inputSchema: z.object({
    id: z.number().describe('Track ID'),
  }),
  handler: async (args: { id: number }) => {
    try {
      const result = await fetchMapsAPI<TrackDetail>(`/tracks/${args.id}`);

      const lines = [`# Track ${args.id}`, ''];
      if (result.metadata) {
        lines.push('## Metadata');
        for (const [k, v] of Object.entries(result.metadata)) {
          lines.push(`- ${k}: ${JSON.stringify(v)}`);
        }
        lines.push('');
      }
      lines.push('## Content');
      lines.push('```xml');
      lines.push(result.content);
      lines.push('```');

      return { content: [{ type: 'text' as const, text: lines.join('\n') }] };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error getting track ${args.id}: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

export const updateMapTrackTool = {
  name: 'update_map_track',
  title: 'Update Map Track',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: false,
  },
  description: "Update a track's color or metadata.",
  inputSchema: z.object({
    id: z.number().describe('Track ID'),
    color: z.string().optional().describe("New color (e.g. '#00ff00')"),
    metadata: z.string().optional().describe('New metadata as JSON string'),
    etag: z.string().optional().describe('ETag for concurrency control'),
  }),
  handler: async (args: { id: number; color?: string; metadata?: string; etag?: string }) => {
    try {
      const body: Record<string, unknown> = {};
      if (args.color !== undefined) body.color = args.color;
      if (args.metadata !== undefined) body.metadata = args.metadata;
      if (args.etag !== undefined) body.etag = args.etag;

      await fetchMapsAPI(`/tracks/${args.id}`, { method: 'PUT', body });

      return {
        content: [{ type: 'text' as const, text: `Track ${args.id} updated.` }],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error updating track ${args.id}: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

// ── Photos Tools ─────────────────────────────────────────────

export const listMapPhotosTool = {
  name: 'list_map_photos',
  title: 'List Map Photos',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description: 'List geolocated photos (photos with GPS coordinates) from Nextcloud Maps.',
  inputSchema: z.object({
    myMapId: z.number().optional().describe('Custom map ID to scope to'),
  }),
  handler: async (args: { myMapId?: number }) => {
    try {
      const queryParams: Record<string, string> = {};
      if (args.myMapId !== undefined) queryParams['myMapId'] = String(args.myMapId);

      const result = await fetchMapsAPI<Photo[]>('/photos', { queryParams });

      if (result.length === 0) {
        return { content: [{ type: 'text' as const, text: 'No geolocated photos found.' }] };
      }

      const formatted = result.map(formatPhoto).join('\n');
      return {
        content: [
          { type: 'text' as const, text: `Geolocated photos (${result.length}):\n\n${formatted}` },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error listing photos: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

export const listMapPhotosNonlocalizedTool = {
  name: 'list_map_photos_nonlocalized',
  title: 'List Non-Localized Map Photos',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description:
    "List photos that don't have GPS coordinates. Useful for finding photos that can be placed on the map.",
  inputSchema: z.object({
    myMapId: z.number().optional().describe('Custom map ID to scope to'),
    limit: z.number().optional().describe('Max photos to return (default 250)'),
    offset: z.number().optional().describe('Pagination offset (default 0)'),
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
      if (args.myMapId !== undefined) queryParams['myMapId'] = String(args.myMapId);
      if (args.limit !== undefined) queryParams['limit'] = String(args.limit);
      if (args.offset !== undefined) queryParams['offset'] = String(args.offset);
      if (args.timezone !== undefined) queryParams['timezone'] = args.timezone;

      const result = await fetchMapsAPI<NonLocalizedPhoto[]>('/photos/nonlocalized', {
        queryParams,
      });

      if (result.length === 0) {
        return { content: [{ type: 'text' as const, text: 'No non-localized photos found.' }] };
      }

      const formatted = result.map(formatNonLocalizedPhoto).join('\n');
      return {
        content: [
          {
            type: 'text' as const,
            text: `Non-localized photos (${result.length}):\n\n${formatted}`,
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error listing non-localized photos: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

export const placeMapPhotosTool = {
  name: 'place_map_photos',
  title: 'Place Map Photos',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: false,
  },
  description:
    'Set GPS coordinates on one or more photos. Each photo path is paired with a lat/lng.',
  inputSchema: z.object({
    paths: z.array(z.string()).describe('File paths in Nextcloud storage'),
    lats: z.array(z.number()).describe('Latitudes (parallel to paths)'),
    lngs: z.array(z.number()).describe('Longitudes (parallel to paths)'),
    myMapId: z.number().optional().describe('Custom map ID to scope to'),
  }),
  handler: async (args: { paths: string[]; lats: number[]; lngs: number[]; myMapId?: number }) => {
    try {
      const body: Record<string, unknown> = {
        paths: args.paths,
        lats: args.lats,
        lngs: args.lngs,
      };
      if (args.myMapId !== undefined) body.myMapId = args.myMapId;

      await fetchMapsAPI('/photos', { method: 'POST', body });

      return {
        content: [
          { type: 'text' as const, text: `Coordinates set on ${args.paths.length} photo(s).` },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error placing photos: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

export const resetMapPhotoCoordsTool = {
  name: 'reset_map_photo_coords',
  title: 'Reset Map Photo Coordinates',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: false,
  },
  description: 'Remove GPS coordinates from one or more photos.',
  inputSchema: z.object({
    paths: z.array(z.string()).describe('File paths in Nextcloud storage'),
    myMapId: z.number().optional().describe('Custom map ID to scope to'),
  }),
  handler: async (args: { paths: string[]; myMapId?: number }) => {
    try {
      const body: Record<string, unknown> = { paths: args.paths };
      if (args.myMapId !== undefined) body.myMapId = args.myMapId;

      await fetchMapsAPI('/photos', { method: 'DELETE', body });

      return {
        content: [
          {
            type: 'text' as const,
            text: `Coordinates removed from ${args.paths.length} photo(s).`,
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error resetting photo coordinates: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

export const getMapPhotoJobStatusTool = {
  name: 'get_map_photo_job_status',
  title: 'Get Map Photo Job Status',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description:
    'Get the status of the background job that scans photos for GPS coordinates. Useful after uploading photos to see whether geolocation has finished.',
  inputSchema: z.object({}),
  handler: async () => {
    try {
      const result = await fetchMapsAPI<Record<string, unknown>>('/photos/backgroundJobStatus');

      const lines = Object.entries(result).map(([k, v]) => `- ${k}: ${JSON.stringify(v)}`);
      return {
        content: [
          {
            type: 'text' as const,
            text:
              lines.length > 0
                ? `Photo background job status:\n\n${lines.join('\n')}`
                : 'No photo background job status reported.',
          },
        ],
      };
    } catch (error) {
      return errorResult('getting photo background job status', error);
    }
  },
};

// ── Contacts Tools ──────────────────────────────────────────────────────────

export const listMapContactsTool = {
  name: 'list_map_contacts',
  title: 'List Map Contacts',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description: 'List address book contacts that carry a geographic address, as shown on the map.',
  inputSchema: z.object({
    myMapId: MY_MAP_ID_SCHEMA,
  }),
  handler: async (args: { myMapId?: number }) => {
    try {
      const result = await fetchMapsAPI<Contact[]>('/contacts', {
        queryParams: myMapIdQuery(args.myMapId),
      });

      if (result.length === 0) {
        return { content: [{ type: 'text' as const, text: 'No contacts with addresses found.' }] };
      }

      const formatted = result.map(formatContact).join('\n');
      return {
        content: [
          { type: 'text' as const, text: `Map contacts (${result.length}):\n\n${formatted}` },
        ],
      };
    } catch (error) {
      return errorResult('listing map contacts', error);
    }
  },
};

export const searchMapContactsTool = {
  name: 'search_map_contacts',
  title: 'Search Map Contacts',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description:
    'Search address book contacts by name, to find the bookid/uri/uid needed to place a contact on the map.',
  inputSchema: z.object({
    query: z.string().describe('Search term matched against contact display names'),
  }),
  handler: async (args: { query: string }) => {
    try {
      const result = await fetchMapsAPI<Contact[]>('/contacts-search', {
        queryParams: { query: args.query },
      });

      if (result.length === 0) {
        return {
          content: [{ type: 'text' as const, text: `No contacts matching "${args.query}".` }],
        };
      }

      const formatted = result
        .map((c) => `- **${c.FN}** — UID: ${c.UID} | Book ID: ${c.BOOKID} | URI: ${c.URI}`)
        .join('\n');
      return {
        content: [
          {
            type: 'text' as const,
            text: `Contacts matching "${args.query}" (${result.length}):\n\n${formatted}`,
          },
        ],
      };
    } catch (error) {
      return errorResult(`searching contacts for "${args.query}"`, error);
    }
  },
};

export const placeMapContactTool = {
  name: 'place_map_contact',
  title: 'Place Map Contact',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: false,
  },
  description:
    "Set a contact's geographic address and coordinates so it appears on the map. Use search_map_contacts to look up bookid, uri and uid.",
  inputSchema: z.object({
    bookid: z.string().describe('Address book ID'),
    uri: z.string().describe('Contact URI (e.g. "abc123.vcf")'),
    uid: z.string().describe('Contact UID'),
    lat: z.number().optional().describe('Latitude'),
    lng: z.number().optional().describe('Longitude'),
    address_string: z
      .string()
      .optional()
      .describe('Full address as a single string, used instead of the individual fields'),
    attraction: z.string().optional().describe('Point of interest name'),
    house_number: z.string().optional().describe('House number'),
    road: z.string().optional().describe('Street'),
    postcode: z.string().optional().describe('Postal code'),
    city: z.string().optional().describe('City'),
    state: z.string().optional().describe('State or region'),
    country: z.string().optional().describe('Country'),
    type: z.string().optional().describe("Address type (e.g. 'HOME', 'WORK')"),
    myMapId: MY_MAP_ID_SCHEMA,
  }),
  handler: async (args: {
    bookid: string;
    uri: string;
    uid: string;
    lat?: number;
    lng?: number;
    address_string?: string;
    attraction?: string;
    house_number?: string;
    road?: string;
    postcode?: string;
    city?: string;
    state?: string;
    country?: string;
    type?: string;
    myMapId?: number;
  }) => {
    try {
      const { bookid, uri, ...rest } = args;
      const body: Record<string, unknown> = {};
      for (const [key, value] of Object.entries(rest)) {
        if (value !== undefined) body[key] = value;
      }

      const result = await fetchMapsAPI<string>(
        `/contacts/${encodeURIComponent(bookid)}/${encodeURIComponent(uri)}`,
        { method: 'PUT', body }
      );

      if (result !== 'EDITED') {
        return errorResult(`placing contact ${args.uid}`, new Error(result));
      }

      return {
        content: [
          {
            type: 'text' as const,
            text: `Contact ${args.uid} placed${args.lat !== undefined && args.lng !== undefined ? ` at ${args.lat}, ${args.lng}` : ''}.`,
          },
        ],
      };
    } catch (error) {
      return errorResult(`placing contact ${args.uid}`, error);
    }
  },
};

export const addContactToMapTool = {
  name: 'add_contact_to_map',
  title: 'Add Contact To Map',
  annotations: {
    readOnlyHint: false,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description: 'Copy a contact into a custom map so it shows up as a pin on that map.',
  inputSchema: z.object({
    bookid: z.string().describe('Address book ID'),
    uri: z.string().describe('Contact URI (e.g. "abc123.vcf")'),
    myMapId: z.number().describe('ID of the custom map to add the contact to'),
  }),
  handler: async (args: { bookid: string; uri: string; myMapId: number }) => {
    try {
      const result = await fetchMapsAPI<string>(
        `/contacts/${encodeURIComponent(args.bookid)}/${encodeURIComponent(args.uri)}/add-to-map/`,
        { method: 'PUT', body: { myMapId: args.myMapId } }
      );

      if (result !== 'DONE') {
        return errorResult(`adding contact to map ${args.myMapId}`, new Error(result));
      }

      return {
        content: [
          { type: 'text' as const, text: `Contact ${args.uri} added to map ${args.myMapId}.` },
        ],
      };
    } catch (error) {
      return errorResult(`adding contact to map ${args.myMapId}`, error);
    }
  },
};

export const deleteMapContactAddressTool = {
  name: 'delete_map_contact_address',
  title: 'Delete Map Contact Address',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: false,
  },
  description:
    'Remove an address and its coordinates from a contact, taking it off the map. The contact itself is kept. Use list_map_contacts to get the exact ADR and GEO values.',
  inputSchema: z.object({
    bookid: z.string().describe('Address book ID'),
    uri: z.string().describe('Contact URI (e.g. "abc123.vcf")'),
    uid: z.string().describe('Contact UID'),
    adr: z.string().describe('The ADR value to remove, exactly as returned by list_map_contacts'),
    geo: z.string().describe('The GEO value to remove, exactly as returned by list_map_contacts'),
    myMapId: MY_MAP_ID_SCHEMA,
  }),
  handler: async (args: {
    bookid: string;
    uri: string;
    uid: string;
    adr: string;
    geo: string;
    myMapId?: number;
  }) => {
    try {
      const body: Record<string, unknown> = { uid: args.uid, adr: args.adr, geo: args.geo };
      if (args.myMapId !== undefined) body.myMapId = args.myMapId;

      const result = await fetchMapsAPI<string>(
        `/contacts/${encodeURIComponent(args.bookid)}/${encodeURIComponent(args.uri)}`,
        { method: 'DELETE', body }
      );

      if (result !== 'DELETED') {
        return errorResult(`deleting address of contact ${args.uid}`, new Error(result));
      }

      return {
        content: [{ type: 'text' as const, text: `Address removed from contact ${args.uid}.` }],
      };
    } catch (error) {
      return errorResult(`deleting address of contact ${args.uid}`, error);
    }
  },
};

// ── My Maps Tools ────────────────────────────────────────────

export const listMapsTool = {
  name: 'list_maps',
  title: 'List Maps',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description: 'List all custom maps created by the user in Nextcloud Maps.',
  inputSchema: z.object({}),
  handler: async () => {
    try {
      const result = await fetchMapsAPI<MyMap[]>('/maps');

      if (result.length === 0) {
        return { content: [{ type: 'text' as const, text: 'No custom maps found.' }] };
      }

      const formatted = result.map(formatMyMap).join('\n');
      return {
        content: [
          { type: 'text' as const, text: `Custom maps (${result.length}):\n\n${formatted}` },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error listing maps: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

export const createMapTool = {
  name: 'create_map',
  title: 'Create Map',
  annotations: {
    readOnlyHint: false,
    destructiveHint: false,
    idempotentHint: false,
    openWorldHint: false,
  },
  description: 'Create a new custom map in Nextcloud Maps.',
  inputSchema: z.object({
    name: z.string().optional().describe("Map name (default: 'New Map')"),
  }),
  handler: async (args: { name?: string }) => {
    try {
      const body: Record<string, unknown> = { values: { newName: args.name || 'New Map' } };

      const result = await fetchMapsAPI<MyMap>('/maps', { method: 'POST', body });

      return {
        content: [
          {
            type: 'text' as const,
            text: `Map created (ID: ${result.id}).\n\n${formatMyMap(result)}`,
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error creating map: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

export const updateMapTool = {
  name: 'update_map',
  title: 'Update Map',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: false,
  },
  description: "Update a custom map's properties (e.g. rename).",
  inputSchema: z.object({
    id: z.number().describe('Map ID'),
    values: z
      .record(z.unknown())
      .describe("Key-value pairs to update (e.g. { newName: 'My Trip' })"),
  }),
  handler: async (args: { id: number; values: Record<string, unknown> }) => {
    try {
      const result = await fetchMapsAPI<MyMap>(`/maps/${args.id}`, {
        method: 'PUT',
        body: { values: args.values },
      });

      return {
        content: [
          { type: 'text' as const, text: `Map ${args.id} updated.\n\n${formatMyMap(result)}` },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error updating map ${args.id}: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

export const deleteMapTool = {
  name: 'delete_map',
  title: 'Delete Map',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: false,
  },
  description: 'Delete a custom map. This action is irreversible.',
  inputSchema: z.object({
    id: z.number().describe('Map ID to delete'),
  }),
  handler: async (args: { id: number }) => {
    try {
      await fetchMapsAPI(`/maps/${args.id}`, { method: 'DELETE' });
      return {
        content: [{ type: 'text' as const, text: `Map ${args.id} deleted.` }],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error deleting map ${args.id}: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

// ── Routing Tool ─────────────────────────────────────────────

export const exportMapRouteTool = {
  name: 'export_map_route',
  title: 'Export Map Route',
  annotations: {
    readOnlyHint: false,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description: "Export a route or track as a GPX file to the user's Nextcloud /Maps folder.",
  inputSchema: z.object({
    name: z.string().describe('Route name (used as filename)'),
    type: z
      .enum(['route', 'track'])
      .describe("GPX structure type: 'route' (<rte>) or 'track' (<trk>)"),
    coords: z
      .array(
        z.object({
          lat: z.number().describe('Latitude'),
          lng: z.number().describe('Longitude'),
        })
      )
      .describe('Ordered list of coordinates'),
    totDist: z.number().optional().describe('Total distance (for metadata)'),
    totTime: z.number().optional().describe('Total time (for metadata)'),
    myMapId: z.number().optional().describe('Target map ID (defaults to /Maps folder)'),
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

      const result = await fetchMapsAPI<Track>('/exportRoute', {
        method: 'POST',
        body,
      });

      return {
        content: [
          {
            type: 'text' as const,
            text: `Route "${args.name}" exported as GPX.\n\n${formatTrack(result)}`,
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error exporting route: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

// ── Import/Export Tools ───────────────────────────────────────

export const exportMapFavoritesTool = {
  name: 'export_map_favorites',
  title: 'Export Map Favorites',
  annotations: {
    readOnlyHint: false,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description:
    "Export favorites as a GPX file to the user's Nextcloud /Maps folder. Filter by categories and time range.",
  inputSchema: z.object({
    categoryList: z
      .array(z.string())
      .describe("Categories to export (e.g. ['Restaurant', 'Home'])"),
    begin: z.number().optional().describe('Start timestamp filter'),
    end: z.number().optional().describe('End timestamp filter'),
    all: z.boolean().optional().describe("If true, filename is not prefixed with 'filtered-'"),
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

      const result = await fetchMapsAPI<string>('/export/favorites', {
        method: 'POST',
        body,
      });

      return {
        content: [{ type: 'text' as const, text: `Favorites exported to: ${result}` }],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error exporting favorites: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

export const importMapFavoritesTool = {
  name: 'import_map_favorites',
  title: 'Import Map Favorites',
  annotations: {
    readOnlyHint: false,
    destructiveHint: false,
    idempotentHint: false,
    openWorldHint: false,
  },
  description:
    'Import favorites from a file in Nextcloud storage. Supports GPX, KML, KMZ, JSON, and GeoJSON.',
  inputSchema: z.object({
    path: z
      .string()
      .describe("Relative path to the file in Nextcloud storage (e.g. '/Maps/favorites.gpx')"),
  }),
  handler: async (args: { path: string }) => {
    try {
      const result = await fetchMapsAPI<unknown>('/import/favorites', {
        method: 'POST',
        body: { path: args.path },
      });

      return {
        content: [
          {
            type: 'text' as const,
            text: `Favorites imported from ${args.path}.\n\n${JSON.stringify(result, null, 2)}`,
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error importing favorites: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

export const exportMapDevicesTool = {
  name: 'export_map_devices',
  title: 'Export Map Devices',
  annotations: {
    readOnlyHint: false,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description: "Export device location data as a GPX file to the user's Nextcloud /Maps folder.",
  inputSchema: z.object({
    deviceIdList: z.array(z.number()).describe('Device IDs to export'),
    begin: z.number().optional().describe('Start timestamp filter'),
    end: z.number().optional().describe('End timestamp filter'),
    all: z.boolean().optional().describe("If true, filename is not prefixed with 'filtered-'"),
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

      const result = await fetchMapsAPI<string>('/export/devices', {
        method: 'POST',
        body,
      });

      return {
        content: [{ type: 'text' as const, text: `Devices exported to: ${result}` }],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error exporting devices: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

export const importMapDevicesTool = {
  name: 'import_map_devices',
  title: 'Import Map Devices',
  annotations: {
    readOnlyHint: false,
    destructiveHint: false,
    idempotentHint: false,
    openWorldHint: false,
  },
  description:
    'Import device location data from a file in Nextcloud storage. Supports GPX, KML, and KMZ.',
  inputSchema: z.object({
    path: z
      .string()
      .describe("Relative path to the file in Nextcloud storage (e.g. '/Maps/track.gpx')"),
  }),
  handler: async (args: { path: string }) => {
    try {
      const result = await fetchMapsAPI<number>('/import/devices', {
        method: 'POST',
        body: { path: args.path },
      });

      return {
        content: [
          { type: 'text' as const, text: `Imported ${result} device point(s) from ${args.path}.` },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error importing devices: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
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
  renameMapFavoriteCategoryTool,
  // Favorite category sharing
  listSharedMapCategoriesTool,
  shareMapCategoryTool,
  unshareMapCategoryTool,
  addSharedCategoryToMapTool,
  // Devices
  listMapDevicesTool,
  getMapDevicePointsTool,
  addMapDevicePointTool,
  updateMapDeviceTool,
  deleteMapDeviceTool,
  // Device sharing
  shareMapDeviceTool,
  listSharedMapDevicesTool,
  removeMapDeviceShareTool,
  addSharedDeviceToMapTool,
  // Tracks
  listMapTracksTool,
  getMapTrackTool,
  updateMapTrackTool,
  // Photos
  listMapPhotosTool,
  listMapPhotosNonlocalizedTool,
  placeMapPhotosTool,
  resetMapPhotoCoordsTool,
  getMapPhotoJobStatusTool,
  // Contacts
  listMapContactsTool,
  searchMapContactsTool,
  placeMapContactTool,
  addContactToMapTool,
  deleteMapContactAddressTool,
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
