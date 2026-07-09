// SPDX-License-Identifier: MIT

import { z } from 'zod';
import { fetchOCS } from '../../client/ocs.js';

/**
 * Unified Search Tools
 * Search across all Nextcloud apps via OCS Unified Search API
 */

/** Shape of a single search result entry */
interface SearchEntry {
  thumbnailUrl: string;
  title: string;
  subline: string;
  resourceUrl: string;
  icon: string;
  rounded: boolean;
  attributes: Record<string, string>;
}

/** Shape of the search response from a single provider */
interface SearchResult {
  name: string;
  isPaginated: boolean;
  entries: SearchEntry[];
  cursor: number | string | null;
}

/** Shape of a search provider from the providers list */
interface SearchProvider {
  id: string;
  appId: string;
  name: string;
  icon: string;
  order: number;
  triggers: string[];
  filters: Record<string, unknown>;
}

/**
 * Search a single provider and return formatted results.
 */
async function searchProvider(
  providerId: string,
  query: string,
  limit: number,
  cursor?: string
): Promise<{ provider: string; results: SearchResult }> {
  const queryParams: Record<string, string> = {
    term: query,
    limit: String(limit),
    format: 'json',
  };
  if (cursor) queryParams.cursor = cursor;

  const response = await fetchOCS<SearchResult>(
    `/ocs/v2.php/search/providers/${encodeURIComponent(providerId)}/search`,
    { queryParams }
  );

  return { provider: providerId, results: response.ocs.data };
}

/**
 * Unified search across Nextcloud apps
 */
export const unifiedSearchTool = {
  name: 'unified_search',
  title: 'Unified Search',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description:
    'Search across all Nextcloud apps (files, calendar, contacts, mail, notes, etc.) using Unified Search. ' +
    'Optionally filter by a specific provider ID. Without a provider, searches the top providers in parallel.',
  inputSchema: z.object({
    query: z.string().describe('The search query'),
    provider: z
      .string()
      .optional()
      .describe(
        'Search a specific provider by ID (e.g. "files", "calendar", "contacts"). ' +
          'Omit to search across all providers.'
      ),
    limit: z.number().optional().default(5).describe('Maximum results per provider (default: 5)'),
    cursor: z.string().optional().describe('Pagination cursor returned from a previous search'),
  }),
  handler: async (args: { query: string; provider?: string; limit: number; cursor?: string }) => {
    try {
      // Search a specific provider
      if (args.provider) {
        const { results } = await searchProvider(
          args.provider,
          args.query,
          args.limit,
          args.cursor
        );

        const output = {
          provider: args.provider,
          name: results.name,
          isPaginated: results.isPaginated,
          cursor: results.cursor,
          entries: results.entries.map((e) => ({
            title: e.title,
            subline: e.subline,
            resourceUrl: e.resourceUrl,
          })),
        };

        return {
          content: [{ type: 'text' as const, text: JSON.stringify(output, null, 2) }],
        };
      }

      // No provider specified — list providers, then search top ones in parallel
      const providersResponse = await fetchOCS<SearchProvider[]>('/ocs/v2.php/search/providers', {
        queryParams: { format: 'json' },
      });
      const providers = providersResponse.ocs.data;

      if (!providers.length) {
        return {
          content: [{ type: 'text' as const, text: 'No search providers available.' }],
        };
      }

      // Always include the most useful providers; fill remaining slots by order.
      // Without this, mail (order 20) and calendar (order 30) fall outside the
      // top 5 on a typical install and are silently never searched.
      const PRIORITY_IDS = ['files', 'mail', 'calendar', 'notes'];
      const prioritized = PRIORITY_IDS.map((id) => providers.find((p) => p.id === id)).filter(
        (p): p is SearchProvider => p !== undefined
      );
      const rest = providers
        .filter((p) => !PRIORITY_IDS.includes(p.id))
        .sort((a, b) => a.order - b.order);
      const topProviders = [...prioritized, ...rest].slice(0, 6);

      const searches = await Promise.allSettled(
        topProviders.map((p) => searchProvider(p.id, args.query, args.limit))
      );

      const output: Array<{
        provider: string;
        name: string;
        entries: Array<{ title: string; subline: string; resourceUrl: string }>;
        cursor: number | string | null;
      }> = [];

      for (const result of searches) {
        if (result.status === 'fulfilled') {
          const { provider, results } = result.value;
          if (results.entries.length > 0) {
            output.push({
              provider,
              name: results.name,
              entries: results.entries.map((e) => ({
                title: e.title,
                subline: e.subline,
                resourceUrl: e.resourceUrl,
              })),
              cursor: results.cursor,
            });
          }
        }
      }

      if (!output.length) {
        return {
          content: [
            {
              type: 'text' as const,
              text: `No results found for "${args.query}" across ${topProviders.length} providers.`,
            },
          ],
        };
      }

      return {
        content: [{ type: 'text' as const, text: JSON.stringify(output, null, 2) }],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error performing unified search: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

/**
 * List available search providers
 */
export const listSearchProvidersTool = {
  name: 'list_search_providers',
  title: 'List Search Providers',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description:
    'List all available Unified Search providers in Nextcloud. ' +
    'Use this to discover provider IDs for targeted searches with unified_search.',
  inputSchema: z.object({}),
  handler: async () => {
    try {
      const response = await fetchOCS<SearchProvider[]>('/ocs/v2.php/search/providers', {
        queryParams: { format: 'json' },
      });

      const providers = response.ocs.data.map((p) => ({
        id: p.id,
        name: p.name,
        appId: p.appId,
        order: p.order,
      }));

      return {
        content: [{ type: 'text' as const, text: JSON.stringify(providers, null, 2) }],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error listing search providers: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

/**
 * Export all search tools
 */
export const searchTools = [unifiedSearchTool, listSearchProvidersTool];
