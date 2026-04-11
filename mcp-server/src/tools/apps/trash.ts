// SPDX-License-Identifier: MIT

import { z } from 'zod';
import { fetchCalDAV, decodeXmlEntities } from '../../client/caldav.js';
import { getNextcloudConfig } from '../types.js';

/**
 * Nextcloud Trash / Recycle Bin Tools
 * Manages deleted files via WebDAV Trashbin API
 */

// ---------------------------------------------------------------------------
// list_trash
// ---------------------------------------------------------------------------

export const listTrashTool = {
  name: 'list_trash',
  description: 'List files in the trash / recycle bin',
  inputSchema: z.object({}),
  handler: async () => {
    try {
      const config = getNextcloudConfig();
      const url = `${config.url}/remote.php/dav/trashbin/${config.user}/trash/`;

      const body = `<?xml version="1.0"?>
<d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns" xmlns:nc="http://nextcloud.org/ns">
  <d:prop>
    <d:displayname />
    <d:getlastmodified />
    <d:getcontentlength />
    <oc:trashbin-original-location />
    <oc:trashbin-delete-timestamp />
  </d:prop>
</d:propfind>`;

      const response = await fetchCalDAV(url, {
        method: 'PROPFIND',
        body,
        headers: { Depth: '1' },
      });

      const text = await response.text();

      if (!response.ok && response.status !== 207) {
        return {
          content: [
            {
              type: 'text' as const,
              text: `Error listing trash: ${response.status} ${response.statusText}`,
            },
          ],
          isError: true,
        };
      }

      // Parse responses — skip the first one (container itself)
      const responses = text.match(/<d:response>[\s\S]*?<\/d:response>/g) || [];
      const items = responses.slice(1).map((r) => {
        const href = r.match(/<d:href>([^<]*)<\/d:href>/)?.[1] ?? '';
        const displayname = decodeXmlEntities(
          r.match(/<d:displayname>([^<]*)<\/d:displayname>/)?.[1] ?? ''
        );
        const originalLocation = decodeXmlEntities(
          r.match(/<oc:trashbin-original-location>([^<]*)<\/oc:trashbin-original-location>/)?.[1] ??
            ''
        );
        const deleteTimestamp =
          r.match(/<oc:trashbin-delete-timestamp>([^<]*)<\/oc:trashbin-delete-timestamp>/)?.[1] ??
          '';
        const size = r.match(/<d:getcontentlength>([^<]*)<\/d:getcontentlength>/)?.[1] ?? '';

        const deletedAt = deleteTimestamp
          ? new Date(parseInt(deleteTimestamp, 10) * 1000).toISOString()
          : '';
        const sizeStr = size ? ` (${Math.round(parseInt(size, 10) / 1024)} KB)` : '';
        // Extract the trash item key from href for restore operations
        const trashKey = href.split('/trash/')[1]?.replace(/\/$/, '') ?? '';

        return `- ${displayname || trashKey}${sizeStr}\n  Original: ${originalLocation}\n  Deleted: ${deletedAt}\n  Key: ${trashKey}`;
      });

      if (items.length === 0) {
        return {
          content: [{ type: 'text' as const, text: 'Trash is empty.' }],
        };
      }

      return {
        content: [
          {
            type: 'text' as const,
            text: `Trash items (${items.length}):\n${items.join('\n')}`,
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error listing trash: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

// ---------------------------------------------------------------------------
// restore_from_trash
// ---------------------------------------------------------------------------

export const restoreFromTrashTool = {
  name: 'restore_from_trash',
  description:
    'Restore a file from the trash to its original location (use the Key from list_trash)',
  inputSchema: z.object({
    trashKey: z.string().describe('The trash item key (from the Key field in list_trash output)'),
  }),
  handler: async (args: { trashKey: string }) => {
    try {
      const config = getNextcloudConfig();
      const sourceUrl = `${config.url}/remote.php/dav/trashbin/${config.user}/trash/${args.trashKey}`;
      const destUrl = `${config.url}/remote.php/dav/trashbin/${config.user}/restore/${args.trashKey}`;

      const response = await fetchCalDAV(sourceUrl, {
        method: 'MOVE',
        headers: {
          Destination: destUrl,
          Overwrite: 'T',
        },
      });

      if (response.status >= 200 && response.status < 300) {
        return {
          content: [
            {
              type: 'text' as const,
              text: `Restored "${args.trashKey}" from trash.`,
            },
          ],
        };
      }

      const text = await response.text();
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error restoring from trash: ${response.status} ${response.statusText}\n${text}`,
          },
        ],
        isError: true,
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error restoring from trash: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

// ---------------------------------------------------------------------------
// empty_trash
// ---------------------------------------------------------------------------

export const emptyTrashTool = {
  name: 'empty_trash',
  description: 'Permanently delete all files in the trash (cannot be undone)',
  inputSchema: z.object({}),
  handler: async () => {
    try {
      const config = getNextcloudConfig();
      const url = `${config.url}/remote.php/dav/trashbin/${config.user}/trash/`;

      const response = await fetchCalDAV(url, { method: 'DELETE' });

      if (response.status >= 200 && response.status < 300) {
        return {
          content: [{ type: 'text' as const, text: 'Trash emptied successfully.' }],
        };
      }

      const text = await response.text();
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error emptying trash: ${response.status} ${response.statusText}\n${text}`,
          },
        ],
        isError: true,
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error emptying trash: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

// ---------------------------------------------------------------------------
// Export
// ---------------------------------------------------------------------------

export const trashTools = [listTrashTool, restoreFromTrashTool, emptyTrashTool];
