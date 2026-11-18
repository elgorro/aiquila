// SPDX-License-Identifier: MIT

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
  ProtocolError,
  ProtocolErrorCode,
  ResourceNotFoundError,
  type McpServer,
} from '@modelcontextprotocol/server';

const mockWebDAV = { stat: vi.fn(), getFileContents: vi.fn() };
vi.mock('../client/webdav.js', async () => {
  const actual = await vi.importActual<typeof import('../client/webdav.js')>('../client/webdav.js');
  return { ...actual, getWebDAVClient: () => mockWebDAV };
});

const mockFetchMailAPI = vi.fn();
vi.mock('../client/mail.js', () => ({
  fetchMailAPI: (...args: unknown[]) => mockFetchMailAPI(...args),
}));

vi.mock('../logger.js', () => ({
  logger: { info: vi.fn(), warn: vi.fn(), error: vi.fn(), debug: vi.fn(), trace: vi.fn() },
}));

import { WebDAVError } from '../client/webdav.js';
import { filePathFromUri, registerResources, servesResources } from '../resources.js';

type Read = (uri: URL, variables: Record<string, string>) => Promise<{ contents: unknown[] }>;

/** The templates a set of tools registers, by name, with their read callbacks. */
function registered(...tools: string[]) {
  const templates = new Map<string, { template: string; read: Read }>();
  const server = {
    registerResource: (
      name: string,
      template: { uriTemplate: object },
      _meta: object,
      read: Read
    ) => templates.set(name, { template: String(template.uriTemplate), read }),
  } as unknown as McpServer;
  registerResources(server, new Set(tools));
  return templates;
}

const readFile = (uri: string) =>
  registered('read_file').get('nextcloud-file')!.read(new URL(uri), {});

const readAttachment = (messageId: string, attachmentId: string) =>
  registered('mail_get_attachment')
    .get('nextcloud-mail-attachment')!
    .read(new URL(`nextcloud-mail:///messages/${messageId}/attachments/${attachmentId}`), {
      messageId,
      attachmentId,
    });

beforeEach(() => {
  vi.clearAllMocks();
  process.env.NEXTCLOUD_URL = 'https://cloud.example.com';
  process.env.NEXTCLOUD_USER = 'admin';
  process.env.NEXTCLOUD_PASSWORD = 'testpass';
});
afterEach(() => {
  delete process.env.MCP_MAX_EMBEDDED_FILE_SIZE;
});

describe('which resources go with which tools', () => {
  it('serves files with a file tool and attachments with mail_get_attachment', () => {
    expect([...registered('get_file_content').keys()]).toEqual(['nextcloud-file']);
    expect([...registered('read_file', 'mail_get_attachment').keys()]).toEqual([
      'nextcloud-file',
      'nextcloud-mail-attachment',
    ]);
    expect(registered('read_file').get('nextcloud-file')!.template).toBe('nextcloud:///{+path}');
  });

  it('serves nothing, and declares nothing, without them', () => {
    expect(registered('system_status', 'list_files').size).toBe(0);
    expect(servesResources(new Set(['system_status']))).toBe(false);
    expect(servesResources(new Set(['mail_get_attachment']))).toBe(true);
  });
});

describe('filePathFromUri', () => {
  it('decodes each segment', () => {
    expect(filePathFromUri(new URL('nextcloud:///Dossier%20%C3%A9t%C3%A9/a%23b.pdf'))).toBe(
      '/Dossier été/a#b.pdf'
    );
  });

  it.each([
    ['nextcloud:///', 'names no file'],
    ['nextcloud://host/a.pdf', 'nextcloud:///<path>'],
    ['nextcloud:///a.pdf?x=1', 'nextcloud:///<path>'],
    ['nextcloud:///a%2Fb.pdf', 'names no file'],
    ['nextcloud:///a/%2E%2E%2Fb', 'names no file'],
    ['nextcloud:///a%ZZ', 'malformed'],
  ])('refuses %s', (uri, reason) => {
    try {
      filePathFromUri(new URL(uri));
      expect.unreachable();
    } catch (error) {
      expect(error).toBeInstanceOf(ProtocolError);
      expect((error as ProtocolError).code).toBe(ProtocolErrorCode.InvalidParams);
      expect((error as Error).message).toContain(reason);
    }
  });
});

describe('reading a file resource', () => {
  it('returns a text file as text', async () => {
    mockWebDAV.stat.mockResolvedValue({
      basename: 'a.md',
      type: 'file',
      size: 3,
      mime: 'text/markdown',
    });
    mockWebDAV.getFileContents.mockResolvedValue(new TextEncoder().encode('# A'));

    const result = await readFile('nextcloud:///Notes/a.md');

    expect(mockWebDAV.stat).toHaveBeenCalledWith('/Notes/a.md');
    expect(result.contents).toEqual([
      { uri: 'nextcloud:///Notes/a.md', mimeType: 'text/markdown', text: '# A' },
    ]);
  });

  it('returns any other file as a blob', async () => {
    const bytes = new Uint8Array([0x50, 0x4b, 3, 4, 0, 255]);
    mockWebDAV.stat.mockResolvedValue({
      basename: 'a.zip',
      type: 'file',
      size: 6,
      mime: 'application/zip',
    });
    mockWebDAV.getFileContents.mockResolvedValue(bytes);

    const result = await readFile('nextcloud:///a.zip');

    expect(result.contents).toEqual([
      {
        uri: 'nextcloud:///a.zip',
        mimeType: 'application/zip',
        blob: Buffer.from(bytes).toString('base64'),
      },
    ]);
  });

  it('reports a missing file as not found', async () => {
    mockWebDAV.stat.mockRejectedValue(new WebDAVError(404, 'Not Found'));
    await expect(readFile('nextcloud:///absent.pdf')).rejects.toBeInstanceOf(ResourceNotFoundError);
  });

  it('passes on any other WebDAV failure', async () => {
    mockWebDAV.stat.mockRejectedValue(new WebDAVError(423, 'Locked'));
    await expect(readFile('nextcloud:///locked.pdf')).rejects.toBeInstanceOf(WebDAVError);
  });

  it('refuses a folder and a file over the limit without downloading them', async () => {
    mockWebDAV.stat.mockResolvedValue({ basename: 'Photos', type: 'directory', size: 0 });
    await expect(readFile('nextcloud:///Photos')).rejects.toThrow('folder');

    process.env.MCP_MAX_EMBEDDED_FILE_SIZE = '10';
    mockWebDAV.stat.mockResolvedValue({ basename: 'big.pdf', type: 'file', size: 11 });
    await expect(readFile('nextcloud:///big.pdf')).rejects.toThrow('MCP_MAX_EMBEDDED_FILE_SIZE');
    expect(mockWebDAV.getFileContents).not.toHaveBeenCalled();
  });
});

describe('reading a mail attachment resource', () => {
  const answer = (status: number, headers: Record<string, string>, body: Uint8Array) => ({
    ok: status < 400,
    status,
    headers: { get: (name: string) => headers[name] ?? null },
    text: () => Promise.resolve('[]'),
    arrayBuffer: () => Promise.resolve(new Uint8Array(body).buffer),
  });

  it('returns the attachment as a blob, typed from its name when declared octet-stream', async () => {
    const bytes = new Uint8Array([0x50, 0x4b, 3, 4, 0, 0]);
    mockFetchMailAPI.mockResolvedValue(
      answer(
        200,
        {
          'content-type': 'application/octet-stream',
          'content-disposition': 'attachment; filename="Budget.xlsx"',
        },
        bytes
      )
    );

    const result = await readAttachment('100', '5');

    expect(mockFetchMailAPI).toHaveBeenCalledWith('/messages/100/attachment/5');
    expect(result.contents).toEqual([
      {
        uri: 'nextcloud-mail:///messages/100/attachments/5',
        mimeType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        blob: Buffer.from(bytes).toString('base64'),
      },
    ]);
  });

  it.each([403, 404])('reports a %i from Mail as not found', async (status) => {
    mockFetchMailAPI.mockResolvedValue(answer(status, {}, new Uint8Array()));
    await expect(readAttachment('100', '5')).rejects.toBeInstanceOf(ResourceNotFoundError);
  });

  it('does not ask Mail for a message ID that is not a number', async () => {
    await expect(readAttachment('abc', '5')).rejects.toBeInstanceOf(ResourceNotFoundError);
    expect(mockFetchMailAPI).not.toHaveBeenCalled();
  });
});
