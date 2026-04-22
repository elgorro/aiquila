// SPDX-License-Identifier: MIT

import { describe, it, expect, vi, beforeEach } from 'vitest';

const mockFetchFormsAPI = vi.fn();
vi.mock('../client/forms.js', () => ({
  fetchFormsAPI: (...args: unknown[]) => mockFetchFormsAPI(...args),
}));

const sampleForm = {
  id: 42,
  hash: 'abcd1234',
  title: 'Lunch survey',
  description: 'Help us plan the team lunch',
  ownerId: 'alice',
  created: 1714078369,
  access: { permitAllUsers: false, showToAllUsers: false },
  expires: 0,
  isAnonymous: false,
  state: 0 as const,
  submitMultiple: false,
  allowEditSubmissions: false,
  showExpiration: false,
  canSubmit: true,
  permissions: ['edit', 'results', 'submit'] as const,
  submissionCount: 3,
};

const sampleQuestion = {
  id: 7,
  formId: 42,
  order: 1,
  type: 'short' as const,
  isRequired: true,
  text: 'What is your favourite cuisine?',
  name: 'favourite',
  options: [],
  extraSettings: {},
};

const sampleSubmission = {
  id: 99,
  formId: 42,
  userId: 'bob',
  userDisplayName: 'Bob',
  timestamp: 1714078400,
  answers: [{ id: 1, submissionId: 99, questionId: 7, questionName: 'favourite', text: 'Italian' }],
};

describe('Forms Tools', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    process.env.NEXTCLOUD_URL = 'https://cloud.example.com';
    process.env.NEXTCLOUD_USER = 'alice';
    process.env.NEXTCLOUD_PASSWORD = 'testpass';
  });

  describe('list_forms', () => {
    it('returns a formatted form list', async () => {
      mockFetchFormsAPI.mockResolvedValue([
        sampleForm,
        { ...sampleForm, id: 43, title: 'Retro feedback', state: 1 as const },
      ]);
      const { listFormsTool } = await import('../tools/apps/forms.js');
      const result = await listFormsTool.handler({});
      expect(result.content[0].text).toContain('Forms (2)');
      expect(result.content[0].text).toContain('Lunch survey');
      expect(result.content[0].text).toContain('Retro feedback');
    });

    it('handles empty list', async () => {
      mockFetchFormsAPI.mockResolvedValue([]);
      const { listFormsTool } = await import('../tools/apps/forms.js');
      const result = await listFormsTool.handler({});
      expect(result.content[0].text).toContain('No forms found');
    });

    it('passes type=shared as a query param', async () => {
      mockFetchFormsAPI.mockResolvedValue([]);
      const { listFormsTool } = await import('../tools/apps/forms.js');
      await listFormsTool.handler({ type: 'shared' });
      expect(mockFetchFormsAPI).toHaveBeenCalledWith('/forms', {
        queryParams: { type: 'shared' },
      });
    });

    it('maps 403 to access denied', async () => {
      const { ApiError } = await import('../client/aiquila.js');
      mockFetchFormsAPI.mockRejectedValue(new ApiError(403, 'Forbidden', ''));
      const { listFormsTool } = await import('../tools/apps/forms.js');
      const result = await listFormsTool.handler({});
      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Access denied');
    });
  });

  describe('get_form', () => {
    it('renders details', async () => {
      mockFetchFormsAPI.mockResolvedValue({ ...sampleForm, questions: [sampleQuestion] });
      const { getFormTool } = await import('../tools/apps/forms.js');
      const result = await getFormTool.handler({ formId: 42 });
      expect(result.content[0].text).toContain('Lunch survey');
      expect(result.content[0].text).toContain('Description: Help us plan');
      expect(result.content[0].text).toContain('Questions: 1');
    });

    it('handles 404', async () => {
      const { ApiError } = await import('../client/aiquila.js');
      mockFetchFormsAPI.mockRejectedValue(new ApiError(404, 'Not Found', ''));
      const { getFormTool } = await import('../tools/apps/forms.js');
      const result = await getFormTool.handler({ formId: 999 });
      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('not found');
    });
  });

  describe('create_form', () => {
    it('posts an empty body', async () => {
      mockFetchFormsAPI.mockResolvedValue(sampleForm);
      const { createFormTool } = await import('../tools/apps/forms.js');
      const result = await createFormTool.handler();
      expect(mockFetchFormsAPI).toHaveBeenCalledWith('/forms', { method: 'POST', body: {} });
      expect(result.content[0].text).toContain('Form created');
    });
  });

  describe('clone_form', () => {
    it('posts with fromId query param', async () => {
      mockFetchFormsAPI.mockResolvedValue({ ...sampleForm, id: 100 });
      const { cloneFormTool } = await import('../tools/apps/forms.js');
      await cloneFormTool.handler({ formId: 42 });
      expect(mockFetchFormsAPI).toHaveBeenCalledWith('/forms', {
        method: 'POST',
        body: {},
        queryParams: { fromId: '42' },
      });
    });
  });

  describe('update_form', () => {
    it('PATCHes only provided fields (no wrapping)', async () => {
      mockFetchFormsAPI.mockResolvedValue(sampleForm);
      const { updateFormTool } = await import('../tools/apps/forms.js');
      await updateFormTool.handler({ formId: 42, title: 'New title', submitMultiple: true });
      expect(mockFetchFormsAPI).toHaveBeenCalledWith('/forms/42', {
        method: 'PATCH',
        body: { title: 'New title', submitMultiple: true },
      });
    });

    it('errors if no fields provided', async () => {
      const { updateFormTool } = await import('../tools/apps/forms.js');
      const result = await updateFormTool.handler({ formId: 42 });
      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('No fields');
      expect(mockFetchFormsAPI).not.toHaveBeenCalled();
    });
  });

  describe('transfer_form_owner', () => {
    it('PATCHes ownerId', async () => {
      mockFetchFormsAPI.mockResolvedValue({ ...sampleForm, ownerId: 'bob' });
      const { transferFormOwnerTool } = await import('../tools/apps/forms.js');
      await transferFormOwnerTool.handler({ formId: 42, ownerId: 'bob' });
      expect(mockFetchFormsAPI).toHaveBeenCalledWith('/forms/42', {
        method: 'PATCH',
        body: { ownerId: 'bob' },
      });
    });
  });

  describe('delete_form', () => {
    it('calls DELETE', async () => {
      mockFetchFormsAPI.mockResolvedValue(undefined);
      const { deleteFormTool } = await import('../tools/apps/forms.js');
      const result = await deleteFormTool.handler({ formId: 42 });
      expect(mockFetchFormsAPI).toHaveBeenCalledWith('/forms/42', { method: 'DELETE' });
      expect(result.content[0].text).toContain('deleted');
    });
  });

  describe('list_form_questions', () => {
    it('lists questions in order', async () => {
      mockFetchFormsAPI.mockResolvedValue([
        sampleQuestion,
        { ...sampleQuestion, id: 8, order: 2, text: 'Dietary restrictions?', type: 'long' },
      ]);
      const { listFormQuestionsTool } = await import('../tools/apps/forms.js');
      const result = await listFormQuestionsTool.handler({ formId: 42 });
      expect(result.content[0].text).toContain('Questions (2)');
      expect(result.content[0].text).toContain('favourite cuisine');
      expect(result.content[0].text).toContain('Dietary restrictions');
    });
  });

  describe('create_form_question', () => {
    it('posts type and optional text', async () => {
      mockFetchFormsAPI.mockResolvedValue(sampleQuestion);
      const { createFormQuestionTool } = await import('../tools/apps/forms.js');
      await createFormQuestionTool.handler({ formId: 42, type: 'short', text: 'Name?' });
      expect(mockFetchFormsAPI).toHaveBeenCalledWith('/forms/42/questions', {
        method: 'POST',
        body: { type: 'short', text: 'Name?' },
      });
    });

    it('omits text when not provided', async () => {
      mockFetchFormsAPI.mockResolvedValue(sampleQuestion);
      const { createFormQuestionTool } = await import('../tools/apps/forms.js');
      await createFormQuestionTool.handler({ formId: 42, type: 'dropdown' });
      expect(mockFetchFormsAPI).toHaveBeenCalledWith('/forms/42/questions', {
        method: 'POST',
        body: { type: 'dropdown' },
      });
    });
  });

  describe('update_form_question', () => {
    it('PATCHes direct fields', async () => {
      mockFetchFormsAPI.mockResolvedValue(sampleQuestion);
      const { updateFormQuestionTool } = await import('../tools/apps/forms.js');
      await updateFormQuestionTool.handler({
        formId: 42,
        questionId: 7,
        text: 'New text',
        isRequired: false,
      });
      expect(mockFetchFormsAPI).toHaveBeenCalledWith('/forms/42/questions/7', {
        method: 'PATCH',
        body: { text: 'New text', isRequired: false },
      });
    });

    it('errors on empty update', async () => {
      const { updateFormQuestionTool } = await import('../tools/apps/forms.js');
      const result = await updateFormQuestionTool.handler({ formId: 42, questionId: 7 });
      expect(result.isError).toBe(true);
      expect(mockFetchFormsAPI).not.toHaveBeenCalled();
    });
  });

  describe('reorder_form_questions', () => {
    it('sends newOrder array', async () => {
      mockFetchFormsAPI.mockResolvedValue(undefined);
      const { reorderFormQuestionsTool } = await import('../tools/apps/forms.js');
      const result = await reorderFormQuestionsTool.handler({
        formId: 42,
        newOrder: [3, 1, 2],
      });
      expect(mockFetchFormsAPI).toHaveBeenCalledWith('/forms/42/questions', {
        method: 'PATCH',
        body: { newOrder: [3, 1, 2] },
      });
      expect(result.content[0].text).toContain('Reordered 3 questions');
    });
  });

  describe('delete_form_question', () => {
    it('DELETEs at the question URL', async () => {
      mockFetchFormsAPI.mockResolvedValue(undefined);
      const { deleteFormQuestionTool } = await import('../tools/apps/forms.js');
      await deleteFormQuestionTool.handler({ formId: 42, questionId: 7 });
      expect(mockFetchFormsAPI).toHaveBeenCalledWith('/forms/42/questions/7', {
        method: 'DELETE',
      });
    });
  });

  describe('create_form_options', () => {
    it('sends optionTexts array', async () => {
      mockFetchFormsAPI.mockResolvedValue([
        { id: 1, questionId: 7, order: 1, text: 'Pizza' },
        { id: 2, questionId: 7, order: 2, text: 'Sushi' },
      ]);
      const { createFormOptionsTool } = await import('../tools/apps/forms.js');
      const result = await createFormOptionsTool.handler({
        formId: 42,
        questionId: 7,
        optionTexts: ['Pizza', 'Sushi'],
      });
      expect(mockFetchFormsAPI).toHaveBeenCalledWith('/forms/42/questions/7/options', {
        method: 'POST',
        body: { optionTexts: ['Pizza', 'Sushi'] },
      });
      expect(result.content[0].text).toContain('Options created (2)');
      expect(result.content[0].text).toContain('Pizza');
    });
  });

  describe('update_form_option', () => {
    it('PATCHes direct fields', async () => {
      mockFetchFormsAPI.mockResolvedValue({ id: 1, questionId: 7, order: 2, text: 'Ramen' });
      const { updateFormOptionTool } = await import('../tools/apps/forms.js');
      await updateFormOptionTool.handler({
        formId: 42,
        questionId: 7,
        optionId: 1,
        text: 'Ramen',
      });
      expect(mockFetchFormsAPI).toHaveBeenCalledWith('/forms/42/questions/7/options/1', {
        method: 'PATCH',
        body: { text: 'Ramen' },
      });
    });
  });

  describe('reorder_form_options', () => {
    it('hits the reorder endpoint', async () => {
      mockFetchFormsAPI.mockResolvedValue(undefined);
      const { reorderFormOptionsTool } = await import('../tools/apps/forms.js');
      await reorderFormOptionsTool.handler({ formId: 42, questionId: 7, newOrder: [2, 1] });
      expect(mockFetchFormsAPI).toHaveBeenCalledWith('/forms/42/questions/7/options/reorder', {
        method: 'PATCH',
        body: { newOrder: [2, 1] },
      });
    });
  });

  describe('delete_form_option', () => {
    it('DELETEs the option URL', async () => {
      mockFetchFormsAPI.mockResolvedValue(undefined);
      const { deleteFormOptionTool } = await import('../tools/apps/forms.js');
      await deleteFormOptionTool.handler({ formId: 42, questionId: 7, optionId: 1 });
      expect(mockFetchFormsAPI).toHaveBeenCalledWith('/forms/42/questions/7/options/1', {
        method: 'DELETE',
      });
    });
  });

  describe('create_form_share', () => {
    it('user share sends shareType=0', async () => {
      mockFetchFormsAPI.mockResolvedValue({
        id: 1,
        formId: 42,
        shareType: 0,
        shareWith: 'bob',
        permissions: ['submit'],
      });
      const { createFormShareTool } = await import('../tools/apps/forms.js');
      await createFormShareTool.handler({ formId: 42, type: 'user', shareWith: 'bob' });
      expect(mockFetchFormsAPI).toHaveBeenCalledWith('/forms/42/shares', {
        method: 'POST',
        body: { shareType: '0', shareWith: 'bob', permissions: ['submit'] },
      });
    });

    it('link share does not need shareWith', async () => {
      mockFetchFormsAPI.mockResolvedValue({
        id: 2,
        formId: 42,
        shareType: 3,
        shareWith: 'hash1234',
      });
      const { createFormShareTool } = await import('../tools/apps/forms.js');
      await createFormShareTool.handler({ formId: 42, type: 'link' });
      expect(mockFetchFormsAPI).toHaveBeenCalledWith('/forms/42/shares', {
        method: 'POST',
        body: { shareType: '3', shareWith: '', permissions: ['submit'] },
      });
    });

    it('user share requires shareWith', async () => {
      const { createFormShareTool } = await import('../tools/apps/forms.js');
      const result = await createFormShareTool.handler({ formId: 42, type: 'user' });
      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('shareWith');
      expect(mockFetchFormsAPI).not.toHaveBeenCalled();
    });

    it('custom permissions pass through', async () => {
      mockFetchFormsAPI.mockResolvedValue({
        id: 3,
        formId: 42,
        shareType: 1,
        shareWith: 'admins',
      });
      const { createFormShareTool } = await import('../tools/apps/forms.js');
      await createFormShareTool.handler({
        formId: 42,
        type: 'group',
        shareWith: 'admins',
        permissions: ['submit', 'results'],
      });
      expect(mockFetchFormsAPI).toHaveBeenCalledWith('/forms/42/shares', {
        method: 'POST',
        body: { shareType: '1', shareWith: 'admins', permissions: ['submit', 'results'] },
      });
    });
  });

  describe('update_form_share', () => {
    it('PATCHes permissions', async () => {
      mockFetchFormsAPI.mockResolvedValue({
        id: 1,
        formId: 42,
        shareType: 0,
        shareWith: 'bob',
        permissions: ['submit', 'results'],
      });
      const { updateFormShareTool } = await import('../tools/apps/forms.js');
      await updateFormShareTool.handler({
        formId: 42,
        shareId: 1,
        permissions: ['submit', 'results'],
      });
      expect(mockFetchFormsAPI).toHaveBeenCalledWith('/forms/42/shares/1', {
        method: 'PATCH',
        body: { permissions: ['submit', 'results'] },
      });
    });
  });

  describe('delete_form_share', () => {
    it('DELETEs the share', async () => {
      mockFetchFormsAPI.mockResolvedValue(undefined);
      const { deleteFormShareTool } = await import('../tools/apps/forms.js');
      await deleteFormShareTool.handler({ formId: 42, shareId: 1 });
      expect(mockFetchFormsAPI).toHaveBeenCalledWith('/forms/42/shares/1', {
        method: 'DELETE',
      });
    });
  });

  describe('list_form_submissions', () => {
    it('formats submissions with answers', async () => {
      mockFetchFormsAPI.mockResolvedValue({
        submissions: [sampleSubmission],
        questions: [sampleQuestion],
      });
      const { listFormSubmissionsTool } = await import('../tools/apps/forms.js');
      const result = await listFormSubmissionsTool.handler({ formId: 42 });
      expect(result.content[0].text).toContain('Submissions (1)');
      expect(result.content[0].text).toContain('Bob');
      expect(result.content[0].text).toContain('favourite: Italian');
    });

    it('forwards query/limit/offset', async () => {
      mockFetchFormsAPI.mockResolvedValue({ submissions: [] });
      const { listFormSubmissionsTool } = await import('../tools/apps/forms.js');
      await listFormSubmissionsTool.handler({
        formId: 42,
        query: 'pizza',
        limit: 50,
        offset: 0,
      });
      expect(mockFetchFormsAPI).toHaveBeenCalledWith('/forms/42/submissions', {
        queryParams: { query: 'pizza', limit: '50', offset: '0' },
      });
    });

    it('handles empty', async () => {
      mockFetchFormsAPI.mockResolvedValue({ submissions: [] });
      const { listFormSubmissionsTool } = await import('../tools/apps/forms.js');
      const result = await listFormSubmissionsTool.handler({ formId: 42 });
      expect(result.content[0].text).toContain('No submissions');
    });
  });

  describe('get_form_submission', () => {
    it('returns a single submission', async () => {
      mockFetchFormsAPI.mockResolvedValue(sampleSubmission);
      const { getFormSubmissionTool } = await import('../tools/apps/forms.js');
      const result = await getFormSubmissionTool.handler({ formId: 42, submissionId: 99 });
      expect(result.content[0].text).toContain('Bob');
      expect(result.content[0].text).toContain('favourite: Italian');
    });
  });

  describe('create_form_submission', () => {
    it('posts answers keyed by question id', async () => {
      mockFetchFormsAPI.mockResolvedValue(undefined);
      const { createFormSubmissionTool } = await import('../tools/apps/forms.js');
      const result = await createFormSubmissionTool.handler({
        formId: 42,
        answers: { '7': ['Italian'], '8': [27, 32] },
      });
      expect(mockFetchFormsAPI).toHaveBeenCalledWith('/forms/42/submissions', {
        method: 'POST',
        body: { answers: { '7': ['Italian'], '8': [27, 32] } },
      });
      expect(result.content[0].text).toContain('recorded');
    });

    it('includes shareHash when provided', async () => {
      mockFetchFormsAPI.mockResolvedValue(undefined);
      const { createFormSubmissionTool } = await import('../tools/apps/forms.js');
      await createFormSubmissionTool.handler({
        formId: 42,
        answers: { '7': ['Thai'] },
        shareHash: 'public-link-hash',
      });
      expect(mockFetchFormsAPI).toHaveBeenCalledWith('/forms/42/submissions', {
        method: 'POST',
        body: { answers: { '7': ['Thai'] }, shareHash: 'public-link-hash' },
      });
    });
  });

  describe('delete_form_submission', () => {
    it('DELETEs a single submission', async () => {
      mockFetchFormsAPI.mockResolvedValue(undefined);
      const { deleteFormSubmissionTool } = await import('../tools/apps/forms.js');
      await deleteFormSubmissionTool.handler({ formId: 42, submissionId: 99 });
      expect(mockFetchFormsAPI).toHaveBeenCalledWith('/forms/42/submissions/99', {
        method: 'DELETE',
      });
    });
  });

  describe('delete_all_form_submissions', () => {
    it('DELETEs the submissions collection', async () => {
      mockFetchFormsAPI.mockResolvedValue(undefined);
      const { deleteAllFormSubmissionsTool } = await import('../tools/apps/forms.js');
      const result = await deleteAllFormSubmissionsTool.handler({ formId: 42 });
      expect(mockFetchFormsAPI).toHaveBeenCalledWith('/forms/42/submissions', {
        method: 'DELETE',
      });
      expect(result.content[0].text).toContain('All submissions deleted');
    });
  });

  describe('export_form_submissions', () => {
    it('POSTs path and fileFormat', async () => {
      mockFetchFormsAPI.mockResolvedValue({ fileName: '/Exports/form-42.csv' });
      const { exportFormSubmissionsTool } = await import('../tools/apps/forms.js');
      const result = await exportFormSubmissionsTool.handler({
        formId: 42,
        path: '/Exports',
        fileFormat: 'csv',
      });
      expect(mockFetchFormsAPI).toHaveBeenCalledWith('/forms/42/submissions/export', {
        method: 'POST',
        body: { path: '/Exports', fileFormat: 'csv' },
      });
      expect(result.content[0].text).toContain('/Exports/form-42.csv');
    });

    it('handles a plain string response', async () => {
      mockFetchFormsAPI.mockResolvedValue('/Exports/form-42.xlsx');
      const { exportFormSubmissionsTool } = await import('../tools/apps/forms.js');
      const result = await exportFormSubmissionsTool.handler({
        formId: 42,
        path: '/Exports',
        fileFormat: 'xlsx',
      });
      expect(result.content[0].text).toContain('/Exports/form-42.xlsx');
    });
  });

  describe('formsTools array', () => {
    it('exports 25 tools with valid shape', async () => {
      const { formsTools } = await import('../tools/apps/forms.js');
      expect(formsTools).toHaveLength(25);
      for (const tool of formsTools) {
        expect(tool.name).toMatch(/^[a-z_]+$/);
        expect(tool.description.length).toBeGreaterThan(0);
        expect(tool.inputSchema).toBeDefined();
        expect(typeof tool.handler).toBe('function');
      }
    });
  });
});
