// SPDX-License-Identifier: MIT

import { afterEach, describe, expect, it, vi } from 'vitest';
import {
  binaryContent,
  decodeText,
  fileContent,
  fileMimeType,
  fileUri,
  isText,
  maxEmbeddedFileSize,
  resourceContents,
} from '../tools/file-content.js';

const bytes = (...values: number[]) => new Uint8Array(values);
const text = (value: string) => new TextEncoder().encode(value);

describe('fileMimeType', () => {
  it('keeps the declared type, without its parameters', () => {
    expect(
      fileMimeType({ name: 'a.bin', bytes: bytes(), mimeType: 'Text/Plain; charset=utf-8' })
    ).toBe('text/plain');
  });

  it('falls back to the extension for application/octet-stream', () => {
    expect(
      fileMimeType({ name: 'Rapport.PDF', bytes: bytes(), mimeType: 'application/octet-stream' })
    ).toBe('application/pdf');
    expect(fileMimeType({ name: 'x.odt', bytes: bytes() })).toBe(
      'application/vnd.oasis.opendocument.text'
    );
  });

  it('falls back to the signature when the name says nothing', () => {
    expect(fileMimeType({ name: 'attachment-2', bytes: text('%PDF-1.7') })).toBe('application/pdf');
    expect(fileMimeType({ name: 'attachment-3', bytes: bytes(0xff, 0xd8, 0xff, 0xe0) })).toBe(
      'image/jpeg'
    );
    expect(fileMimeType({ name: 'attachment-4', bytes: bytes(1, 2, 3) })).toBe(
      'application/octet-stream'
    );
  });
});

describe('isText and decodeText', () => {
  it('treats bytes without NUL as text, an empty file included', () => {
    expect(isText(text('héllo\n'))).toBe(true);
    expect(isText(bytes())).toBe(true);
    expect(isText(bytes(0x50, 0x4b, 3, 4, 0, 0))).toBe(false);
  });

  it('reads UTF-16 by its byte order mark', () => {
    const utf16 = bytes(0xff, 0xfe, 0x68, 0x00, 0x69, 0x00);
    expect(isText(utf16)).toBe(true);
    expect(decodeText(utf16)).toBe('hi');
  });

  it('drops a UTF-8 byte order mark, as Response.text() does', () => {
    expect(decodeText(bytes(0xef, 0xbb, 0xbf, 0x61))).toBe('a');
  });
});

describe('maxEmbeddedFileSize', () => {
  afterEach(() => {
    delete process.env.MCP_MAX_EMBEDDED_FILE_SIZE;
    vi.restoreAllMocks();
  });

  const DEFAULT = 20 * 1024 * 1024;

  it('defaults to 20 MB and takes a positive integer', () => {
    expect(maxEmbeddedFileSize()).toBe(DEFAULT);
    process.env.MCP_MAX_EMBEDDED_FILE_SIZE = '1048576';
    expect(maxEmbeddedFileSize()).toBe(1048576);
  });

  it.each(['0', '-5', '20MB', '1.5', 'abc'])(
    'ignores %s for the default, with one warning',
    async (value) => {
      const { logger } = await import('../logger.js');
      const warn = vi.spyOn(logger, 'warn').mockImplementation(() => undefined);
      process.env.MCP_MAX_EMBEDDED_FILE_SIZE = value;

      expect(maxEmbeddedFileSize()).toBe(DEFAULT);
      expect(maxEmbeddedFileSize()).toBe(DEFAULT);
      expect(warn).toHaveBeenCalledTimes(1);
      expect(warn.mock.calls[0][0]).toMatchObject({ value });
    }
  );
});

describe('a format that is binary by definition', () => {
  // An uncompressed PDF can hold no NUL in its first 8 KB.
  const pdf = text('%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n');
  const uri = 'nextcloud:///plain.pdf';

  it('is embedded, not decoded as text', () => {
    const blocks = fileContent({ name: 'plain.pdf', bytes: pdf, uri }, { header: false });
    expect(blocks[1]).toMatchObject({
      type: 'resource',
      resource: { mimeType: 'application/pdf' },
    });
  });

  it('is a blob in resource contents', () => {
    expect(resourceContents({ name: 'attachment-2', bytes: pdf, uri })).toHaveProperty('blob');
    expect(resourceContents({ name: 'a.json', bytes: text('{}'), uri })).toHaveProperty('text');
  });
});

describe('fileUri', () => {
  it('percent-encodes each segment', () => {
    expect(fileUri('/Dossier été/a b#1.pdf')).toBe(
      'nextcloud:///Dossier%20%C3%A9t%C3%A9/a%20b%231.pdf'
    );
    expect(fileUri('a.pdf')).toBe('nextcloud:///a.pdf');
  });
});

describe('fileContent', () => {
  afterEach(() => {
    delete process.env.MCP_MAX_EMBEDDED_FILE_SIZE;
  });

  const uri = 'nextcloud:///f';

  it('returns text bare or under a header', () => {
    const file = { name: 'a.md', bytes: text('# A'), mimeType: 'text/markdown', uri };
    expect(fileContent(file, { header: false })).toEqual([{ type: 'text', text: '# A' }]);
    expect(fileContent(file, { header: true })).toEqual([
      { type: 'text', text: 'File: a.md (text/markdown, 3 bytes)\n\n# A' },
    ]);
  });

  it('returns SVG as text, not as an image block', () => {
    const file = { name: 'a.svg', bytes: text('<svg/>'), mimeType: 'image/svg+xml', uri };
    expect(fileContent(file, { header: false })).toEqual([{ type: 'text', text: '<svg/>' }]);
  });

  it('returns an image as an image block', () => {
    const file = {
      name: 'a.gif',
      bytes: bytes(0x47, 0x49, 0x46, 0x38, 0),
      mimeType: 'image/gif',
      uri,
    };
    expect(fileContent(file, { header: false })[1]).toEqual({
      type: 'image',
      data: 'R0lGOAA=',
      mimeType: 'image/gif',
    });
  });

  it('embeds any other binary file, bytes intact', () => {
    const data = bytes(0x25, 0x50, 0x44, 0x46, 0x2d, 0, 255, 254);
    const blocks = fileContent({ name: 'a.pdf', bytes: data, uri }, { header: true });
    expect(blocks[0]).toEqual({
      type: 'text',
      text: 'File: a.pdf (application/pdf, 8 bytes)\nThe file itself follows as an embedded resource.',
    });
    const resource = blocks[1] as { resource: { uri: string; mimeType: string; blob: string } };
    expect(resource.resource.uri).toBe(uri);
    expect(resource.resource.mimeType).toBe('application/pdf');
    expect(new Uint8Array(Buffer.from(resource.resource.blob, 'base64'))).toEqual(data);
  });

  it('embeds a view of a larger buffer without its neighbours', () => {
    const view = new Uint8Array(bytes(9, 9, 0, 1, 2, 9).buffer, 2, 3);
    const blocks = binaryContent({ name: 'x.bin', bytes: view, uri }, 'File: x.bin');
    const resource = blocks[1] as { resource: { blob: string } };
    expect([...Buffer.from(resource.resource.blob, 'base64')]).toEqual([0, 1, 2]);
  });

  it('describes an image over the limit instead of sending it', () => {
    process.env.MCP_MAX_EMBEDDED_FILE_SIZE = '4';
    const file = { name: 'a.gif', bytes: bytes(0x47, 0x49, 0x46, 0x38, 0), uri };
    const blocks = fileContent(file, { header: false });
    expect(blocks).toHaveLength(1);
    expect((blocks[0] as { text: string }).text).toContain('File: a.gif (image/gif, 5 bytes)');
    expect((blocks[0] as { text: string }).text).toContain('MCP_MAX_EMBEDDED_FILE_SIZE');
  });

  it('describes a file over the limit instead of embedding it', () => {
    process.env.MCP_MAX_EMBEDDED_FILE_SIZE = '2';
    const blocks = binaryContent({ name: 'x.bin', bytes: bytes(0, 1, 2), uri }, 'File: x.bin');
    expect(blocks).toHaveLength(1);
    expect((blocks[0] as { text: string }).text).toContain('MCP_MAX_EMBEDDED_FILE_SIZE');
  });
});
