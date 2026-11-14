// SPDX-License-Identifier: MIT

import { z } from 'zod';
import {
  decodeXmlEntities,
  encodeXmlEntities,
  fetchCalDAV,
  nsTagContent,
} from '../../client/caldav.js';
import { getWebDAVClient, type DetailedFileStat } from '../../client/webdav.js';
import { davPath } from '../dav-utils.js';
import { getNextcloudConfig } from '../types.js';

/**
 * Nextcloud file comments: the Comments tab of the Files sidebar.
 *
 * The comments app has no OCS API. Comments live in the WebDAV collection
 * `/remote.php/dav/comments/files/<fileId>` (apps/dav/lib/Comments): a
 * `oc:filter-comments` REPORT lists them, a JSON POST creates one, PROPPATCH of
 * `oc:message` edits one, DELETE removes one, and PROPPATCH of `oc:readMarker`
 * on the collection marks them read. Only a comment's author may edit or
 * delete it.
 */

/** IComment::MAX_MESSAGE_LENGTH in Nextcloud. */
export const MAX_COMMENT_LENGTH = 1000;
/** The comments REPORT clamps its limit to 1..100. */
const MAX_LIMIT = 100;

export interface FileComment {
  id: string;
  actorId: string;
  actorDisplayName: string;
  creationDateTime: string;
  message: string;
  isUnread: boolean;
  verb: string;
}

type ToolResult = { content: { type: 'text'; text: string }[]; isError?: boolean };

function text(t: string, isError = false): ToolResult {
  return { content: [{ type: 'text' as const, text: t }], ...(isError ? { isError: true } : {}) };
}

const FileArgs = {
  path: z.string().optional().describe("File or folder path, e.g. '/Documents/report.odt'"),
  fileId: z.number().int().optional().describe('File ID, instead of path (from get_file_info)'),
};

/** The file ID the comments are attached to, from `fileId` or by looking up `path`. */
export async function resolveFileId(args: { path?: string; fileId?: number }): Promise<number> {
  if (args.fileId !== undefined) return args.fileId;
  if (!args.path) throw new Error('Pass either path or fileId.');
  let stat: DetailedFileStat;
  try {
    stat = await getWebDAVClient().stat(args.path, { props: ['oc:fileid'] });
  } catch (error) {
    if ((error as { status?: number }).status === 404) {
      throw new Error(`${args.path} does not exist.`, { cause: error });
    }
    throw error;
  }
  const id = Number(stat.props.fileid);
  if (!Number.isInteger(id)) throw new Error(`Nextcloud returned no file ID for ${args.path}.`);
  return id;
}

function collectionUrl(fileId: number, commentId?: string): string {
  const { url } = getNextcloudConfig();
  return commentId === undefined
    ? `${url}${davPath('comments', 'files', fileId)}`
    : `${url}${davPath('comments', 'files', fileId, commentId)}`;
}

function prop(block: string, name: string): string {
  const m = block.match(nsTagContent(name));
  return m ? decodeXmlEntities(m[1]) : '';
}

/** The comments in a filter-comments multistatus, in the order the server sent them. */
export function parseComments(xml: string): FileComment[] {
  const responses = xml.match(
    /<(?:[a-zA-Z][a-zA-Z0-9]*:)?response[\s>][\s\S]*?<\/(?:[a-zA-Z][a-zA-Z0-9]*:)?response>/g
  );
  return (responses ?? []).map((r) => ({
    id: prop(r, 'id'),
    actorId: prop(r, 'actorId'),
    actorDisplayName: prop(r, 'actorDisplayName'),
    creationDateTime: prop(r, 'creationDateTime'),
    message: prop(r, 'message'),
    isUnread: prop(r, 'isUnread') === 'true',
    verb: prop(r, 'verb'),
  }));
}

function formatComment(c: FileComment): string {
  const who = c.actorDisplayName || c.actorId;
  const unread = c.isUnread ? ' (unread)' : '';
  return `- [${c.id}] ${who} (${c.actorId}), ${c.creationDateTime}${unread}\n  ${c.message.replace(/\n/g, '\n  ')}`;
}

async function failure(response: Response, action: string): Promise<ToolResult> {
  const body = await response.text().catch(() => '');
  const reason = body.match(nsTagContent('message'))?.[1];
  const known: Record<number, string> = {
    403: 'Only the author of a comment can change or delete it.',
    404: 'No such file or comment, or no access to it.',
  };
  return text(
    `Error ${action}: ${response.status} ${response.statusText}` +
      (reason
        ? ` — ${decodeXmlEntities(reason)}`
        : known[response.status]
          ? ` — ${known[response.status]}`
          : ''),
    true
  );
}

/** Whether a PROPPATCH multistatus reports the property set with 200. */
function propPatchSucceeded(xml: string): boolean {
  const status = xml.match(nsTagContent('status'))?.[1] ?? '';
  return /\s200\s/.test(` ${status} `);
}

function checkLength(message: string): ToolResult | null {
  if (message.trim() === '') return text('The comment is empty.', true);
  if ([...message].length > MAX_COMMENT_LENGTH) {
    return text(`A comment can have at most ${MAX_COMMENT_LENGTH} characters.`, true);
  }
  return null;
}

function errorText(action: string, error: unknown): ToolResult {
  return text(`Error ${action}: ${error instanceof Error ? error.message : String(error)}`, true);
}

// ---------------------------------------------------------------------------
// list_file_comments
// ---------------------------------------------------------------------------

export const listFileCommentsTool = {
  name: 'list_file_comments',
  title: 'List File Comments',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description:
    'List the comments on a file or folder (the Comments tab in Files), newest first, with ' +
    'their ID, author, time and whether they are unread. Pass path or fileId.',
  inputSchema: z.object({
    ...FileArgs,
    limit: z
      .number()
      .int()
      .min(1)
      .max(MAX_LIMIT)
      .optional()
      .describe(`How many comments to return, at most ${MAX_LIMIT} (default 50)`),
    offset: z.number().int().min(0).optional().describe('Skip this many of the newest comments'),
  }),
  handler: async (args: { path?: string; fileId?: number; limit?: number; offset?: number }) => {
    try {
      const fileId = await resolveFileId(args);
      const body = `<?xml version="1.0" encoding="utf-8"?>
<oc:filter-comments xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">
  <oc:limit>${args.limit ?? 50}</oc:limit>
  <oc:offset>${args.offset ?? 0}</oc:offset>
</oc:filter-comments>`;
      const response = await fetchCalDAV(collectionUrl(fileId), { method: 'REPORT', body });
      if (response.status !== 207) return failure(response, 'listing comments');

      const comments = parseComments(await response.text()).filter((c) => c.verb === 'comment');
      if (comments.length === 0) return text(`No comments on file ${fileId}.`);
      const unread = comments.filter((c) => c.isUnread).length;
      return text(
        `Comments on file ${fileId} (${comments.length}${unread ? `, ${unread} unread` : ''}):\n` +
          comments.map(formatComment).join('\n')
      );
    } catch (error) {
      return errorText('listing comments', error);
    }
  },
};

// ---------------------------------------------------------------------------
// add_file_comment
// ---------------------------------------------------------------------------

export const addFileCommentTool = {
  name: 'add_file_comment',
  title: 'Add File Comment',
  annotations: {
    readOnlyHint: false,
    destructiveHint: false,
    idempotentHint: false,
    openWorldHint: false,
  },
  description:
    'Comment on a file or folder as the signed-in user. Write @userid to mention someone; ' +
    `Nextcloud notifies them. At most ${MAX_COMMENT_LENGTH} characters. Pass path or fileId.`,
  inputSchema: z.object({
    ...FileArgs,
    message: z.string().describe('The comment, plain text'),
  }),
  handler: async (args: { path?: string; fileId?: number; message: string }) => {
    const invalid = checkLength(args.message);
    if (invalid) return invalid;
    try {
      const fileId = await resolveFileId(args);
      const response = await fetchCalDAV(collectionUrl(fileId), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          actorType: 'users',
          verb: 'comment',
          message: args.message,
        }),
      });
      if (response.status !== 201) return failure(response, 'adding the comment');
      const id = lastSegment(response.headers.get('Content-Location'));
      return text(`Added comment${id ? ` ${id}` : ''} to file ${fileId}.`);
    } catch (error) {
      return errorText('adding the comment', error);
    }
  },
};

function lastSegment(location: string | null): string {
  return location?.split('/').filter(Boolean).pop() ?? '';
}

// ---------------------------------------------------------------------------
// update_file_comment
// ---------------------------------------------------------------------------

const CommentIdArg = z
  .string()
  .regex(/^\d+$/, 'A comment ID is a number')
  .describe('Comment ID (from list_file_comments)');

export const updateFileCommentTool = {
  name: 'update_file_comment',
  title: 'Update File Comment',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: false,
  },
  description:
    "Change the text of one of the signed-in user's own comments on a file. Pass path or fileId.",
  inputSchema: z.object({
    ...FileArgs,
    commentId: CommentIdArg,
    message: z.string().describe('The new text'),
  }),
  handler: async (args: { path?: string; fileId?: number; commentId: string; message: string }) => {
    const invalid = checkLength(args.message);
    if (invalid) return invalid;
    try {
      const fileId = await resolveFileId(args);
      const body = `<?xml version="1.0" encoding="utf-8"?>
<d:propertyupdate xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">
  <d:set><d:prop><oc:message>${encodeXmlEntities(args.message)}</oc:message></d:prop></d:set>
</d:propertyupdate>`;
      const response = await fetchCalDAV(collectionUrl(fileId, args.commentId), {
        method: 'PROPPATCH',
        body,
      });
      if (response.status !== 207) return failure(response, 'updating the comment');
      if (!propPatchSucceeded(await response.text())) {
        return text(
          `Error updating the comment: Nextcloud refused the change. ` +
            'Only the author of a comment can change it.',
          true
        );
      }
      return text(`Updated comment ${args.commentId} on file ${fileId}.`);
    } catch (error) {
      return errorText('updating the comment', error);
    }
  },
};

// ---------------------------------------------------------------------------
// delete_file_comment
// ---------------------------------------------------------------------------

export const deleteFileCommentTool = {
  name: 'delete_file_comment',
  title: 'Delete File Comment',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: false,
  },
  description: "Delete one of the signed-in user's own comments on a file. Pass path or fileId.",
  inputSchema: z.object({ ...FileArgs, commentId: CommentIdArg }),
  handler: async (args: { path?: string; fileId?: number; commentId: string }) => {
    try {
      const fileId = await resolveFileId(args);
      const response = await fetchCalDAV(collectionUrl(fileId, args.commentId), {
        method: 'DELETE',
      });
      if (response.status !== 204 && response.status !== 200) {
        return failure(response, 'deleting the comment');
      }
      return text(`Deleted comment ${args.commentId} on file ${fileId}.`);
    } catch (error) {
      return errorText('deleting the comment', error);
    }
  },
};

// ---------------------------------------------------------------------------
// mark_file_comments_read
// ---------------------------------------------------------------------------

export const markFileCommentsReadTool = {
  name: 'mark_file_comments_read',
  title: 'Mark File Comments Read',
  annotations: {
    readOnlyHint: false,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description:
    "Mark every comment on a file as read for the signed-in user, as opening the file's " +
    'Comments tab does. Pass path or fileId.',
  inputSchema: z.object(FileArgs),
  handler: async (args: { path?: string; fileId?: number }) => {
    try {
      const fileId = await resolveFileId(args);
      const body = `<?xml version="1.0" encoding="utf-8"?>
<d:propertyupdate xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">
  <d:set><d:prop><oc:readMarker>${new Date().toUTCString()}</oc:readMarker></d:prop></d:set>
</d:propertyupdate>`;
      const response = await fetchCalDAV(collectionUrl(fileId), { method: 'PROPPATCH', body });
      if (response.status !== 207 || !propPatchSucceeded(await response.text())) {
        return failure(response, 'marking comments read');
      }
      return text(`Marked the comments on file ${fileId} as read.`);
    } catch (error) {
      return errorText('marking comments read', error);
    }
  },
};

export const commentsTools = [
  listFileCommentsTool,
  addFileCommentTool,
  updateFileCommentTool,
  deleteFileCommentTool,
  markFileCommentsReadTool,
];
