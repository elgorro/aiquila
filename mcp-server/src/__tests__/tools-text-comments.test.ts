// SPDX-License-Identifier: MIT

import { beforeEach, describe, expect, it, vi } from 'vitest';

const mockFetchCalDAV = vi.fn();
vi.mock('../client/caldav.js', async () => {
  const actual = await vi.importActual<typeof import('../client/caldav.js')>('../client/caldav.js');
  return { ...actual, fetchCalDAV: (...args: unknown[]) => mockFetchCalDAV(...args) };
});

const mockFetchOCS = vi.fn();
vi.mock('../client/ocs.js', () => ({
  fetchOCS: (...args: unknown[]) => mockFetchOCS(...args),
}));

vi.mock('../logger.js', () => ({
  logger: { info: vi.fn(), warn: vi.fn(), error: vi.fn(), debug: vi.fn(), trace: vi.fn() },
}));

import { _resetCapabilitiesCache } from '../client/capabilities.js';
import {
  addReply,
  addTextCommentTool,
  addThread,
  deleteTextCommentTool,
  listTextCommentsTool,
  parseTextComments,
  removeComment,
  replyTextCommentTool,
} from '../tools/apps/text-comments.js';

const ALICE = { author: 'alice', authorLabel: 'Alice', timestamp: '2026-11-10T09:00:00.000Z' };
const BOB = { author: 'bob', authorLabel: 'Bob', timestamp: '2026-11-10T09:30:00.000Z' };

/** A document as Text 9 serializes it. */
const DOC = `# Plan

The budget is final.[^comment-1] Ship on Friday.

[^comment-1]:
    - @[Alice](mention://user/alice) *(2026-11-10T09:00:00.000Z)*
      Is it?

      Finance has not signed off.
    - @[Bob](mention://user/bob) *(2026-11-10T09:30:00.000Z)*
      They did this morning.

[^1]: An ordinary footnote.
`;

describe('Text inline comment format', () => {
  it('reads threads, authors, timestamps and multi-paragraph bodies', () => {
    const [thread, ...rest] = parseTextComments(DOC);
    expect(rest).toEqual([]);
    expect(thread.id).toBe('comment-1');
    expect(thread.anchor).toBe('The budget is final.');
    expect(thread.items).toEqual([
      { ...ALICE, body: 'Is it?\n\nFinance has not signed off.' },
      { ...BOB, body: 'They did this morning.' },
    ]);
  });

  it('ignores ordinary footnotes and anything inside a code block', () => {
    const doc = '```\n[^comment-9]:\n    - @x\n      y\n```\n\n[^2]: note\n';
    expect(parseTextComments(doc)).toEqual([]);
  });

  it('reads a hand-written single-line definition and a plain @name author', () => {
    const doc =
      'A[^comment-2]\n\n[^comment-2]: Just a note\n\nB[^comment-3]\n\n[^comment-3]:\n    - @carol\n      hi\n';
    const [two, three] = parseTextComments(doc);
    expect(two.items).toEqual([
      { author: '', authorLabel: '', timestamp: '', body: 'Just a note' },
    ]);
    expect(three.items[0]).toMatchObject({ author: '', authorLabel: 'carol', body: 'hi' });
  });

  it('writes a new thread in the format it reads, after the last thread', () => {
    const { markdown, id } = addThread(DOC, 'Ship on Friday.', 'Which Friday?', BOB);
    expect(id).toBe('comment-2');
    expect(markdown).toContain('Ship on Friday.[^comment-2]');
    expect(markdown).toContain(
      '[^comment-2]:\n    - @[Bob](mention://user/bob) *(2026-11-10T09:30:00.000Z)*\n      Which Friday?\n'
    );
    // The new thread follows comment-1, before the ordinary footnote.
    expect(markdown.indexOf('[^comment-2]:')).toBeLessThan(markdown.indexOf('[^1]:'));
    const threads = parseTextComments(markdown);
    expect(threads.map((t) => t.id)).toEqual(['comment-1', 'comment-2']);
    expect(threads[1].items).toEqual([{ ...BOB, body: 'Which Friday?' }]);
    expect(threads[1].anchor).toBe('The budget is final. Ship on Friday.');
  });

  it('appends the first thread at the end of a document without comments', () => {
    const { markdown } = addThread('# T\n\nHello world.\n\n\n', 'Hello', 'Hi', ALICE);
    expect(markdown).toBe(
      '# T\n\nHello[^comment-1] world.\n\n[^comment-1]:\n' +
        '    - @[Alice](mention://user/alice) *(2026-11-10T09:00:00.000Z)*\n      Hi\n'
    );
  });

  it('refuses an anchor that is missing, repeated or only inside code', () => {
    expect(() => addThread(DOC, 'Monday', 'x', ALICE)).toThrow('not in the document');
    expect(() => addThread('a b a', 'a', 'x', ALICE)).toThrow('occurs 2 times');
    expect(() => addThread('```\nonly here\n```\n', 'only here', 'x', ALICE)).toThrow(
      'not in the document'
    );
    // Text in a thread is not an anchor either.
    expect(() => addThread(DOC, 'They did this morning.', 'x', ALICE)).toThrow(
      'not in the document'
    );
  });

  it('picks the first free comment number', () => {
    const doc = 'a[^comment-2] b\n\n[^comment-2]:\n    - x\n';
    expect(addThread(doc, 'b', 'y', ALICE).id).toBe('comment-1');
  });

  it('keeps label characters from breaking the mention link', () => {
    const { markdown } = addThread('x', 'x', 'y', { ...ALICE, authorLabel: 'A [team] lead' });
    expect(markdown).toContain('@[A team lead](mention://user/alice)');
  });

  it('adds a reply at the end of its thread', () => {
    const updated = addReply(DOC, 'comment-1', 'Great.', ALICE);
    const [thread] = parseTextComments(updated);
    expect(thread.items).toHaveLength(3);
    expect(thread.items[2]).toEqual({ ...ALICE, body: 'Great.' });
    expect(() => addReply(DOC, 'comment-9', 'x', ALICE)).toThrow('no comment comment-9');
  });

  it('removes a thread with its reference and leaves the rest of the document', () => {
    const updated = removeComment(DOC, 'comment-1');
    expect(updated).toBe(
      '# Plan\n\nThe budget is final. Ship on Friday.\n\n[^1]: An ordinary footnote.\n'
    );
  });

  it('removes one reply, and the whole thread for the first comment', () => {
    const one = removeComment(DOC, 'comment-1', 2);
    expect(parseTextComments(one)[0].items).toEqual([
      { ...ALICE, body: 'Is it?\n\nFinance has not signed off.' },
    ]);
    expect(one).toContain('[^comment-1] Ship');
    expect(removeComment(DOC, 'comment-1', 1)).not.toContain('comment-1');
    expect(() => removeComment(DOC, 'comment-1', 3)).toThrow('has 2 entries');
  });
});

describe('Text inline comment tools', () => {
  const FILE = 'https://cloud.example.com/remote.php/dav/files/alice/Projects/Plan%20A.md';
  let stored: string;

  beforeEach(() => {
    vi.clearAllMocks();
    _resetCapabilitiesCache();
    process.env.NEXTCLOUD_URL = 'https://cloud.example.com';
    process.env.NEXTCLOUD_USER = 'alice';
    process.env.NEXTCLOUD_PASSWORD = 'secret';
    stored = DOC;
    mockFetchCalDAV.mockImplementation(
      async (_url: string, options: { method: string; body?: string }) => {
        if (options.method === 'GET') {
          return new Response(stored, { status: 200, headers: { ETag: '"v1"' } });
        }
        stored = options.body ?? '';
        return new Response(null, { status: 204 });
      }
    );
    mockFetchOCS.mockImplementation(async (path: string) => {
      if (path.includes('/cloud/capabilities')) {
        return { ocs: { data: { version: { major: 35 }, capabilities: {} } } };
      }
      return { ocs: { data: { id: 'alice', displayname: 'Alice A.' } } };
    });
  });

  const writes = () => mockFetchCalDAV.mock.calls.filter(([, o]) => o.method === 'PUT');

  it('lists threads', async () => {
    const result = await listTextCommentsTool.handler({ path: '/Projects/Plan A.md' });
    expect(mockFetchCalDAV.mock.calls[0][0]).toBe(FILE);
    expect(result.content[0].text).toContain('comment-1 on "The budget is final."');
    expect(result.content[0].text).toContain('2. Bob (bob), 2026-11-10T09:30:00.000Z (reply)');
  });

  it('adds a thread as the signed-in user, guarded by the ETag it read', async () => {
    const result = await addTextCommentTool.handler({
      path: '/Projects/Plan A.md',
      anchor: 'Ship on Friday.',
      message: 'Which Friday?',
    });

    expect(result.content[0].text).toBe('Added comment-2 to /Projects/Plan A.md.');
    const [[url, options]] = writes();
    expect(url).toBe(FILE);
    expect(options.headers['If-Match']).toBe('"v1"');
    expect(stored).toMatch(
      /- @\[Alice A\.\]\(mention:\/\/user\/alice\) \*\(\d{4}-.+Z\)\*\n {6}Which Friday\?/
    );
  });

  it('saves nothing when the file changed in between (412)', async () => {
    mockFetchCalDAV.mockImplementation(async (_url: string, options: { method: string }) =>
      options.method === 'GET'
        ? new Response(DOC, { status: 200, headers: { ETag: '"v1"' } })
        : new Response(null, { status: 412, statusText: 'Precondition Failed' })
    );
    const result = await replyTextCommentTool.handler({
      path: '/Projects/Plan A.md',
      commentId: 'comment-1',
      message: 'ok',
    });
    expect(result.isError).toBe(true);
    expect(result.content[0].text).toContain('changed while the comment was being written');
  });

  it('refuses to write on a server older than Nextcloud 35, whose Text shows footnotes', async () => {
    mockFetchOCS.mockResolvedValue({ ocs: { data: { version: { major: 34 }, capabilities: {} } } });
    const result = await addTextCommentTool.handler({
      path: '/Projects/Plan A.md',
      anchor: 'Ship',
      message: 'x',
    });
    expect(result.isError).toBe(true);
    expect(result.content[0].text).toContain('Nextcloud 35');
    expect(mockFetchCalDAV).not.toHaveBeenCalled();
  });

  it('only works on Markdown files', async () => {
    const result = await listTextCommentsTool.handler({ path: '/notes.txt' });
    expect(result.isError).toBe(true);
    expect(mockFetchCalDAV).not.toHaveBeenCalled();
  });

  it('deletes a thread', async () => {
    const result = await deleteTextCommentTool.handler({
      path: '/Projects/Plan A.md',
      commentId: 'comment-1',
    });
    expect(result.isError).toBeUndefined();
    expect(stored).not.toContain('comment-1');
  });

  it('rejects a comment ID that is not one', () => {
    expect(
      deleteTextCommentTool.inputSchema.safeParse({ path: '/a.md', commentId: '1' }).success
    ).toBe(false);
  });
});
