// SPDX-License-Identifier: MIT

import { z } from 'zod';
import { checkGatedParams, nextcloudMajor } from '../../client/capabilities.js';
import { fetchCalDAV } from '../../client/caldav.js';
import { fetchOCS } from '../../client/ocs.js';
import { davFilePath, davPath } from '../dav-utils.js';
import { getNextcloudConfig } from '../types.js';

/**
 * Inline comments in Nextcloud Text (Text 9, Nextcloud 35).
 *
 * Text has no API for them: a comment thread is part of the Markdown. The
 * commented spot carries a footnote reference whose label starts with
 * `comment-`, and the thread is that footnote's definition, one list item per
 * comment. This is what Text's own serializer writes (the `comment` and
 * `commentItem` nodes in apps/text):
 *
 *     The budget is final.[^comment-1]
 *
 *     [^comment-1]:
 *         - @[Alice](mention://user/alice) *(2026-11-10T09:00:00.000Z)*
 *           Is it? Finance has not signed off.
 *         - @[Bob](mention://user/bob) *(2026-11-10T09:30:00.000Z)*
 *           They did this morning.
 *
 * So these tools read and rewrite the file over WebDAV. Writes carry the ETag
 * of the version read in `If-Match` (RFC 9110 §13.1.1), so a change made in
 * between fails with 412 instead of being overwritten.
 */

const COMMENT_PREFIX = 'comment-';
/** Text 9, which ships with Nextcloud 35, is the first to show these as comments. */
const WRITE_GATES = { message: nextcloudMajor(35) };
const DEFINITION_INDENT = '    ';
const BODY_INDENT = '      ';

export interface TextCommentItem {
  author: string;
  authorLabel: string;
  timestamp: string;
  body: string;
}

export interface TextCommentThread {
  id: string;
  items: TextCommentItem[];
  /** The text just before the first reference, for orientation. */
  anchor: string;
  /** Line range [start, end) of the definition block in the document. */
  start: number;
  end: number;
}

type ToolResult = { content: { type: 'text'; text: string }[]; isError?: boolean };

function text(t: string, isError = false): ToolResult {
  return { content: [{ type: 'text' as const, text: t }], ...(isError ? { isError: true } : {}) };
}

const DEFINITION_RE = /^\[\^(comment-[^\]\s]+)\]:[ \t]*(.*)$/;
const REFERENCE_RE = /\[\^(comment-[^\]\s]+)\](?!:)/g;

function escapeRegExp(s: string): string {
  return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/** Parse the header of a comment item: `@[label](mention://user/id) *(timestamp)* rest`. */
function parseItemHead(head: string): TextCommentItem {
  let rest = head.trim();
  let author = '';
  let authorLabel = '';
  let timestamp = '';
  const mention = rest.match(/^@\[([^\]]*)\]\(mention:\/\/user\/([^)\s]+)\)/);
  if (mention) {
    authorLabel = mention[1];
    try {
      author = decodeURIComponent(mention[2]);
    } catch {
      author = mention[2];
    }
    rest = rest.slice(mention[0].length).trim();
  } else {
    const plain = rest.match(/^@(\S+)/);
    if (plain) {
      authorLabel = plain[1];
      rest = rest.slice(plain[0].length).trim();
    }
  }
  const ts = rest.match(/^[*_]\(([^)]+)\)[*_]/);
  if (ts) {
    timestamp = ts[1];
    rest = rest.slice(ts[0].length).trim();
  }
  return { author, authorLabel, timestamp, body: rest };
}

/** Split a dedented definition body into its comment items. */
function parseItems(lines: string[]): TextCommentItem[] {
  const items: TextCommentItem[] = [];
  let current: { item: TextCommentItem; body: string[] } | null = null;
  const flush = () => {
    if (!current) return;
    const body = [current.item.body, ...current.body].join('\n').trim();
    items.push({ ...current.item, body });
    current = null;
  };
  for (const line of lines) {
    const bullet = line.match(/^[-*+][ \t]+(.*)$/) ?? (line.match(/^[-*+]$/) ? ['', ''] : null);
    if (bullet) {
      flush();
      current = { item: parseItemHead(bullet[1]), body: [] };
    } else if (current) {
      current.body.push(line.replace(/^ {1,2}/, ''));
    } else if (line.trim() !== '') {
      // A definition written by hand without a list: one comment, no author.
      current = {
        item: { author: '', authorLabel: '', timestamp: '', body: line.trim() },
        body: [],
      };
    }
  }
  flush();
  return items;
}

function dedent(line: string): string {
  if (line.startsWith('\t')) return line.slice(1);
  return line.replace(/^ {1,4}/, '');
}

/** Lines [start, end) that are inside fenced code blocks, so they are not searched. */
function fencedLines(lines: string[]): Set<number> {
  const inside = new Set<number>();
  let fence: string | null = null;
  lines.forEach((line, i) => {
    const m = line.match(/^ {0,3}(`{3,}|~{3,})/);
    if (fence) {
      inside.add(i);
      if (m && m[1][0] === fence[0] && m[1].length >= fence.length) fence = null;
    } else if (m) {
      fence = m[1];
      inside.add(i);
    }
  });
  return inside;
}

/** Every comment thread in a Text document. */
export function parseTextComments(markdown: string): TextCommentThread[] {
  const lines = markdown.split('\n');
  const fenced = fencedLines(lines);
  const threads: TextCommentThread[] = [];
  for (let i = 0; i < lines.length; i++) {
    if (fenced.has(i)) continue;
    const def = lines[i].match(DEFINITION_RE);
    if (!def) continue;
    let end = i + 1;
    while (end < lines.length && (lines[end].trim() === '' || /^( {4}|\t)/.test(lines[end]))) end++;
    while (end > i + 1 && lines[end - 1].trim() === '') end--;
    const body = lines.slice(i + 1, end).map(dedent);
    if (def[2]) body.unshift(def[2]);
    threads.push({ id: def[1], items: parseItems(body), anchor: '', start: i, end });
    i = end - 1;
  }
  const inDefinition = (line: number) => threads.some((t) => line >= t.start && line < t.end);
  for (const thread of threads) {
    const ref = `[^${thread.id}]`;
    const idx = lines.findIndex((l, n) => !inDefinition(n) && !fenced.has(n) && l.includes(ref));
    if (idx === -1) continue;
    const before = lines[idx].slice(0, lines[idx].indexOf(ref)).replace(REFERENCE_RE, '').trim();
    thread.anchor = before.length > 80 ? `…${before.slice(-80)}` : before;
  }
  return threads;
}

/** The first unused `comment-N`, as Text picks it. */
function nextCommentId(markdown: string): string {
  for (let n = 1; ; n++) {
    const id = `${COMMENT_PREFIX}${n}`;
    if (!markdown.includes(`[^${id}]`)) return id;
  }
}

function formatItem(item: Omit<TextCommentItem, 'body'>, body: string): string[] {
  const label = item.authorLabel.replace(/[[\]\n]/g, '');
  const who = item.author
    ? `@[${label || item.author}](mention://user/${encodeURIComponent(item.author)})`
    : label
      ? `@${label}`
      : '';
  const when = item.timestamp ? ` *(${item.timestamp})*` : '';
  const head = `${DEFINITION_INDENT}- ${who}${when}`.trimEnd();
  const bodyLines = body
    .trim()
    .split('\n')
    .map((l) => (l.trim() === '' ? '' : `${BODY_INDENT}${l}`));
  return [head, ...bodyLines];
}

export interface Author {
  author: string;
  authorLabel: string;
  timestamp: string;
}

/**
 * Attach a new thread to the first occurrence of `anchor`. Throws when the
 * anchor is missing or ambiguous, so the comment never lands in the wrong place.
 */
export function addThread(
  markdown: string,
  anchor: string,
  message: string,
  who: Author
): { markdown: string; id: string } {
  const lines = markdown.split('\n');
  const fenced = fencedLines(lines);
  const threads = parseTextComments(markdown);
  const inDefinition = (n: number) => threads.some((t) => n >= t.start && n < t.end);
  const hits: { line: number; col: number }[] = [];
  const needle = anchor.trim();
  if (needle === '') throw new Error('The anchor text is empty.');
  if (needle.includes('\n')) throw new Error('The anchor text must be on one line.');
  lines.forEach((line, n) => {
    if (fenced.has(n) || inDefinition(n)) return;
    for (const m of line.matchAll(new RegExp(escapeRegExp(needle), 'g'))) {
      hits.push({ line: n, col: (m.index ?? 0) + needle.length });
    }
  });
  if (hits.length === 0) throw new Error(`The text "${needle}" is not in the document.`);
  if (hits.length > 1) {
    throw new Error(
      `The text "${needle}" occurs ${hits.length} times. Quote more of it so it occurs once.`
    );
  }
  const id = nextCommentId(markdown);
  const { line, col } = hits[0];
  lines[line] = `${lines[line].slice(0, col)}[^${id}]${lines[line].slice(col)}`;

  const block = [`[^${id}]:`, ...formatItem(who, message)];
  const last = threads.at(-1);
  if (last) {
    lines.splice(last.end, 0, '', ...block);
  } else {
    while (lines.length > 0 && lines[lines.length - 1].trim() === '') lines.pop();
    lines.push('', ...block, '');
  }
  return { markdown: lines.join('\n'), id };
}

/** Append a reply to an existing thread. */
export function addReply(markdown: string, id: string, message: string, who: Author): string {
  const thread = parseTextComments(markdown).find((t) => t.id === id);
  if (!thread) throw new Error(`There is no comment ${id} in the document.`);
  const lines = markdown.split('\n');
  lines.splice(thread.end, 0, ...formatItem(who, message));
  return lines.join('\n');
}

/**
 * Remove a thread (its references and its definition), or with `reply` one
 * comment in it. Removing the first comment removes the thread, as in Text.
 */
export function removeComment(markdown: string, id: string, reply?: number): string {
  const thread = parseTextComments(markdown).find((t) => t.id === id);
  if (!thread) throw new Error(`There is no comment ${id} in the document.`);
  const lines = markdown.split('\n');
  if (reply !== undefined && reply > 1) {
    if (reply > thread.items.length) {
      throw new Error(`Comment ${id} has ${thread.items.length} entries, not ${reply}.`);
    }
    const kept = thread.items.filter((_, i) => i !== reply - 1);
    const block = [`[^${id}]:`, ...kept.flatMap((item) => formatItem(item, item.body))];
    lines.splice(thread.start, thread.end - thread.start, ...block);
    return lines.join('\n');
  }
  let start = thread.start;
  let end = thread.end;
  // Take one surrounding blank line with the block, so no gap is left behind.
  if (start > 0 && lines[start - 1].trim() === '') start--;
  else if (end < lines.length && lines[end].trim() === '') end++;
  lines.splice(start, end - start);
  const ref = new RegExp(escapeRegExp(`[^${id}]`), 'g');
  return lines.map((l) => l.replace(ref, '')).join('\n');
}

// ---------------------------------------------------------------------------
// File access
// ---------------------------------------------------------------------------

function fileUrl(path: string): string {
  const { url, user } = getNextcloudConfig();
  return `${url}${davPath('files', user)}/${davFilePath(path)}`;
}

function checkMarkdownPath(path: string): ToolResult | null {
  return /\.(md|markdown)$/i.test(path)
    ? null
    : text('Inline comments exist only in Markdown files (.md) that Text edits.', true);
}

async function readDocument(path: string): Promise<{ markdown: string; etag: string }> {
  const response = await fetchCalDAV(fileUrl(path), { method: 'GET' });
  if (response.status === 404) throw new Error(`${path} does not exist.`);
  if (!response.ok) throw new Error(`reading ${path}: ${response.status} ${response.statusText}`);
  const etag = response.headers.get('ETag');
  if (!etag) throw new Error(`Nextcloud sent no ETag for ${path}.`);
  return { markdown: await response.text(), etag };
}

async function writeDocument(path: string, markdown: string, etag: string): Promise<void> {
  const response = await fetchCalDAV(fileUrl(path), {
    method: 'PUT',
    body: markdown,
    headers: { 'Content-Type': 'text/markdown; charset=utf-8', 'If-Match': etag },
  });
  if (response.status === 412) {
    throw new Error(
      `${path} changed while the comment was being written. Nothing was saved; try again.`
    );
  }
  if (!response.ok) throw new Error(`writing ${path}: ${response.status} ${response.statusText}`);
}

async function currentAuthor(): Promise<Author> {
  const { user } = getNextcloudConfig();
  let label = user;
  try {
    const me = await fetchOCS<{ id?: string; displayname?: string }>('/ocs/v2.php/cloud/user');
    if (typeof me.ocs.data.displayname === 'string' && me.ocs.data.displayname !== '') {
      label = me.ocs.data.displayname;
    }
  } catch {
    // The user ID is a usable label.
  }
  return { author: user, authorLabel: label, timestamp: new Date().toISOString() };
}

function formatThread(t: TextCommentThread): string {
  const where = t.anchor ? ` on "${t.anchor}"` : ' (its reference is missing)';
  const items = t.items.map((item, i) => {
    const who = item.authorLabel || item.author || 'unknown';
    const uid = item.author && item.author !== who ? ` (${item.author})` : '';
    const when = item.timestamp ? `, ${item.timestamp}` : '';
    const role = i === 0 ? '' : ' (reply)';
    return `  ${i + 1}. ${who}${uid}${when}${role}\n     ${item.body.replace(/\n/g, '\n     ')}`;
  });
  return `- ${t.id}${where}\n${items.join('\n')}`;
}

const PathArg = z.string().describe("Markdown file, e.g. '/Projects/Acme/Readme.md'");
const CommentIdArg = z
  .string()
  .regex(/^comment-[^\]\s]+$/, 'A Text comment ID looks like comment-3')
  .describe('Comment ID, such as comment-3 (from list_text_comments)');

function errorText(action: string, error: unknown): ToolResult {
  return text(`Error ${action}: ${error instanceof Error ? error.message : String(error)}`, true);
}

// ---------------------------------------------------------------------------
// Tools
// ---------------------------------------------------------------------------

export const listTextCommentsTool = {
  name: 'list_text_comments',
  title: 'List Text Comments',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description:
    'List the inline comment threads in a Markdown document edited with Nextcloud Text: each ' +
    "thread's ID, the text it is attached to, and its comments with author and time.",
  inputSchema: z.object({ path: PathArg }),
  handler: async (args: { path: string }) => {
    const invalid = checkMarkdownPath(args.path);
    if (invalid) return invalid;
    try {
      const { markdown } = await readDocument(args.path);
      const threads = parseTextComments(markdown);
      if (threads.length === 0) return text(`No inline comments in ${args.path}.`);
      return text(
        `Inline comments in ${args.path} (${threads.length}):\n` +
          threads.map(formatThread).join('\n')
      );
    } catch (error) {
      return errorText('listing inline comments', error);
    }
  },
};

export const addTextCommentTool = {
  name: 'add_text_comment',
  title: 'Add Text Comment',
  annotations: {
    readOnlyHint: false,
    destructiveHint: false,
    idempotentHint: false,
    openWorldHint: false,
  },
  description:
    'Start an inline comment thread in a Markdown document, attached right after a piece of ' +
    'text that occurs exactly once in it. Written as the signed-in user, in the format Nextcloud ' +
    'Text uses. Changes made in Text after the file was read are never overwritten.',
  inputSchema: z.object({
    path: PathArg,
    anchor: z
      .string()
      .describe('Text in the document, on one line, that the comment follows. Must occur once'),
    message: z.string().describe('The comment, Markdown'),
  }),
  handler: async (args: { path: string; anchor: string; message: string }) => {
    const invalid = checkMarkdownPath(args.path);
    if (invalid) return invalid;
    if (args.message.trim() === '') return text('The comment is empty.', true);
    const refused = await checkGatedParams(args, WRITE_GATES);
    if (refused) return text(refused, true);
    try {
      const { markdown, etag } = await readDocument(args.path);
      const result = addThread(markdown, args.anchor, args.message, await currentAuthor());
      await writeDocument(args.path, result.markdown, etag);
      return text(`Added ${result.id} to ${args.path}.`);
    } catch (error) {
      return errorText('adding the inline comment', error);
    }
  },
};

export const replyTextCommentTool = {
  name: 'reply_text_comment',
  title: 'Reply to Text Comment',
  annotations: {
    readOnlyHint: false,
    destructiveHint: false,
    idempotentHint: false,
    openWorldHint: false,
  },
  description: 'Reply to an inline comment thread in a Markdown document, as the signed-in user.',
  inputSchema: z.object({
    path: PathArg,
    commentId: CommentIdArg,
    message: z.string().describe('The reply, Markdown'),
  }),
  handler: async (args: { path: string; commentId: string; message: string }) => {
    const invalid = checkMarkdownPath(args.path);
    if (invalid) return invalid;
    if (args.message.trim() === '') return text('The reply is empty.', true);
    const refused = await checkGatedParams(args, WRITE_GATES);
    if (refused) return text(refused, true);
    try {
      const { markdown, etag } = await readDocument(args.path);
      const updated = addReply(markdown, args.commentId, args.message, await currentAuthor());
      await writeDocument(args.path, updated, etag);
      return text(`Replied to ${args.commentId} in ${args.path}.`);
    } catch (error) {
      return errorText('replying to the inline comment', error);
    }
  },
};

export const deleteTextCommentTool = {
  name: 'delete_text_comment',
  title: 'Delete Text Comment',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: false,
  },
  description:
    'Delete an inline comment thread from a Markdown document, which is how a thread is ' +
    'resolved in Text, or with reply one comment in it. The document text is kept.',
  inputSchema: z.object({
    path: PathArg,
    commentId: CommentIdArg,
    reply: z
      .number()
      .int()
      .min(1)
      .optional()
      .describe(
        'Position of the one comment to delete (2 = first reply). 1 or omitted deletes the thread'
      ),
  }),
  handler: async (args: { path: string; commentId: string; reply?: number }) => {
    const invalid = checkMarkdownPath(args.path);
    if (invalid) return invalid;
    try {
      const { markdown, etag } = await readDocument(args.path);
      const updated = removeComment(markdown, args.commentId, args.reply);
      await writeDocument(args.path, updated, etag);
      return text(
        args.reply && args.reply > 1
          ? `Deleted comment ${args.reply} of ${args.commentId} in ${args.path}.`
          : `Deleted ${args.commentId} from ${args.path}.`
      );
    } catch (error) {
      return errorText('deleting the inline comment', error);
    }
  },
};

export const textCommentTools = [
  listTextCommentsTool,
  addTextCommentTool,
  replyTextCommentTool,
  deleteTextCommentTool,
];
