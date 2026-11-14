// SPDX-License-Identifier: MIT

import { beforeEach, describe, expect, it, vi } from 'vitest';

const mockFetchCalDAV = vi.fn();
vi.mock('../client/caldav.js', async () => {
  const actual = await vi.importActual<typeof import('../client/caldav.js')>('../client/caldav.js');
  return { ...actual, fetchCalDAV: (...args: unknown[]) => mockFetchCalDAV(...args) };
});

const mockStat = vi.fn();
vi.mock('../client/webdav.js', () => ({
  getWebDAVClient: () => ({ stat: (...args: unknown[]) => mockStat(...args) }),
}));

import {
  addFileCommentTool,
  deleteFileCommentTool,
  listFileCommentsTool,
  markFileCommentsReadTool,
  parseComments,
  updateFileCommentTool,
} from '../tools/apps/comments.js';

const BASE = 'https://cloud.example.com/remote.php/dav/comments/files';

function comment(id: number, props: Record<string, string>): string {
  const p = {
    verb: 'comment',
    actorId: 'bob',
    actorDisplayName: 'Bob',
    isUnread: 'false',
    ...props,
  };
  return `<d:response><d:href>/remote.php/dav/comments/files/42/${id}</d:href><d:propstat><d:prop>
<oc:id>${id}</oc:id>${Object.entries(p)
    .map(([k, v]) => `<oc:${k}>${v}</oc:${k}>`)
    .join('')}<oc:creationDateTime>Mon, 09 Nov 2026 10:00:00 GMT</oc:creationDateTime>
</d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>`;
}

function multistatus(...responses: string[]): Response {
  return new Response(
    `<?xml version="1.0"?><d:multistatus xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">${responses.join('')}</d:multistatus>`,
    { status: 207 }
  );
}

describe('file comments', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    process.env.NEXTCLOUD_URL = 'https://cloud.example.com';
    process.env.NEXTCLOUD_USER = 'alice';
    process.env.NEXTCLOUD_PASSWORD = 'secret';
    mockStat.mockResolvedValue({ props: { fileid: '42' } });
  });

  it('parses the comment properties and decodes entities', () => {
    const [c] = parseComments(
      `<d:multistatus>${comment(7, { message: 'a &lt;b&gt; &amp; c', isUnread: 'true' })}</d:multistatus>`
    );
    expect(c).toMatchObject({ id: '7', actorId: 'bob', message: 'a <b> & c', isUnread: true });
  });

  it('lists through the filter-comments REPORT on the file ID of the path', async () => {
    mockFetchCalDAV.mockResolvedValue(
      multistatus(
        comment(8, { message: 'second', isUnread: 'true' }),
        comment(7, { message: 'first' }),
        comment(6, { message: 'x', verb: 'system' })
      )
    );

    const result = await listFileCommentsTool.handler({ path: '/a.txt', limit: 10, offset: 5 });

    expect(mockStat).toHaveBeenCalledWith('/a.txt', { props: ['oc:fileid'] });
    const [url, options] = mockFetchCalDAV.mock.calls[0];
    expect(url).toBe(`${BASE}/42`);
    expect(options.method).toBe('REPORT');
    expect(options.body).toContain('<oc:filter-comments');
    expect(options.body).toContain('<oc:limit>10</oc:limit>');
    expect(options.body).toContain('<oc:offset>5</oc:offset>');
    const text = result.content[0].text;
    expect(text).toContain('(2, 1 unread)');
    expect(text).toContain('[8] Bob (bob)');
    expect(text).toContain('(unread)');
    expect(text).not.toContain('[6]');
  });

  it('uses fileId without looking up a path', async () => {
    mockFetchCalDAV.mockResolvedValue(multistatus());
    const result = await listFileCommentsTool.handler({ fileId: 9 });
    expect(mockStat).not.toHaveBeenCalled();
    expect(mockFetchCalDAV.mock.calls[0][0]).toBe(`${BASE}/9`);
    expect(result.content[0].text).toBe('No comments on file 9.');
  });

  it('reports a missing path plainly', async () => {
    mockStat.mockRejectedValue(Object.assign(new Error('Invalid response: 404'), { status: 404 }));
    const result = await listFileCommentsTool.handler({ path: '/gone.txt' });
    expect(result.isError).toBe(true);
    expect(result.content[0].text).toContain('/gone.txt does not exist');
  });

  it('needs a path or a file ID', async () => {
    const result = await listFileCommentsTool.handler({});
    expect(result.isError).toBe(true);
    expect(mockFetchCalDAV).not.toHaveBeenCalled();
  });

  it('creates a comment as JSON and reports its ID from Content-Location', async () => {
    mockFetchCalDAV.mockResolvedValue(
      new Response(null, {
        status: 201,
        headers: { 'Content-Location': '/remote.php/dav/comments/files/42/77' },
      })
    );

    const result = await addFileCommentTool.handler({ fileId: 42, message: 'Hi @bob' });

    const [url, options] = mockFetchCalDAV.mock.calls[0];
    expect(url).toBe(`${BASE}/42`);
    expect(options.method).toBe('POST');
    expect(options.headers['Content-Type']).toBe('application/json');
    expect(JSON.parse(options.body)).toEqual({
      actorType: 'users',
      verb: 'comment',
      message: 'Hi @bob',
    });
    expect(result.content[0].text).toBe('Added comment 77 to file 42.');
  });

  it('refuses an empty or over-long comment without a request', async () => {
    expect((await addFileCommentTool.handler({ fileId: 1, message: '  ' })).isError).toBe(true);
    const long = await addFileCommentTool.handler({ fileId: 1, message: 'x'.repeat(1001) });
    expect(long.isError).toBe(true);
    expect(long.content[0].text).toContain('1000');
    expect(mockFetchCalDAV).not.toHaveBeenCalled();
  });

  it('edits through PROPPATCH of oc:message, XML-escaped', async () => {
    mockFetchCalDAV.mockResolvedValue(
      new Response(
        '<d:multistatus xmlns:d="DAV:"><d:response><d:propstat><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response></d:multistatus>',
        { status: 207 }
      )
    );

    const result = await updateFileCommentTool.handler({
      fileId: 42,
      commentId: '7',
      message: 'a <b> & c',
    });

    const [url, options] = mockFetchCalDAV.mock.calls[0];
    expect(url).toBe(`${BASE}/42/7`);
    expect(options.method).toBe('PROPPATCH');
    expect(options.body).toContain('<oc:message>a &lt;b&gt; &amp; c</oc:message>');
    expect(result.isError).toBeUndefined();
  });

  it("reports the server's reason when someone else's comment is edited", async () => {
    mockFetchCalDAV.mockResolvedValue(
      new Response(
        '<d:error xmlns:d="DAV:" xmlns:s="http://sabredav.org/ns"><s:message>Only authors are allowed to edit their comment.</s:message></d:error>',
        { status: 403, statusText: 'Forbidden' }
      )
    );
    const result = await updateFileCommentTool.handler({
      fileId: 42,
      commentId: '7',
      message: 'x',
    });
    expect(result.isError).toBe(true);
    expect(result.content[0].text).toContain('Only authors are allowed');
  });

  it('treats a PROPPATCH multistatus without 200 as a failure', async () => {
    mockFetchCalDAV.mockResolvedValue(
      new Response(
        '<d:multistatus xmlns:d="DAV:"><d:response><d:propstat><d:status>HTTP/1.1 403 Forbidden</d:status></d:propstat></d:response></d:multistatus>',
        { status: 207 }
      )
    );
    const result = await updateFileCommentTool.handler({
      fileId: 42,
      commentId: '7',
      message: 'x',
    });
    expect(result.isError).toBe(true);
  });

  it('rejects a comment ID that is not a number before any request', () => {
    expect(
      updateFileCommentTool.inputSchema.safeParse({ fileId: 1, commentId: '../7', message: 'x' })
        .success
    ).toBe(false);
  });

  it('deletes a comment', async () => {
    mockFetchCalDAV.mockResolvedValue(new Response(null, { status: 204 }));
    const result = await deleteFileCommentTool.handler({ fileId: 42, commentId: '7' });
    expect(mockFetchCalDAV).toHaveBeenCalledWith(`${BASE}/42/7`, { method: 'DELETE' });
    expect(result.isError).toBeUndefined();
  });

  it('marks comments read by setting the read marker on the collection', async () => {
    mockFetchCalDAV.mockResolvedValue(
      new Response(
        '<d:multistatus xmlns:d="DAV:"><d:response><d:propstat><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response></d:multistatus>',
        { status: 207 }
      )
    );
    await markFileCommentsReadTool.handler({ fileId: 42 });
    const [url, options] = mockFetchCalDAV.mock.calls[0];
    expect(url).toBe(`${BASE}/42`);
    expect(options.method).toBe('PROPPATCH');
    expect(options.body).toMatch(/<oc:readMarker>.+ GMT<\/oc:readMarker>/);
  });
});
