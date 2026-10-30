// SPDX-License-Identifier: MIT

import { z } from 'zod';
import { fetchOCS } from '../../client/ocs.js';
import { statusLabel, type Task } from './assistant.js';

/**
 * Nextcloud Translation Tool
 *
 * Translates through the TaskProcessing task type `core:text2text:translate`,
 * which current providers implement (translate2, the AIquila app, LLM apps).
 * The older Translation API (/ocs/v2.php/translation/*) is deprecated since
 * Nextcloud 30 and only reaches providers that still implement
 * ITranslationProvider, so it is used only when no TaskProcessing provider
 * offers translation.
 */

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

interface LanguagePair {
  from: string;
  to: string;
}

interface EnumValue {
  name: string;
  value: string;
}

interface TranslateTaskType {
  inputShapeEnumValues?: {
    origin_language?: EnumValue[];
    target_language?: EnumValue[];
  };
}

const TASK_TYPE = 'core:text2text:translate';
const DETECT_LANGUAGE = 'detect_language';

/** How long translate_text waits for the task before handing back its ID. */
const WAIT_MS = 30_000;
const POLL_INTERVAL_MS = 1_000;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/** The translate task type, or null when no TaskProcessing provider offers it. */
async function getTranslateTaskType(): Promise<TranslateTaskType | null> {
  const data = await fetchOCS<{ types: Record<string, TranslateTaskType> }>(
    '/ocs/v2.php/taskprocessing/tasktypes'
  );
  return data.ocs.data.types?.[TASK_TYPE] ?? null;
}

function formatLanguages(values: EnumValue[] | undefined): string {
  return (values ?? []).map((v) => `${v.value} (${v.name})`).join(', ') || '(none)';
}

function text(t: string, isError = false) {
  return {
    content: [{ type: 'text' as const, text: t }],
    ...(isError ? { isError: true } : {}),
  };
}

async function translateWithTask(
  input: string,
  fromLanguage: string | undefined,
  toLanguage: string
) {
  const scheduled = await fetchOCS<{ task: Task }>('/ocs/v2.php/taskprocessing/schedule', {
    method: 'POST',
    jsonBody: {
      type: TASK_TYPE,
      appId: 'aiquila-mcp',
      input: {
        input,
        origin_language: fromLanguage ?? DETECT_LANGUAGE,
        target_language: toLanguage,
      },
    },
  });

  let task = scheduled.ocs.data.task;
  const deadline = Date.now() + WAIT_MS;
  while (
    (task.status === 'STATUS_SCHEDULED' || task.status === 'STATUS_RUNNING') &&
    Date.now() < deadline
  ) {
    await new Promise((resolve) => setTimeout(resolve, POLL_INTERVAL_MS));
    task = (await fetchOCS<{ task: Task }>(`/ocs/v2.php/taskprocessing/task/${task.id}`)).ocs.data
      .task;
  }

  if (task.status === 'STATUS_SUCCESSFUL') {
    const output = task.output?.output;
    return text(typeof output === 'string' ? output : JSON.stringify(task.output));
  }
  if (task.status === 'STATUS_FAILED' || task.status === 'STATUS_CANCELLED') {
    return text(
      `Translation task ${task.id} ${statusLabel(task.status)}: ${task.userFacingErrorMessage || 'no error message given'}`,
      true
    );
  }
  return text(
    `Translation task ${task.id} is still ${statusLabel(task.status)}. ` +
      `Use get_task_result with taskId ${task.id} to fetch the translation.`
  );
}

// ---------------------------------------------------------------------------
// translate_text
// ---------------------------------------------------------------------------

export const translateTextTool = {
  name: 'translate_text',
  title: 'Translate Text',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: true,
  },
  description:
    "Translate text between languages using Nextcloud's configured translation provider. " +
    'If called without text, returns the available languages. Translation runs as a Nextcloud ' +
    'AI task; if it takes longer than about 30 seconds, the task ID is returned for get_task_result.',
  inputSchema: z.object({
    text: z.string().optional().describe('Text to translate. Omit to list available languages.'),
    fromLanguage: z
      .string()
      .optional()
      .describe("Source language code (e.g. 'en', 'de'). Omit to detect it."),
    toLanguage: z.string().optional().describe("Target language code (e.g. 'en', 'de', 'fr')"),
  }),
  handler: async (args: { text?: string; fromLanguage?: string; toLanguage?: string }) => {
    try {
      const taskType = await getTranslateTaskType();

      // List available languages mode
      if (!args.text) {
        if (taskType) {
          const enums = taskType.inputShapeEnumValues ?? {};
          return text(
            `Source languages: ${formatLanguages(enums.origin_language)}\n` +
              `Target languages: ${formatLanguages(enums.target_language)}`
          );
        }
        const data = await fetchOCS<{ languages: LanguagePair[] }>(
          '/ocs/v2.php/translation/languages'
        );
        const languages = data.ocs.data.languages ?? [];
        return text(
          languages.length === 0
            ? 'No translation providers are configured in Nextcloud.'
            : JSON.stringify(languages, null, 2)
        );
      }

      if (!args.toLanguage) {
        return text('toLanguage is required when translating text.', true);
      }

      if (taskType) {
        return await translateWithTask(args.text, args.fromLanguage, args.toLanguage);
      }

      const body: Record<string, string> = { text: args.text, toLanguage: args.toLanguage };
      if (args.fromLanguage) body.fromLanguage = args.fromLanguage;
      const data = await fetchOCS<{ text: string }>('/ocs/v2.php/translation/translate', {
        method: 'POST',
        body,
      });
      return text(data.ocs.data.text);
    } catch (error) {
      return text(
        `Error translating text: ${error instanceof Error ? error.message : String(error)}`,
        true
      );
    }
  },
};

// ---------------------------------------------------------------------------
// Export
// ---------------------------------------------------------------------------

export const translateTools = [translateTextTool];
