// SPDX-License-Identifier: MIT

import { z } from 'zod';
import { fetchOCS } from '../../client/ocs.js';

/**
 * Nextcloud Recommendations Tools
 * Surface the files Nextcloud recommends for the configured user via the
 * Recommendations app OCS API.
 */

interface RecommendedFile {
  id: string;
  timestamp: number;
  name: string;
  directory: string;
  extension: string;
  mimeType: string;
  hasPreview: boolean;
  reason: string;
}

interface RecommendationsResponse {
  enabled: boolean;
  recommendations?: RecommendedFile[];
}

function formatTime(ts: number): string {
  if (!ts) return '';
  return new Date(ts * 1000).toISOString();
}

function formatRecommendations(items: RecommendedFile[]): string {
  return items
    .map((f) => {
      const dir = f.directory && f.directory !== '/' ? f.directory.replace(/\/$/, '') : '';
      const path = `${dir}/${f.name}`;
      const time = f.timestamp ? ` (${formatTime(f.timestamp)})` : '';
      const reason = f.reason ? `\n  reason: ${f.reason}` : '';
      return `- ${path}${time}${reason}`;
    })
    .join('\n');
}

// ---------------------------------------------------------------------------
// list_recommendations
// ---------------------------------------------------------------------------

export const listRecommendationsTool = {
  name: 'list_recommendations',
  description:
    'List files Nextcloud recommends for the configured user (e.g. recently or frequently ' +
    'accessed, recently shared, or files in active folders), including the reason each file ' +
    "is recommended. Respects the user's recommendations setting.",
  inputSchema: z.object({}),
  handler: async () => {
    try {
      const result = await fetchOCS<RecommendationsResponse>(
        '/ocs/v2.php/apps/recommendations/api/v1/recommendations'
      );

      const data = result.ocs.data;
      const items = data.recommendations ?? [];

      if (!data.enabled && items.length === 0) {
        return {
          content: [
            {
              type: 'text' as const,
              text: 'Recommendations are disabled for this user.',
            },
          ],
        };
      }

      if (items.length === 0) {
        return {
          content: [{ type: 'text' as const, text: 'No recommended files.' }],
        };
      }

      return {
        content: [
          {
            type: 'text' as const,
            text: `Recommended files (${items.length}):\n${formatRecommendations(items)}`,
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error listing recommendations: ${error instanceof Error ? error.message : String(error)}`,
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

export const recommendationsTools = [listRecommendationsTool];
