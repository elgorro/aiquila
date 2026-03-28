import { z } from 'zod';
import { fetchCalDAV, nsTagContent, decodeXmlEntities } from '../../client/caldav.js';
import { getNextcloudConfig } from '../types.js';

/**
 * Nextcloud Photos Tools
 *
 * Manages photo albums and retrieves photo metadata via the Photos DAV API.
 */

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function text(t: string) {
  return { content: [{ type: 'text' as const, text: t }] };
}

function error(msg: string) {
  return { content: [{ type: 'text' as const, text: msg }], isError: true };
}

function wrapError(action: string, err: unknown) {
  return error(`Error ${action}: ${err instanceof Error ? err.message : String(err)}`);
}

function albumsUrl(config: { url: string; user: string }): string {
  return `${config.url}/remote.php/dav/photos/${config.user}/albums`;
}

function filesUrl(config: { url: string; user: string }): string {
  return `${config.url}/remote.php/dav/files/${config.user}`;
}

/** Extract all <d:response> blocks from a multistatus XML response. */
function parseResponses(xml: string): string[] {
  return xml.match(/<d:response>[\s\S]*?<\/d:response>/g) || [];
}

/** Extract text content of an XML tag, with optional namespace prefix handling. */
function tagContent(xml: string, localName: string): string {
  const match = xml.match(nsTagContent(localName));
  return match ? decodeXmlEntities(match[1].trim()) : '';
}

/** Format file size in human-readable form. */
function formatSize(bytes: string): string {
  const n = parseInt(bytes, 10);
  if (isNaN(n)) return '';
  if (n < 1024) return `${n} B`;
  if (n < 1024 * 1024) return `${Math.round(n / 1024)} KB`;
  return `${(n / (1024 * 1024)).toFixed(1)} MB`;
}

// ---------------------------------------------------------------------------
// XML request bodies
// ---------------------------------------------------------------------------

const ALBUM_LIST_PROPFIND = `<?xml version="1.0"?>
<d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns" xmlns:nc="http://nextcloud.org/ns">
  <d:prop>
    <d:displayname />
    <d:getlastmodified />
    <nc:nbItems />
    <nc:location />
    <nc:dateRange />
    <nc:lastPhoto />
  </d:prop>
</d:propfind>`;

const ALBUM_CONTENTS_PROPFIND = `<?xml version="1.0"?>
<d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns" xmlns:nc="http://nextcloud.org/ns">
  <d:prop>
    <d:displayname />
    <d:getcontenttype />
    <d:getcontentlength />
    <d:getlastmodified />
    <oc:fileid />
    <oc:size />
    <nc:has-preview />
    <nc:favorite />
  </d:prop>
</d:propfind>`;

const FILE_ID_PROPFIND = `<?xml version="1.0"?>
<d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">
  <d:prop>
    <oc:fileid />
  </d:prop>
</d:propfind>`;

const METADATA_PROPFIND = `<?xml version="1.0"?>
<d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns" xmlns:nc="http://nextcloud.org/ns">
  <d:prop>
    <oc:fileid />
    <d:displayname />
    <d:getcontenttype />
    <d:getcontentlength />
    <d:getlastmodified />
    <nc:favorite />
    <nc:metadata-photos-size />
    <nc:metadata-photos-gps />
    <nc:metadata-photos-ifd0 />
    <nc:metadata-photos-exif />
    <nc:metadata-photos-original_date_time />
  </d:prop>
</d:propfind>`;

// ---------------------------------------------------------------------------
// photos_list_albums
// ---------------------------------------------------------------------------

export const photosListAlbumsTool = {
  name: 'photos_list_albums',
  description:
    'List all photo albums owned by the current user. Returns album names, item counts, locations, and date ranges.',
  inputSchema: z.object({}),
  handler: async () => {
    try {
      const config = getNextcloudConfig();
      const url = `${albumsUrl(config)}/`;

      const response = await fetchCalDAV(url, {
        method: 'PROPFIND',
        body: ALBUM_LIST_PROPFIND,
        headers: { Depth: '1' },
      });

      const xml = await response.text();
      if (!response.ok && response.status !== 207) {
        return error(`Error listing albums: ${response.status} ${response.statusText}`);
      }

      const responses = parseResponses(xml);
      // Skip the first response (the albums container itself)
      const albums = responses.slice(1).map((r) => {
        const name = tagContent(r, 'displayname');
        const nbItems = tagContent(r, 'nbItems');
        const location = tagContent(r, 'location');
        const dateRange = tagContent(r, 'dateRange');
        let dateInfo = '';
        if (dateRange) {
          try {
            const parsed = JSON.parse(dateRange);
            const start = parsed.start
              ? new Date(parsed.start * 1000).toISOString().split('T')[0]
              : '';
            const end = parsed.end ? new Date(parsed.end * 1000).toISOString().split('T')[0] : '';
            if (start || end) dateInfo = `, dates: ${start} — ${end}`;
          } catch {
            // dateRange may not be valid JSON — ignore
          }
        }

        const locInfo = location ? `, location: ${location}` : '';
        const count = nbItems || '0';
        return `- ${name} (${count} items${locInfo}${dateInfo})`;
      });

      if (!albums.length) return text('No albums found.');
      return text(`Albums (${albums.length}):\n${albums.join('\n')}`);
    } catch (err) {
      return wrapError('listing albums', err);
    }
  },
};

// ---------------------------------------------------------------------------
// photos_get_album
// ---------------------------------------------------------------------------

export const photosGetAlbumTool = {
  name: 'photos_get_album',
  description:
    'Get details of a photo album including its files. Returns file IDs (needed for photos_remove_from_album), names, types, and sizes.',
  inputSchema: z.object({
    name: z.string().describe('Album name'),
  }),
  handler: async (args: { name: string }) => {
    try {
      const config = getNextcloudConfig();
      const url = `${albumsUrl(config)}/${encodeURIComponent(args.name)}/`;

      const response = await fetchCalDAV(url, {
        method: 'PROPFIND',
        body: ALBUM_CONTENTS_PROPFIND,
        headers: { Depth: '1' },
      });

      const xml = await response.text();
      if (!response.ok && response.status !== 207) {
        return error(`Error getting album: ${response.status} ${response.statusText}`);
      }

      const responses = parseResponses(xml);
      if (!responses.length) return error(`Album "${args.name}" not found.`);

      // First response is the album itself
      const albumXml = responses[0];
      const albumName = tagContent(albumXml, 'displayname') || args.name;

      // Remaining responses are files in the album
      const files = responses.slice(1).map((r, i) => {
        const fileId = tagContent(r, 'fileid');
        const displayName = tagContent(r, 'displayname');
        const contentType = tagContent(r, 'getcontenttype');
        const size = tagContent(r, 'getcontentlength') || tagContent(r, 'size');
        const favorite = tagContent(r, 'favorite') === '1' ? ' *' : '';
        const sizeStr = size ? ` (${formatSize(size)})` : '';
        const typeStr = contentType || 'unknown';
        return `${i + 1}. [${fileId}] ${displayName} — ${typeStr}${sizeStr}${favorite}`;
      });

      const lines = [`# ${albumName}`, `Files: ${files.length}`, ''];
      if (files.length) {
        lines.push(...files);
      } else {
        lines.push('(empty album)');
      }
      return text(lines.join('\n'));
    } catch (err) {
      return wrapError(`getting album "${args.name}"`, err);
    }
  },
};

// ---------------------------------------------------------------------------
// photos_create_album
// ---------------------------------------------------------------------------

export const photosCreateAlbumTool = {
  name: 'photos_create_album',
  description: 'Create a new photo album.',
  inputSchema: z.object({
    name: z.string().describe('Name for the new album'),
  }),
  handler: async (args: { name: string }) => {
    try {
      const config = getNextcloudConfig();
      const url = `${albumsUrl(config)}/${encodeURIComponent(args.name)}`;

      const response = await fetchCalDAV(url, { method: 'MKCOL' });

      if (response.status === 201) {
        return text(`Album "${args.name}" created.`);
      }
      if (response.status === 405) {
        return error(`Album "${args.name}" already exists.`);
      }
      const body = await response.text();
      return error(`Error creating album: ${response.status} ${response.statusText}\n${body}`);
    } catch (err) {
      return wrapError('creating album', err);
    }
  },
};

// ---------------------------------------------------------------------------
// photos_delete_album
// ---------------------------------------------------------------------------

export const photosDeleteAlbumTool = {
  name: 'photos_delete_album',
  description:
    'Delete a photo album. This only removes the album — the underlying files are not deleted.',
  inputSchema: z.object({
    name: z.string().describe('Name of the album to delete'),
  }),
  handler: async (args: { name: string }) => {
    try {
      const config = getNextcloudConfig();
      const url = `${albumsUrl(config)}/${encodeURIComponent(args.name)}`;

      const response = await fetchCalDAV(url, { method: 'DELETE' });

      if (response.status >= 200 && response.status < 300) {
        return text(`Album "${args.name}" deleted.`);
      }
      if (response.status === 404) {
        return error(`Album "${args.name}" not found.`);
      }
      const body = await response.text();
      return error(`Error deleting album: ${response.status} ${response.statusText}\n${body}`);
    } catch (err) {
      return wrapError(`deleting album "${args.name}"`, err);
    }
  },
};

// ---------------------------------------------------------------------------
// photos_rename_album
// ---------------------------------------------------------------------------

export const photosRenameAlbumTool = {
  name: 'photos_rename_album',
  description: 'Rename a photo album.',
  inputSchema: z.object({
    name: z.string().describe('Current album name'),
    newName: z.string().describe('New album name'),
  }),
  handler: async (args: { name: string; newName: string }) => {
    try {
      const config = getNextcloudConfig();
      const sourceUrl = `${albumsUrl(config)}/${encodeURIComponent(args.name)}`;
      const destUrl = `${albumsUrl(config)}/${encodeURIComponent(args.newName)}`;

      const response = await fetchCalDAV(sourceUrl, {
        method: 'MOVE',
        headers: { Destination: destUrl, Overwrite: 'F' },
      });

      if (response.status >= 200 && response.status < 300) {
        return text(`Album renamed from "${args.name}" to "${args.newName}".`);
      }
      if (response.status === 404) {
        return error(`Album "${args.name}" not found.`);
      }
      if (response.status === 412) {
        return error(`Album "${args.newName}" already exists.`);
      }
      const body = await response.text();
      return error(`Error renaming album: ${response.status} ${response.statusText}\n${body}`);
    } catch (err) {
      return wrapError(`renaming album "${args.name}"`, err);
    }
  },
};

// ---------------------------------------------------------------------------
// photos_add_to_album
// ---------------------------------------------------------------------------

export const photosAddToAlbumTool = {
  name: 'photos_add_to_album',
  description:
    'Add one or more files to a photo album. Files are referenced by their Nextcloud path. The files themselves are not moved — only an album association is created.',
  inputSchema: z.object({
    albumName: z.string().describe('Album name'),
    filePaths: z
      .array(z.string())
      .describe('Array of file paths in Nextcloud (e.g., ["/Photos/sunset.jpg"])'),
  }),
  handler: async (args: { albumName: string; filePaths: string[] }) => {
    try {
      const config = getNextcloudConfig();
      const results: string[] = [];

      for (const filePath of args.filePaths) {
        const normalizedPath = filePath.startsWith('/') ? filePath : `/${filePath}`;
        const fileName = normalizedPath.split('/').pop() ?? '';

        try {
          // Step 1: Get file ID via PROPFIND
          const fileUrl = `${filesUrl(config)}${normalizedPath}`;
          const propRes = await fetchCalDAV(fileUrl, {
            method: 'PROPFIND',
            body: FILE_ID_PROPFIND,
            headers: { Depth: '0' },
          });

          if (!propRes.ok && propRes.status !== 207) {
            results.push(`FAIL ${filePath}: file not found (${propRes.status})`);
            continue;
          }

          const propXml = await propRes.text();
          const fileId = tagContent(propXml, 'fileid');
          if (!fileId) {
            results.push(`FAIL ${filePath}: could not resolve file ID`);
            continue;
          }

          // Step 2: COPY file to album
          const destUrl = `${albumsUrl(config)}/${encodeURIComponent(args.albumName)}/${fileId}-${encodeURIComponent(fileName)}`;
          const copyRes = await fetchCalDAV(fileUrl, {
            method: 'COPY',
            headers: { Destination: destUrl, Overwrite: 'F' },
          });

          if (copyRes.status >= 200 && copyRes.status < 300) {
            results.push(`OK   ${filePath}`);
          } else if (copyRes.status === 409) {
            results.push(`SKIP ${filePath}: already in album`);
          } else {
            results.push(`FAIL ${filePath}: ${copyRes.status} ${copyRes.statusText}`);
          }
        } catch (fileErr) {
          results.push(
            `FAIL ${filePath}: ${fileErr instanceof Error ? fileErr.message : String(fileErr)}`
          );
        }
      }

      const ok = results.filter((r) => r.startsWith('OK')).length;
      const header = `Added ${ok}/${args.filePaths.length} files to album "${args.albumName}"`;
      return text(`${header}\n${results.join('\n')}`);
    } catch (err) {
      return wrapError(`adding files to album "${args.albumName}"`, err);
    }
  },
};

// ---------------------------------------------------------------------------
// photos_remove_from_album
// ---------------------------------------------------------------------------

export const photosRemoveFromAlbumTool = {
  name: 'photos_remove_from_album',
  description:
    'Remove one or more files from a photo album by file ID (use photos_get_album to find IDs). This only removes the album association — the files are not deleted.',
  inputSchema: z.object({
    albumName: z.string().describe('Album name'),
    fileIds: z.array(z.string()).describe('File IDs to remove (from photos_get_album output)'),
  }),
  handler: async (args: { albumName: string; fileIds: string[] }) => {
    try {
      const config = getNextcloudConfig();
      const albumUrl = `${albumsUrl(config)}/${encodeURIComponent(args.albumName)}/`;

      // Get album contents to find hrefs matching the file IDs
      const listRes = await fetchCalDAV(albumUrl, {
        method: 'PROPFIND',
        body: ALBUM_CONTENTS_PROPFIND,
        headers: { Depth: '1' },
      });

      const xml = await listRes.text();
      if (!listRes.ok && listRes.status !== 207) {
        return error(`Error reading album: ${listRes.status} ${listRes.statusText}`);
      }

      const responses = parseResponses(xml);
      // Build a map of fileId -> href
      const fileHrefs = new Map<string, string>();
      for (const r of responses.slice(1)) {
        const id = tagContent(r, 'fileid');
        const href = r.match(/<d:href>([^<]*)<\/d:href>/)?.[1] ?? '';
        if (id && href) fileHrefs.set(id, href);
      }

      const results: string[] = [];
      for (const fileId of args.fileIds) {
        const href = fileHrefs.get(fileId);
        if (!href) {
          results.push(`SKIP ${fileId}: not found in album`);
          continue;
        }

        try {
          const deleteUrl = `${config.url}${href}`;
          const delRes = await fetchCalDAV(deleteUrl, { method: 'DELETE' });
          if (delRes.status >= 200 && delRes.status < 300) {
            results.push(`OK   ${fileId}`);
          } else {
            results.push(`FAIL ${fileId}: ${delRes.status} ${delRes.statusText}`);
          }
        } catch (delErr) {
          results.push(
            `FAIL ${fileId}: ${delErr instanceof Error ? delErr.message : String(delErr)}`
          );
        }
      }

      const ok = results.filter((r) => r.startsWith('OK')).length;
      const header = `Removed ${ok}/${args.fileIds.length} files from album "${args.albumName}"`;
      return text(`${header}\n${results.join('\n')}`);
    } catch (err) {
      return wrapError(`removing files from album "${args.albumName}"`, err);
    }
  },
};

// ---------------------------------------------------------------------------
// photos_get_metadata
// ---------------------------------------------------------------------------

export const photosGetMetadataTool = {
  name: 'photos_get_metadata',
  description:
    'Get photo/video metadata (EXIF) for a file. Returns camera, lens, ISO, aperture, shutter speed, focal length, GPS coordinates, dimensions, and date taken when available.',
  inputSchema: z.object({
    path: z.string().describe('File path in Nextcloud (e.g., "/Photos/sunset.jpg")'),
  }),
  handler: async (args: { path: string }) => {
    try {
      const config = getNextcloudConfig();
      const normalizedPath = args.path.startsWith('/') ? args.path : `/${args.path}`;
      const url = `${filesUrl(config)}${normalizedPath}`;

      const response = await fetchCalDAV(url, {
        method: 'PROPFIND',
        body: METADATA_PROPFIND,
        headers: { Depth: '0' },
      });

      const xml = await response.text();
      if (!response.ok && response.status !== 207) {
        if (response.status === 404) return error(`File not found: ${args.path}`);
        return error(`Error getting metadata: ${response.status} ${response.statusText}`);
      }

      // Basic file info
      const fileId = tagContent(xml, 'fileid');
      const displayName = tagContent(xml, 'displayname');
      const contentType = tagContent(xml, 'getcontenttype');
      const size = tagContent(xml, 'getcontentlength');
      const lastModified = tagContent(xml, 'getlastmodified');
      const favorite = tagContent(xml, 'favorite') === '1';

      const lines: string[] = [`# ${displayName || args.path}`, ''];

      // File info
      lines.push(`File ID: ${fileId}`);
      lines.push(`Type: ${contentType}`);
      if (size) lines.push(`Size: ${formatSize(size)}`);
      lines.push(`Modified: ${lastModified}`);
      if (favorite) lines.push(`Favorite: yes`);

      // Photo dimensions
      const sizeJson = tagContent(xml, 'metadata-photos-size');
      if (sizeJson) {
        try {
          const dims = JSON.parse(sizeJson);
          if (dims.width && dims.height) {
            lines.push(`Dimensions: ${dims.width} x ${dims.height}`);
          }
        } catch {
          // not valid JSON
        }
      }

      // Original date/time
      const originalDateTime = tagContent(xml, 'metadata-photos-original_date_time');
      if (originalDateTime) {
        const ts = parseInt(originalDateTime, 10);
        if (!isNaN(ts)) {
          lines.push(`Date taken: ${new Date(ts * 1000).toISOString()}`);
        }
      }

      // Camera info (IFD0)
      const ifd0Json = tagContent(xml, 'metadata-photos-ifd0');
      if (ifd0Json) {
        try {
          const ifd0 = JSON.parse(ifd0Json);
          if (ifd0.Make || ifd0.Model) {
            const camera = [ifd0.Make, ifd0.Model].filter(Boolean).join(' ');
            lines.push(`Camera: ${camera}`);
          }
        } catch {
          // not valid JSON
        }
      }

      // EXIF details
      const exifJson = tagContent(xml, 'metadata-photos-exif');
      if (exifJson) {
        try {
          const exif = JSON.parse(exifJson);
          if (exif.FocalLength) lines.push(`Focal length: ${exif.FocalLength}mm`);
          if (exif.FNumber) lines.push(`Aperture: f/${exif.FNumber}`);
          if (exif.ExposureTime) {
            const et = exif.ExposureTime;
            const shutter = et < 1 ? `1/${Math.round(1 / et)}s` : `${et}s`;
            lines.push(`Shutter speed: ${shutter}`);
          }
          if (exif.ISOSpeedRatings) lines.push(`ISO: ${exif.ISOSpeedRatings}`);
          if (exif.LensModel) lines.push(`Lens: ${exif.LensModel}`);
          if (exif.Flash !== undefined) {
            lines.push(`Flash: ${exif.Flash & 1 ? 'fired' : 'no flash'}`);
          }
        } catch {
          // not valid JSON
        }
      }

      // GPS coordinates
      const gpsJson = tagContent(xml, 'metadata-photos-gps');
      if (gpsJson) {
        try {
          const gps = JSON.parse(gpsJson);
          if (gps.latitude !== undefined && gps.longitude !== undefined) {
            lines.push(`GPS: ${gps.latitude}, ${gps.longitude}`);
          }
        } catch {
          // not valid JSON
        }
      }

      return text(lines.join('\n'));
    } catch (err) {
      return wrapError(`getting metadata for "${args.path}"`, err);
    }
  },
};

// ---------------------------------------------------------------------------
// Export
// ---------------------------------------------------------------------------

export const photosTools = [
  photosListAlbumsTool,
  photosGetAlbumTool,
  photosCreateAlbumTool,
  photosDeleteAlbumTool,
  photosRenameAlbumTool,
  photosAddToAlbumTool,
  photosRemoveFromAlbumTool,
  photosGetMetadataTool,
];
