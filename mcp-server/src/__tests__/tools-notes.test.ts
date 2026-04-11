// SPDX-License-Identifier: MIT

import { describe, it, expect, vi, beforeEach } from 'vitest';

const mockFetchNotesAPI = vi.fn();
vi.mock('../client/notes.js', () => ({
  fetchNotesAPI: (...args: unknown[]) => mockFetchNotesAPI(...args),
}));

describe('Note Tools', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    process.env.NEXTCLOUD_URL = 'https://cloud.example.com';
    process.env.NEXTCLOUD_USER = 'admin';
    process.env.NEXTCLOUD_PASSWORD = 'testpass';
  });

  describe('list_notes', () => {
    it('should return formatted note list', async () => {
      mockFetchNotesAPI.mockResolvedValue([
        {
          id: 1,
          title: 'Meeting Notes',
          category: '',
          favorite: false,
          readonly: false,
          modified: 1734220800,
          etag: 'abc',
          content: '',
        },
        {
          id: 2,
          title: 'Shopping List',
          category: 'personal',
          favorite: true,
          readonly: false,
          modified: 1736467200,
          etag: 'def',
          content: '',
        },
      ]);

      const { listNotesTool } = await import('../tools/apps/notes.js');
      const result = await listNotesTool.handler({});

      expect(result.content[0].text).toContain('Meeting Notes');
      expect(result.content[0].text).toContain('Shopping List');
      expect(result.content[0].text).toContain('Notes (2)');
    });

    it('should filter notes by search term', async () => {
      mockFetchNotesAPI.mockResolvedValue([
        {
          id: 1,
          title: 'Meeting Notes',
          category: '',
          favorite: false,
          readonly: false,
          modified: 1734220800,
          etag: 'abc',
          content: '',
        },
        {
          id: 2,
          title: 'Shopping List',
          category: '',
          favorite: false,
          readonly: false,
          modified: 1736467200,
          etag: 'def',
          content: '',
        },
      ]);

      const { listNotesTool } = await import('../tools/apps/notes.js');
      const result = await listNotesTool.handler({ search: 'meeting' });

      expect(result.content[0].text).toContain('Meeting Notes');
      expect(result.content[0].text).not.toContain('Shopping List');
      expect(result.content[0].text).toContain('Notes (1)');
    });

    it('should handle empty notes', async () => {
      mockFetchNotesAPI.mockResolvedValue([]);

      const { listNotesTool } = await import('../tools/apps/notes.js');
      const result = await listNotesTool.handler({});

      expect(result.content[0].text).toContain('No notes found');
    });

    it('should handle errors', async () => {
      mockFetchNotesAPI.mockRejectedValue(new Error('Network error'));

      const { listNotesTool } = await import('../tools/apps/notes.js');
      const result = await listNotesTool.handler({});

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Network error');
    });
  });

  describe('get_note', () => {
    it('should return note content', async () => {
      mockFetchNotesAPI.mockResolvedValue({
        id: 1,
        title: 'Meeting Notes',
        content: 'Discussed project timeline',
        category: '',
        favorite: false,
        readonly: false,
        modified: 1734220800,
        etag: 'abc',
      });

      const { getNoteTool } = await import('../tools/apps/notes.js');
      const result = await getNoteTool.handler({ id: 1 });

      expect(result.content[0].text).toContain('Meeting Notes');
      expect(result.content[0].text).toContain('Discussed project timeline');
    });

    it('should handle nonexistent note', async () => {
      const { ApiError } = await import('../client/aiquila.js');
      mockFetchNotesAPI.mockRejectedValue(new ApiError(404, 'Not Found', ''));

      const { getNoteTool } = await import('../tools/apps/notes.js');
      const result = await getNoteTool.handler({ id: 999 });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('not found');
    });
  });

  describe('update_note', () => {
    it('should fetch current note then update', async () => {
      const current = {
        id: 1,
        title: 'Old Title',
        content: 'Old content',
        category: '',
        favorite: false,
        readonly: false,
        modified: 1734220800,
        etag: 'abc',
      };
      const updated = { ...current, title: 'New Title', etag: 'xyz' };
      mockFetchNotesAPI.mockResolvedValueOnce(current).mockResolvedValueOnce(updated);

      const { updateNoteTool } = await import('../tools/apps/notes.js');
      const result = await updateNoteTool.handler({ id: 1, title: 'New Title' });

      expect(result.content[0].text).toContain('New Title');
      expect(mockFetchNotesAPI).toHaveBeenCalledTimes(2);
    });

    it('should handle conflict (412)', async () => {
      const { ApiError } = await import('../client/aiquila.js');
      const current = {
        id: 1,
        title: 'Note',
        content: 'Content',
        category: '',
        favorite: false,
        readonly: false,
        modified: 1734220800,
        etag: 'abc',
      };
      mockFetchNotesAPI
        .mockResolvedValueOnce(current)
        .mockRejectedValueOnce(new ApiError(412, 'Precondition Failed', ''));

      const { updateNoteTool } = await import('../tools/apps/notes.js');
      const result = await updateNoteTool.handler({ id: 1, content: 'New content' });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Conflict');
    });
  });

  describe('delete_note', () => {
    it('should delete a note by id', async () => {
      mockFetchNotesAPI.mockResolvedValue(undefined);

      const { deleteNoteTool } = await import('../tools/apps/notes.js');
      const result = await deleteNoteTool.handler({ id: 1 });

      expect(result.content[0].text).toContain('deleted');
      expect(mockFetchNotesAPI).toHaveBeenCalledWith('/notes/1', { method: 'DELETE' });
    });

    it('should handle nonexistent note on delete', async () => {
      const { ApiError } = await import('../client/aiquila.js');
      mockFetchNotesAPI.mockRejectedValue(new ApiError(404, 'Not Found', ''));

      const { deleteNoteTool } = await import('../tools/apps/notes.js');
      const result = await deleteNoteTool.handler({ id: 999 });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('not found');
    });
  });
});
