// SPDX-License-Identifier: MIT

import { z } from 'zod';
import {
  fetchFormsAPI,
  type Form,
  type FormPermission,
  type FormQuestion,
  type FormOption,
  type FormShare,
  type FormSubmission,
  type QuestionType,
  type ShareType,
} from '../../client/forms.js';
import { handleAppError } from '../error-utils.js';

/**
 * Nextcloud Forms App Tools
 * Uses the Forms OCS API v3 (/ocs/v2.php/apps/forms/api/v3)
 */

const formsStatusMap: Record<number, string> = {
  400: 'Bad request — check parameters, or the form/question/option may not exist.',
  403: 'Access denied to this form.',
  404: 'Form, question, option, share, or submission not found.',
};

const QUESTION_TYPES = [
  'short',
  'long',
  'multiple',
  'multiple_unique',
  'dropdown',
  'date',
  'datetime',
  'time',
  'file',
  'linearscale',
  'color',
] as const;

const PERMISSIONS = ['edit', 'results', 'results_delete', 'submit', 'embed'] as const;

const SHARE_TYPE_LABELS: Record<ShareType, string> = {
  0: 'user',
  1: 'group',
  3: 'link',
};

const STATE_LABELS: Record<number, string> = {
  0: 'active',
  1: 'closed',
  2: 'archived',
};

function formatForm(f: Form): string {
  const state = STATE_LABELS[f.state] ?? `state=${f.state}`;
  const expires = f.expires ? ` expires=${new Date(f.expires * 1000).toISOString()}` : '';
  const counts = f.submissionCount !== undefined ? ` submissions=${f.submissionCount}` : '';
  return `[${f.id}] ${f.title || '(untitled)'} — owner=${f.ownerId}, ${state}${expires}${counts}`;
}

function formatFormDetailed(f: Form): string {
  const lines: string[] = [formatForm(f)];
  if (f.description) lines.push(`Description: ${f.description}`);
  if (f.hash) lines.push(`Hash: ${f.hash}`);
  if (f.created) lines.push(`Created: ${new Date(f.created * 1000).toISOString()}`);
  const flags = [
    f.isAnonymous ? 'anonymous' : null,
    f.submitMultiple ? 'submit-multiple' : null,
    f.allowEditSubmissions ? 'edit-submissions' : null,
    f.showExpiration ? 'show-expiration' : null,
    f.access?.permitAllUsers ? 'all-users' : null,
  ].filter(Boolean);
  if (flags.length) lines.push(`Flags: ${flags.join(', ')}`);
  if (f.permissions?.length) lines.push(`Permissions: ${f.permissions.join(', ')}`);
  if (f.questions?.length) lines.push(`Questions: ${f.questions.length}`);
  if (f.shares?.length) lines.push(`Shares: ${f.shares.length}`);
  return lines.join('\n');
}

function formatQuestion(q: FormQuestion): string {
  const req = q.isRequired ? ' *required*' : '';
  const optCount = q.options?.length ? ` (${q.options.length} options)` : '';
  return `[${q.id}] #${q.order} ${q.type}${req}: ${q.text || q.name || '(no text)'}${optCount}`;
}

function formatOption(o: FormOption): string {
  return `[${o.id}] #${o.order} ${o.text}`;
}

function formatShare(s: FormShare): string {
  const type = SHARE_TYPE_LABELS[s.shareType] ?? `type=${s.shareType}`;
  const who = s.displayName ? `${s.displayName} (${s.shareWith})` : s.shareWith;
  const perms = s.permissions?.length ? ` [${s.permissions.join(',')}]` : '';
  return `[${s.id}] ${type}: ${who}${perms}`;
}

function formatAnswer(a: { questionName?: string; text: string; fileId?: number }): string {
  const q = a.questionName ? `${a.questionName}: ` : '';
  const file = a.fileId ? ` (fileId=${a.fileId})` : '';
  return `${q}${a.text}${file}`;
}

function formatSubmission(s: FormSubmission): string {
  const when = s.timestamp ? new Date(s.timestamp * 1000).toISOString() : '';
  const who = s.userDisplayName || s.userId || 'anonymous';
  const answers =
    s.answers && s.answers.length ? '\n  ' + s.answers.map(formatAnswer).join('\n  ') : '';
  return `[${s.id}] ${who}${when ? ` at ${when}` : ''}${answers}`;
}

// ─────────────────────────────────────────────────────────────────────────────
// Forms
// ─────────────────────────────────────────────────────────────────────────────

export const listFormsTool = {
  name: 'list_forms',
  title: 'List Forms',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description:
    'List forms. type="owned" (default) returns forms the current user owns; "shared" returns forms shared with them; "partial" returns forms the user has an unfinished draft on.',
  inputSchema: z.object({
    type: z.enum(['owned', 'shared', 'partial']).optional().describe('Form list scope'),
  }),
  handler: async (args: { type?: 'owned' | 'shared' | 'partial' } = {}) => {
    try {
      const queryParams = args.type ? { type: args.type } : undefined;
      const forms = await fetchFormsAPI<Form[]>('/forms', { queryParams });
      if (!forms || forms.length === 0) {
        return { content: [{ type: 'text' as const, text: 'No forms found.' }] };
      }
      return {
        content: [
          {
            type: 'text' as const,
            text: `Forms (${forms.length}):\n\n${forms.map(formatForm).join('\n')}`,
          },
        ],
      };
    } catch (error) {
      return handleAppError(error, 'Error listing forms', formsStatusMap);
    }
  },
};

export const getFormTool = {
  name: 'get_form',
  title: 'Get Form',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description:
    'Get full details for a form by ID, including questions, options, shares, and metadata.',
  inputSchema: z.object({
    formId: z.number().int().describe('Form ID (from list_forms)'),
  }),
  handler: async (args: { formId: number }) => {
    try {
      const form = await fetchFormsAPI<Form>(`/forms/${args.formId}`);
      return {
        content: [{ type: 'text' as const, text: formatFormDetailed(form) }],
      };
    } catch (error) {
      return handleAppError(error, 'Error getting form', formsStatusMap);
    }
  },
};

export const createFormTool = {
  name: 'create_form',
  title: 'Create Form',
  annotations: {
    readOnlyHint: false,
    destructiveHint: false,
    idempotentHint: false,
    openWorldHint: false,
  },
  description:
    'Create a new empty form. Returns the new form ID. Use update_form to set title/description and create_form_question to add questions.',
  inputSchema: z.object({}),
  handler: async () => {
    try {
      const form = await fetchFormsAPI<Form>('/forms', { method: 'POST', body: {} });
      return {
        content: [{ type: 'text' as const, text: `Form created: ${formatForm(form)}` }],
      };
    } catch (error) {
      return handleAppError(error, 'Error creating form', formsStatusMap);
    }
  },
};

export const cloneFormTool = {
  name: 'clone_form',
  title: 'Clone Form',
  annotations: {
    readOnlyHint: false,
    destructiveHint: false,
    idempotentHint: false,
    openWorldHint: false,
  },
  description: 'Clone an existing form (its questions, options, and settings) into a new form.',
  inputSchema: z.object({
    formId: z.number().int().describe('Form ID to clone'),
  }),
  handler: async (args: { formId: number }) => {
    try {
      const form = await fetchFormsAPI<Form>('/forms', {
        method: 'POST',
        body: {},
        queryParams: { fromId: String(args.formId) },
      });
      return {
        content: [{ type: 'text' as const, text: `Form cloned: ${formatForm(form)}` }],
      };
    } catch (error) {
      return handleAppError(error, 'Error cloning form', formsStatusMap);
    }
  },
};

export const updateFormTool = {
  name: 'update_form',
  title: 'Update Form',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: false,
  },
  description:
    'Update form properties. Only provided fields are changed. state: 0=active, 1=closed, 2=archived. expires=0 disables expiration.',
  inputSchema: z.object({
    formId: z.number().int().describe('Form ID'),
    title: z.string().optional(),
    description: z.string().optional(),
    submissionMessage: z.string().optional(),
    expires: z.number().int().optional().describe('Unix timestamp; 0 = no expiration'),
    isAnonymous: z.boolean().optional(),
    submitMultiple: z.boolean().optional(),
    allowEditSubmissions: z.boolean().optional(),
    showExpiration: z.boolean().optional(),
    state: z.union([z.literal(0), z.literal(1), z.literal(2)]).optional(),
  }),
  handler: async (args: {
    formId: number;
    title?: string;
    description?: string;
    submissionMessage?: string;
    expires?: number;
    isAnonymous?: boolean;
    submitMultiple?: boolean;
    allowEditSubmissions?: boolean;
    showExpiration?: boolean;
    state?: 0 | 1 | 2;
  }) => {
    try {
      const { formId, ...rest } = args;
      const body: Record<string, unknown> = {};
      for (const [k, v] of Object.entries(rest)) {
        if (v !== undefined) body[k] = v;
      }
      if (Object.keys(body).length === 0) {
        return {
          content: [{ type: 'text' as const, text: 'No fields provided to update.' }],
          isError: true,
        };
      }
      const form = await fetchFormsAPI<Form>(`/forms/${formId}`, {
        method: 'PATCH',
        body,
      });
      return {
        content: [{ type: 'text' as const, text: `Form updated: ${formatForm(form)}` }],
      };
    } catch (error) {
      return handleAppError(error, 'Error updating form', formsStatusMap);
    }
  },
};

export const transferFormOwnerTool = {
  name: 'transfer_form_owner',
  title: 'Transfer Form Ownership',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: false,
  },
  description:
    'Transfer ownership of a form to another Nextcloud user. The new owner must have access to the Forms app.',
  inputSchema: z.object({
    formId: z.number().int().describe('Form ID'),
    ownerId: z.string().describe('User ID of the new owner'),
  }),
  handler: async (args: { formId: number; ownerId: string }) => {
    try {
      const form = await fetchFormsAPI<Form>(`/forms/${args.formId}`, {
        method: 'PATCH',
        body: { ownerId: args.ownerId },
      });
      return {
        content: [{ type: 'text' as const, text: `Ownership transferred: ${formatForm(form)}` }],
      };
    } catch (error) {
      return handleAppError(error, 'Error transferring form ownership', formsStatusMap);
    }
  },
};

export const deleteFormTool = {
  name: 'delete_form',
  title: 'Delete Form',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: false,
  },
  description: 'Permanently delete a form and all of its submissions. This cannot be undone.',
  inputSchema: z.object({
    formId: z.number().int().describe('Form ID'),
  }),
  handler: async (args: { formId: number }) => {
    try {
      await fetchFormsAPI(`/forms/${args.formId}`, { method: 'DELETE' });
      return {
        content: [{ type: 'text' as const, text: `Form ${args.formId} deleted.` }],
      };
    } catch (error) {
      return handleAppError(error, 'Error deleting form', formsStatusMap);
    }
  },
};

// ─────────────────────────────────────────────────────────────────────────────
// Questions
// ─────────────────────────────────────────────────────────────────────────────

export const listFormQuestionsTool = {
  name: 'list_form_questions',
  title: 'List Form Questions',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description: 'List all questions for a form, in display order.',
  inputSchema: z.object({
    formId: z.number().int().describe('Form ID'),
  }),
  handler: async (args: { formId: number }) => {
    try {
      const questions = await fetchFormsAPI<FormQuestion[]>(`/forms/${args.formId}/questions`);
      if (!questions || questions.length === 0) {
        return { content: [{ type: 'text' as const, text: 'No questions for this form.' }] };
      }
      return {
        content: [
          {
            type: 'text' as const,
            text: `Questions (${questions.length}):\n\n${questions.map(formatQuestion).join('\n')}`,
          },
        ],
      };
    } catch (error) {
      return handleAppError(error, 'Error listing questions', formsStatusMap);
    }
  },
};

export const createFormQuestionTool = {
  name: 'create_form_question',
  title: 'Create Form Question',
  annotations: {
    readOnlyHint: false,
    destructiveHint: false,
    idempotentHint: false,
    openWorldHint: false,
  },
  description:
    'Add a question to a form. For choice questions use type "multiple" (checkbox), "multiple_unique" (radio), or "dropdown" — then call create_form_options.',
  inputSchema: z.object({
    formId: z.number().int().describe('Form ID'),
    type: z.enum(QUESTION_TYPES).describe('Question type'),
    text: z.string().optional().describe('Question text (optional at creation)'),
  }),
  handler: async (args: { formId: number; type: QuestionType; text?: string }) => {
    try {
      const body: Record<string, unknown> = { type: args.type };
      if (args.text !== undefined) body.text = args.text;
      const question = await fetchFormsAPI<FormQuestion>(`/forms/${args.formId}/questions`, {
        method: 'POST',
        body,
      });
      return {
        content: [{ type: 'text' as const, text: `Question created: ${formatQuestion(question)}` }],
      };
    } catch (error) {
      return handleAppError(error, 'Error creating question', formsStatusMap);
    }
  },
};

export const updateFormQuestionTool = {
  name: 'update_form_question',
  title: 'Update Form Question',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: false,
  },
  description:
    'Update question properties (text, requirement, type, etc.). Only provided fields are changed.',
  inputSchema: z.object({
    formId: z.number().int().describe('Form ID'),
    questionId: z.number().int().describe('Question ID'),
    text: z.string().optional(),
    name: z.string().optional().describe('Internal name / shortcode'),
    isRequired: z.boolean().optional(),
    type: z.enum(QUESTION_TYPES).optional(),
    extraSettings: z
      .record(z.string(), z.unknown())
      .optional()
      .describe('Question-type-specific settings (e.g. validationRegex, allowedFileTypes)'),
  }),
  handler: async (args: {
    formId: number;
    questionId: number;
    text?: string;
    name?: string;
    isRequired?: boolean;
    type?: QuestionType;
    extraSettings?: Record<string, unknown>;
  }) => {
    try {
      const { formId, questionId, ...rest } = args;
      const body: Record<string, unknown> = {};
      for (const [k, v] of Object.entries(rest)) {
        if (v !== undefined) body[k] = v;
      }
      if (Object.keys(body).length === 0) {
        return {
          content: [{ type: 'text' as const, text: 'No fields provided to update.' }],
          isError: true,
        };
      }
      const question = await fetchFormsAPI<FormQuestion>(
        `/forms/${formId}/questions/${questionId}`,
        { method: 'PATCH', body }
      );
      return {
        content: [{ type: 'text' as const, text: `Question updated: ${formatQuestion(question)}` }],
      };
    } catch (error) {
      return handleAppError(error, 'Error updating question', formsStatusMap);
    }
  },
};

export const reorderFormQuestionsTool = {
  name: 'reorder_form_questions',
  title: 'Reorder Form Questions',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: false,
  },
  description:
    'Reorder all questions in a form. Pass the full list of question IDs in the desired order.',
  inputSchema: z.object({
    formId: z.number().int().describe('Form ID'),
    newOrder: z
      .array(z.number().int())
      .min(1)
      .describe('Full list of question IDs in desired order'),
  }),
  handler: async (args: { formId: number; newOrder: number[] }) => {
    try {
      await fetchFormsAPI(`/forms/${args.formId}/questions`, {
        method: 'PATCH',
        body: { newOrder: args.newOrder },
      });
      return {
        content: [{ type: 'text' as const, text: `Reordered ${args.newOrder.length} questions.` }],
      };
    } catch (error) {
      return handleAppError(error, 'Error reordering questions', formsStatusMap);
    }
  },
};

export const deleteFormQuestionTool = {
  name: 'delete_form_question',
  title: 'Delete Form Question',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: false,
  },
  description: 'Delete a question from a form.',
  inputSchema: z.object({
    formId: z.number().int().describe('Form ID'),
    questionId: z.number().int().describe('Question ID'),
  }),
  handler: async (args: { formId: number; questionId: number }) => {
    try {
      await fetchFormsAPI(`/forms/${args.formId}/questions/${args.questionId}`, {
        method: 'DELETE',
      });
      return {
        content: [{ type: 'text' as const, text: `Question ${args.questionId} deleted.` }],
      };
    } catch (error) {
      return handleAppError(error, 'Error deleting question', formsStatusMap);
    }
  },
};

// ─────────────────────────────────────────────────────────────────────────────
// Options
// ─────────────────────────────────────────────────────────────────────────────

export const createFormOptionsTool = {
  name: 'create_form_options',
  title: 'Create Form Options',
  annotations: {
    readOnlyHint: false,
    destructiveHint: false,
    idempotentHint: false,
    openWorldHint: false,
  },
  description:
    'Add one or more options to a choice question (multiple/multiple_unique/dropdown). Pass an array of option texts.',
  inputSchema: z.object({
    formId: z.number().int().describe('Form ID'),
    questionId: z.number().int().describe('Question ID'),
    optionTexts: z.array(z.string().min(1)).min(1).describe('One or more option texts to create'),
  }),
  handler: async (args: { formId: number; questionId: number; optionTexts: string[] }) => {
    try {
      const options = await fetchFormsAPI<FormOption[]>(
        `/forms/${args.formId}/questions/${args.questionId}/options`,
        { method: 'POST', body: { optionTexts: args.optionTexts } }
      );
      const list = options && options.length ? options.map(formatOption).join('\n') : '(none)';
      return {
        content: [
          {
            type: 'text' as const,
            text: `Options created (${options?.length ?? 0}):\n${list}`,
          },
        ],
      };
    } catch (error) {
      return handleAppError(error, 'Error creating options', formsStatusMap);
    }
  },
};

export const updateFormOptionTool = {
  name: 'update_form_option',
  title: 'Update Form Option',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: false,
  },
  description: "Update a choice option's text (and optionally its order).",
  inputSchema: z.object({
    formId: z.number().int().describe('Form ID'),
    questionId: z.number().int().describe('Question ID'),
    optionId: z.number().int().describe('Option ID'),
    text: z.string().optional(),
    order: z.number().int().optional(),
  }),
  handler: async (args: {
    formId: number;
    questionId: number;
    optionId: number;
    text?: string;
    order?: number;
  }) => {
    try {
      const { formId, questionId, optionId, ...rest } = args;
      const body: Record<string, unknown> = {};
      for (const [k, v] of Object.entries(rest)) {
        if (v !== undefined) body[k] = v;
      }
      if (Object.keys(body).length === 0) {
        return {
          content: [{ type: 'text' as const, text: 'No fields provided to update.' }],
          isError: true,
        };
      }
      const option = await fetchFormsAPI<FormOption>(
        `/forms/${formId}/questions/${questionId}/options/${optionId}`,
        { method: 'PATCH', body }
      );
      return {
        content: [{ type: 'text' as const, text: `Option updated: ${formatOption(option)}` }],
      };
    } catch (error) {
      return handleAppError(error, 'Error updating option', formsStatusMap);
    }
  },
};

export const reorderFormOptionsTool = {
  name: 'reorder_form_options',
  title: 'Reorder Form Options',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: false,
  },
  description:
    'Reorder all options for a choice question. Pass the full list of option IDs in the desired order.',
  inputSchema: z.object({
    formId: z.number().int().describe('Form ID'),
    questionId: z.number().int().describe('Question ID'),
    newOrder: z.array(z.number().int()).min(1).describe('Full list of option IDs in desired order'),
  }),
  handler: async (args: { formId: number; questionId: number; newOrder: number[] }) => {
    try {
      await fetchFormsAPI(`/forms/${args.formId}/questions/${args.questionId}/options/reorder`, {
        method: 'PATCH',
        body: { newOrder: args.newOrder },
      });
      return {
        content: [{ type: 'text' as const, text: `Reordered ${args.newOrder.length} options.` }],
      };
    } catch (error) {
      return handleAppError(error, 'Error reordering options', formsStatusMap);
    }
  },
};

export const deleteFormOptionTool = {
  name: 'delete_form_option',
  title: 'Delete Form Option',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: false,
  },
  description: 'Delete an option from a choice question.',
  inputSchema: z.object({
    formId: z.number().int().describe('Form ID'),
    questionId: z.number().int().describe('Question ID'),
    optionId: z.number().int().describe('Option ID'),
  }),
  handler: async (args: { formId: number; questionId: number; optionId: number }) => {
    try {
      await fetchFormsAPI(
        `/forms/${args.formId}/questions/${args.questionId}/options/${args.optionId}`,
        { method: 'DELETE' }
      );
      return {
        content: [{ type: 'text' as const, text: `Option ${args.optionId} deleted.` }],
      };
    } catch (error) {
      return handleAppError(error, 'Error deleting option', formsStatusMap);
    }
  },
};

// ─────────────────────────────────────────────────────────────────────────────
// Shares
// ─────────────────────────────────────────────────────────────────────────────

export const createFormShareTool = {
  name: 'create_form_share',
  title: 'Create Form Share',
  annotations: {
    readOnlyHint: false,
    destructiveHint: false,
    idempotentHint: false,
    openWorldHint: false,
  },
  description:
    'Share a form. type="user" shares with a Nextcloud user (shareWith=userId), "group" with a group (shareWith=groupId), "link" creates a public link (shareWith is the generated hash, omit it at creation).',
  inputSchema: z.object({
    formId: z.number().int().describe('Form ID'),
    type: z.enum(['user', 'group', 'link']).describe('Share type'),
    shareWith: z
      .string()
      .optional()
      .describe('User ID (type=user) or group ID (type=group). Omit for type=link.'),
    permissions: z
      .array(z.enum(PERMISSIONS))
      .optional()
      .describe('Permissions to grant. Defaults to ["submit"] if omitted.'),
  }),
  handler: async (args: {
    formId: number;
    type: 'user' | 'group' | 'link';
    shareWith?: string;
    permissions?: FormPermission[];
  }) => {
    try {
      const shareTypeMap: Record<'user' | 'group' | 'link', ShareType> = {
        user: 0,
        group: 1,
        link: 3,
      };
      const shareTypeValue = shareTypeMap[args.type];
      if ((args.type === 'user' || args.type === 'group') && !args.shareWith) {
        return {
          content: [
            {
              type: 'text' as const,
              text: `shareWith is required for type="${args.type}".`,
            },
          ],
          isError: true,
        };
      }
      const body: Record<string, unknown> = {
        shareType: String(shareTypeValue),
        shareWith: args.shareWith ?? '',
        permissions: args.permissions ?? ['submit'],
      };
      const share = await fetchFormsAPI<FormShare>(`/forms/${args.formId}/shares`, {
        method: 'POST',
        body,
      });
      return {
        content: [{ type: 'text' as const, text: `Share created: ${formatShare(share)}` }],
      };
    } catch (error) {
      return handleAppError(error, 'Error creating share', formsStatusMap);
    }
  },
};

export const updateFormShareTool = {
  name: 'update_form_share',
  title: 'Update Form Share',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: false,
  },
  description: 'Update the permissions on an existing form share.',
  inputSchema: z.object({
    formId: z.number().int().describe('Form ID'),
    shareId: z.number().int().describe('Share ID (from get_form)'),
    permissions: z.array(z.enum(PERMISSIONS)).min(1).describe('New permission set'),
  }),
  handler: async (args: { formId: number; shareId: number; permissions: FormPermission[] }) => {
    try {
      const share = await fetchFormsAPI<FormShare>(`/forms/${args.formId}/shares/${args.shareId}`, {
        method: 'PATCH',
        body: { permissions: args.permissions },
      });
      return {
        content: [{ type: 'text' as const, text: `Share updated: ${formatShare(share)}` }],
      };
    } catch (error) {
      return handleAppError(error, 'Error updating share', formsStatusMap);
    }
  },
};

export const deleteFormShareTool = {
  name: 'delete_form_share',
  title: 'Delete Form Share',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: false,
  },
  description: 'Revoke a form share.',
  inputSchema: z.object({
    formId: z.number().int().describe('Form ID'),
    shareId: z.number().int().describe('Share ID'),
  }),
  handler: async (args: { formId: number; shareId: number }) => {
    try {
      await fetchFormsAPI(`/forms/${args.formId}/shares/${args.shareId}`, {
        method: 'DELETE',
      });
      return {
        content: [{ type: 'text' as const, text: `Share ${args.shareId} deleted.` }],
      };
    } catch (error) {
      return handleAppError(error, 'Error deleting share', formsStatusMap);
    }
  },
};

// ─────────────────────────────────────────────────────────────────────────────
// Submissions
// ─────────────────────────────────────────────────────────────────────────────

export const listFormSubmissionsTool = {
  name: 'list_form_submissions',
  title: 'List Form Submissions',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description:
    'List submissions for a form. Supports free-text search (query) and pagination (limit/offset). Only form owners/admins see all submissions.',
  inputSchema: z.object({
    formId: z.number().int().describe('Form ID'),
    query: z.string().optional().describe('Free-text search across answer values'),
    limit: z.number().int().min(1).max(500).optional().describe('Max submissions to return'),
    offset: z.number().int().min(0).optional().describe('Offset for pagination'),
  }),
  handler: async (args: { formId: number; query?: string; limit?: number; offset?: number }) => {
    try {
      const queryParams: Record<string, string> = {};
      if (args.query) queryParams.query = args.query;
      if (args.limit !== undefined) queryParams.limit = String(args.limit);
      if (args.offset !== undefined) queryParams.offset = String(args.offset);
      const data = await fetchFormsAPI<{
        submissions: FormSubmission[];
        questions?: FormQuestion[];
      }>(`/forms/${args.formId}/submissions`, {
        queryParams: Object.keys(queryParams).length ? queryParams : undefined,
      });
      const subs = data?.submissions ?? [];
      if (!subs.length) {
        return { content: [{ type: 'text' as const, text: 'No submissions for this form.' }] };
      }
      return {
        content: [
          {
            type: 'text' as const,
            text: `Submissions (${subs.length}):\n\n${subs.map(formatSubmission).join('\n\n')}`,
          },
        ],
      };
    } catch (error) {
      return handleAppError(error, 'Error listing submissions', formsStatusMap);
    }
  },
};

export const getFormSubmissionTool = {
  name: 'get_form_submission',
  title: 'Get Form Submission',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description: 'Get a single submission with all of its answers.',
  inputSchema: z.object({
    formId: z.number().int().describe('Form ID'),
    submissionId: z.number().int().describe('Submission ID'),
  }),
  handler: async (args: { formId: number; submissionId: number }) => {
    try {
      const submission = await fetchFormsAPI<FormSubmission>(
        `/forms/${args.formId}/submissions/${args.submissionId}`
      );
      return {
        content: [{ type: 'text' as const, text: formatSubmission(submission) }],
      };
    } catch (error) {
      return handleAppError(error, 'Error getting submission', formsStatusMap);
    }
  },
};

export const createFormSubmissionTool = {
  name: 'create_form_submission',
  title: 'Create Form Submission',
  annotations: {
    readOnlyHint: false,
    destructiveHint: false,
    idempotentHint: false,
    openWorldHint: false,
  },
  description:
    'Submit answers to a form. `answers` is an object keyed by question ID; values are always arrays. For text questions: ["My answer"]. For choice questions: [optionId1, optionId2] (numeric IDs). Public links require `shareHash`.',
  inputSchema: z.object({
    formId: z.number().int().describe('Form ID'),
    answers: z
      .record(z.string(), z.array(z.union([z.string(), z.number()])))
      .describe('Object keyed by question ID; each value is an array of strings or option IDs'),
    shareHash: z
      .string()
      .optional()
      .describe('Share hash — required when submitting via a public link'),
  }),
  handler: async (args: {
    formId: number;
    answers: Record<string, (string | number)[]>;
    shareHash?: string;
  }) => {
    try {
      const body: Record<string, unknown> = { answers: args.answers };
      if (args.shareHash) body.shareHash = args.shareHash;
      await fetchFormsAPI(`/forms/${args.formId}/submissions`, {
        method: 'POST',
        body,
      });
      return {
        content: [{ type: 'text' as const, text: `Submission recorded for form ${args.formId}.` }],
      };
    } catch (error) {
      return handleAppError(error, 'Error creating submission', formsStatusMap);
    }
  },
};

export const deleteFormSubmissionTool = {
  name: 'delete_form_submission',
  title: 'Delete Form Submission',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: false,
  },
  description: 'Delete a single submission by ID.',
  inputSchema: z.object({
    formId: z.number().int().describe('Form ID'),
    submissionId: z.number().int().describe('Submission ID'),
  }),
  handler: async (args: { formId: number; submissionId: number }) => {
    try {
      await fetchFormsAPI(`/forms/${args.formId}/submissions/${args.submissionId}`, {
        method: 'DELETE',
      });
      return {
        content: [{ type: 'text' as const, text: `Submission ${args.submissionId} deleted.` }],
      };
    } catch (error) {
      return handleAppError(error, 'Error deleting submission', formsStatusMap);
    }
  },
};

export const deleteAllFormSubmissionsTool = {
  name: 'delete_all_form_submissions',
  title: 'Delete All Form Submissions',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: false,
  },
  description:
    'Delete every submission for a form. The form itself is kept. This cannot be undone.',
  inputSchema: z.object({
    formId: z.number().int().describe('Form ID'),
  }),
  handler: async (args: { formId: number }) => {
    try {
      await fetchFormsAPI(`/forms/${args.formId}/submissions`, { method: 'DELETE' });
      return {
        content: [
          {
            type: 'text' as const,
            text: `All submissions deleted for form ${args.formId}.`,
          },
        ],
      };
    } catch (error) {
      return handleAppError(error, 'Error deleting submissions', formsStatusMap);
    }
  },
};

export const exportFormSubmissionsTool = {
  name: 'export_form_submissions',
  title: 'Export Form Submissions',
  annotations: {
    readOnlyHint: false,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description:
    "Export a form's submissions as a spreadsheet into the user's Nextcloud storage. Returns the destination path. Use read_file / get_file_info afterwards to work with the export.",
  inputSchema: z.object({
    formId: z.number().int().describe('Form ID'),
    path: z.string().describe('Target folder path inside the user\'s Nextcloud (e.g. "/Exports")'),
    fileFormat: z.enum(['csv', 'ods', 'xlsx']).describe('Export file format'),
  }),
  handler: async (args: { formId: number; path: string; fileFormat: 'csv' | 'ods' | 'xlsx' }) => {
    try {
      const result = await fetchFormsAPI<string | { fileName?: string; path?: string }>(
        `/forms/${args.formId}/submissions/export`,
        {
          method: 'POST',
          body: { path: args.path, fileFormat: args.fileFormat },
        }
      );
      const where =
        typeof result === 'string'
          ? result
          : (result?.path ??
            result?.fileName ??
            `${args.path}/form-${args.formId}.${args.fileFormat}`);
      return {
        content: [{ type: 'text' as const, text: `Submissions exported to ${where}.` }],
      };
    } catch (error) {
      return handleAppError(error, 'Error exporting submissions', formsStatusMap);
    }
  },
};

export const formsTools = [
  // Forms
  listFormsTool,
  getFormTool,
  createFormTool,
  cloneFormTool,
  updateFormTool,
  transferFormOwnerTool,
  deleteFormTool,
  // Questions
  listFormQuestionsTool,
  createFormQuestionTool,
  updateFormQuestionTool,
  reorderFormQuestionsTool,
  deleteFormQuestionTool,
  // Options
  createFormOptionsTool,
  updateFormOptionTool,
  reorderFormOptionsTool,
  deleteFormOptionTool,
  // Shares
  createFormShareTool,
  updateFormShareTool,
  deleteFormShareTool,
  // Submissions
  listFormSubmissionsTool,
  getFormSubmissionTool,
  createFormSubmissionTool,
  deleteFormSubmissionTool,
  deleteAllFormSubmissionsTool,
  exportFormSubmissionsTool,
];
