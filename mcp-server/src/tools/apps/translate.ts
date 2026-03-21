import { z } from 'zod';
import { fetchOCS } from '../../client/ocs.js';

/**
 * Nextcloud Translation Tool
 *
 * Bridges MCP to Nextcloud's OCS Translation API, allowing text
 * translation between languages using whatever translation provider
 * the Nextcloud admin has configured (DeepL, LibreTranslate, etc.).
 */

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

interface LanguagePair {
  from: string;
  to: string;
}

// ---------------------------------------------------------------------------
// translate_text
// ---------------------------------------------------------------------------

export const translateTextTool = {
  name: 'translate_text',
  description:
    "Translate text between languages using Nextcloud's configured translation provider. If called without text, returns available language pairs.",
  inputSchema: z.object({
    text: z
      .string()
      .optional()
      .describe('Text to translate. Omit to list available language pairs.'),
    fromLanguage: z.string().optional().describe("Source language code (e.g. 'en', 'de', 'fr')"),
    toLanguage: z.string().optional().describe("Target language code (e.g. 'en', 'de', 'fr')"),
  }),
  handler: async (args: { text?: string; fromLanguage?: string; toLanguage?: string }) => {
    try {
      // List available languages mode
      if (!args.text) {
        const data = await fetchOCS<{ languages: LanguagePair[] }>(
          '/ocs/v2.php/translation/languages'
        );
        const languages = data.ocs.data.languages ?? [];
        return {
          content: [
            {
              type: 'text' as const,
              text:
                languages.length === 0
                  ? 'No translation providers are configured in Nextcloud.'
                  : JSON.stringify(languages, null, 2),
            },
          ],
        };
      }

      // Translate mode
      if (!args.fromLanguage || !args.toLanguage) {
        return {
          content: [
            {
              type: 'text' as const,
              text: 'Both fromLanguage and toLanguage are required when translating text.',
            },
          ],
          isError: true,
        };
      }

      const data = await fetchOCS<{ text: string }>('/ocs/v2.php/translation/translate', {
        method: 'POST',
        body: {
          text: args.text,
          fromLanguage: args.fromLanguage,
          toLanguage: args.toLanguage,
        },
      });

      return {
        content: [
          {
            type: 'text' as const,
            text: data.ocs.data.text,
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error translating text: ${error instanceof Error ? error.message : String(error)}`,
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

export const translateTools = [translateTextTool];
