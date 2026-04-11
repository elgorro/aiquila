// SPDX-License-Identifier: MIT

import { z } from 'zod';
import { fetchCalDAV } from '../../client/caldav.js';
import { getNextcloudConfig } from '../types.js';

/**
 * Nextcloud File Versioning Tools
 * Manages file versions via WebDAV Versions API
 */

// ---------------------------------------------------------------------------
// list_file_versions
// ---------------------------------------------------------------------------

export const listFileVersionsTool = {
  name: 'list_file_versions',
  description: 'List previous versions of a file (use get_file_info to find the fileId)',
  inputSchema: z.object({
    fileId: z.number().describe('The Nextcloud internal file ID (from get_file_info)'),
  }),
  handler: async (args: { fileId: number }) => {
    try {
      const config = getNextcloudConfig();
      const url = `${config.url}/remote.php/dav/versions/${config.user}/versions/${args.fileId}`;

      const body = `<?xml version="1.0"?>
<d:propfind xmlns:d="DAV:">
  <d:prop>
    <d:getlastmodified />
    <d:getcontentlength />
    <d:getcontenttype />
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
              text: `Error listing versions: ${response.status} ${response.statusText}`,
            },
          ],
          isError: true,
        };
      }

      // Parse responses — skip the first one (container)
      const responses = text.match(/<d:response>[\s\S]*?<\/d:response>/g) || [];
      const versions = responses.slice(1).map((r) => {
        const href = r.match(/<d:href>([^<]*)<\/d:href>/)?.[1] ?? '';
        const lastModified = r.match(/<d:getlastmodified>([^<]*)<\/d:getlastmodified>/)?.[1] ?? '';
        const size = r.match(/<d:getcontentlength>([^<]*)<\/d:getcontentlength>/)?.[1] ?? '';

        // Version identifier is the last segment of the href
        const versionId = href.split('/').filter(Boolean).pop() ?? '';
        const sizeStr = size ? ` (${Math.round(parseInt(size, 10) / 1024)} KB)` : '';

        return `- Version: ${versionId}\n  Modified: ${lastModified}${sizeStr}`;
      });

      if (versions.length === 0) {
        return {
          content: [
            {
              type: 'text' as const,
              text: `No previous versions found for file ${args.fileId}.`,
            },
          ],
        };
      }

      return {
        content: [
          {
            type: 'text' as const,
            text: `File versions for ${args.fileId} (${versions.length}):\n${versions.join('\n')}`,
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error listing file versions: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

// ---------------------------------------------------------------------------
// restore_file_version
// ---------------------------------------------------------------------------

export const restoreFileVersionTool = {
  name: 'restore_file_version',
  description:
    'Restore a previous version of a file (creates a new current version from the old one)',
  inputSchema: z.object({
    fileId: z.number().describe('The Nextcloud internal file ID'),
    versionId: z.string().describe('The version identifier (from list_file_versions output)'),
  }),
  handler: async (args: { fileId: number; versionId: string }) => {
    try {
      const config = getNextcloudConfig();
      const sourceUrl = `${config.url}/remote.php/dav/versions/${config.user}/versions/${args.fileId}/${args.versionId}`;
      const destUrl = `${config.url}/remote.php/dav/versions/${config.user}/restore/target`;

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
              text: `Restored version ${args.versionId} of file ${args.fileId}.`,
            },
          ],
        };
      }

      const text = await response.text();
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error restoring version: ${response.status} ${response.statusText}\n${text}`,
          },
        ],
        isError: true,
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error restoring version: ${error instanceof Error ? error.message : String(error)}`,
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

export const versionsTools = [listFileVersionsTool, restoreFileVersionTool];
