// SPDX-License-Identifier: MIT

import { z } from 'zod';
import { fetchNotesAPI, type Note } from '../../client/notes.js';
import { handleAppError } from '../error-utils.js';
import { bool, int, str, structuredResult } from '../structured.js';

/**
 * Nextcloud Notes App Tools
 * Uses the Notes REST API v1 (/index.php/apps/notes/api/v1)
 */

const notesStatusMap: Record<number, string> = {
  404: 'Note not found.',
  403: 'Note is read-only.',
  412: 'Conflict: note was modified by someone else. Fetch the latest version and retry.',
};

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

/** A note as Notes API v1 returns it (GET/POST /notes). */
const NoteSchema = z.object({
  id: z.number().int(),
  title: z.string().nullable(),
  content: z.string().nullable().describe('Markdown'),
  category: z.string().nullable().describe('Empty for none; maps to a subfolder'),
  favorite: z.boolean().nullable(),
  readonly: z.boolean().nullable(),
  modified: z.number().int().nullable().describe('Last modified, Unix seconds'),
  etag: z.string().nullable().describe('For If-Match on update'),
});

function noteData(note: Note): z.input<typeof NoteSchema> {
  return {
    id: note.id,
    title: str(note.title),
    content: str(note.content),
    category: str(note.category),
    favorite: bool(note.favorite),
    readonly: bool(note.readonly),
    modified: int(note.modified),
    etag: str(note.etag),
  };
}

export const listNotesTool = {
  name: 'list_notes',
  title: 'List Notes',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
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
      return handleAppError(error, 'Error listing notes', notesStatusMap);
    }
  },
};

export const getNoteTool = {
  name: 'get_note',
  title: 'Get Note',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description: 'Get the full content of a note by its ID.',
  inputSchema: z.object({
    id: z.number().int().describe('Note ID (from list_notes)'),
  }),
  outputSchema: NoteSchema,
  handler: async (args: { id: number }) => {
    try {
      const note = await fetchNotesAPI<Note>(`/notes/${args.id}`);
      return structuredResult(NoteSchema, noteData(note), `# ${note.title}\n\n${note.content}`);
    } catch (error) {
      return handleAppError(error, 'Error getting note', notesStatusMap);
    }
  },
};

export const createNoteTool = {
  name: 'create_note',
  title: 'Create Note',
  annotations: {
    readOnlyHint: false,
    destructiveHint: false,
    idempotentHint: false,
    openWorldHint: false,
  },
  description: 'Create a new note in Nextcloud Notes.',
  inputSchema: z.object({
    title: z.string().describe('Title of the note'),
    content: z.string().describe('Content of the note (Markdown)'),
    category: z.string().optional().describe('Category (maps to a subfolder)'),
    favorite: z.boolean().optional().describe('Mark as favorite'),
  }),
  outputSchema: NoteSchema,
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
      return structuredResult(NoteSchema, noteData(note), `Note created: ${formatNote(note)}`);
    } catch (error) {
      return handleAppError(error, 'Error creating note', notesStatusMap);
    }
  },
};

export const updateNoteTool = {
  name: 'update_note',
  title: 'Update Note',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: false,
  },
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
      return handleAppError(error, 'Error updating note', notesStatusMap);
    }
  },
};

export const deleteNoteTool = {
  name: 'delete_note',
  title: 'Delete Note',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: false,
  },
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
      return handleAppError(error, 'Error deleting note', notesStatusMap);
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
