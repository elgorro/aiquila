import { z } from 'zod';
import { fetchNotesAPI, type Note } from '../../client/notes.js';
import { ApiError } from '../../client/aiquila.js';

/**
 * Nextcloud Notes App Tools
 * Uses the Notes REST API v1 (/index.php/apps/notes/api/v1)
 */

function formatNote(note: Note): string {
  const date = new Date(note.modified * 1000).toISOString();
  const flags = [note.favorite ? 'favorite' : null, note.readonly ? 'readonly' : null]
    .filter(Boolean)
    .join(', ');
  const meta = [note.category ? `category: ${note.category}` : null, flags || null]
    .filter(Boolean)
    .join(' | ');
  return `[${note.id}] ${note.title}${meta ? ` (${meta})` : ''} — modified: ${date}`;
}

function handleError(
  error: unknown,
  context: string
): { content: { type: 'text'; text: string }[]; isError: true } {
  if (error instanceof ApiError) {
    if (error.statusCode === 404) {
      return { content: [{ type: 'text', text: `Note not found.` }], isError: true };
    }
    if (error.statusCode === 403) {
      return { content: [{ type: 'text', text: `Note is read-only.` }], isError: true };
    }
    if (error.statusCode === 412) {
      return {
        content: [
          {
            type: 'text',
            text: `Conflict: note was modified by someone else. Fetch the latest version and retry.`,
          },
        ],
        isError: true,
      };
    }
  }
  return {
    content: [
      {
        type: 'text',
        text: `${context}: ${error instanceof Error ? error.message : String(error)}`,
      },
    ],
    isError: true,
  };
}

export const listNotesTool = {
  name: 'list_notes',
  description:
    'List all notes in Nextcloud Notes. Returns id, title, category, favorite flag, and modification date.',
  inputSchema: z.object({
    category: z.string().optional().describe('Filter by category'),
    search: z.string().optional().describe('Filter notes by title (client-side)'),
  }),
  handler: async (args: { category?: string; search?: string }) => {
    try {
      const params: Record<string, string> = { exclude: 'content' };
      if (args.category) params.category = args.category;

      let notes = await fetchNotesAPI<Note[]>('/notes', { queryParams: params });

      if (args.search) {
        const q = args.search.toLowerCase();
        notes = notes.filter((n) => n.title.toLowerCase().includes(q));
      }

      if (notes.length === 0) {
        return { content: [{ type: 'text' as const, text: 'No notes found.' }] };
      }

      return {
        content: [
          {
            type: 'text' as const,
            text: `Notes (${notes.length}):\n\n${notes.map(formatNote).join('\n')}`,
          },
        ],
      };
    } catch (error) {
      return handleError(error, 'Error listing notes');
    }
  },
};

export const getNoteTool = {
  name: 'get_note',
  description: 'Get the full content of a note by its ID.',
  inputSchema: z.object({
    id: z.number().int().describe('Note ID (from list_notes)'),
  }),
  handler: async (args: { id: number }) => {
    try {
      const note = await fetchNotesAPI<Note>(`/notes/${args.id}`);
      return {
        content: [
          {
            type: 'text' as const,
            text: `# ${note.title}\n\n${note.content}`,
          },
        ],
      };
    } catch (error) {
      return handleError(error, 'Error getting note');
    }
  },
};

export const createNoteTool = {
  name: 'create_note',
  description: 'Create a new note in Nextcloud Notes.',
  inputSchema: z.object({
    title: z.string().describe('Title of the note'),
    content: z.string().describe('Content of the note (Markdown)'),
    category: z.string().optional().describe('Category (maps to a subfolder)'),
    favorite: z.boolean().optional().describe('Mark as favorite'),
  }),
  handler: async (args: {
    title: string;
    content: string;
    category?: string;
    favorite?: boolean;
  }) => {
    try {
      const note = await fetchNotesAPI<Note>('/notes', {
        method: 'POST',
        body: {
          title: args.title,
          content: args.content,
          category: args.category ?? '',
          favorite: args.favorite ?? false,
        },
      });
      return {
        content: [
          {
            type: 'text' as const,
            text: `Note created: ${formatNote(note)}`,
          },
        ],
      };
    } catch (error) {
      return handleError(error, 'Error creating note');
    }
  },
};

export const updateNoteTool = {
  name: 'update_note',
  description: 'Update an existing note. Provide only the fields you want to change.',
  inputSchema: z.object({
    id: z.number().int().describe('Note ID (from list_notes)'),
    title: z.string().optional().describe('New title'),
    content: z.string().optional().describe('New content (Markdown)'),
    category: z.string().optional().describe('New category'),
    favorite: z.boolean().optional().describe('Favorite flag'),
  }),
  handler: async (args: {
    id: number;
    title?: string;
    content?: string;
    category?: string;
    favorite?: boolean;
  }) => {
    try {
      // Fetch current note to get etag and merge fields
      const current = await fetchNotesAPI<Note>(`/notes/${args.id}`);
      const updated = await fetchNotesAPI<Note>(`/notes/${args.id}`, {
        method: 'PUT',
        ifMatch: current.etag,
        body: {
          title: args.title ?? current.title,
          content: args.content ?? current.content,
          category: args.category ?? current.category,
          favorite: args.favorite ?? current.favorite,
        },
      });
      return {
        content: [
          {
            type: 'text' as const,
            text: `Note updated: ${formatNote(updated)}`,
          },
        ],
      };
    } catch (error) {
      return handleError(error, 'Error updating note');
    }
  },
};

export const deleteNoteTool = {
  name: 'delete_note',
  description: 'Delete a note by its ID.',
  inputSchema: z.object({
    id: z.number().int().describe('Note ID (from list_notes)'),
  }),
  handler: async (args: { id: number }) => {
    try {
      await fetchNotesAPI(`/notes/${args.id}`, { method: 'DELETE' });
      return {
        content: [
          {
            type: 'text' as const,
            text: `Note ${args.id} deleted.`,
          },
        ],
      };
    } catch (error) {
      return handleError(error, 'Error deleting note');
    }
  },
};

export const notesTools = [
  listNotesTool,
  getNoteTool,
  createNoteTool,
  updateNoteTool,
  deleteNoteTool,
];
