// SPDX-License-Identifier: MIT

/**
 * A file read from Nextcloud as MCP tool-result content: text as text, an
 * image as an image block, and any other format (PDF, office documents,
 * archives, audio) as an embedded resource whose `blob` is the file itself
 * (MCP 2026-07-28 and 2025-11-25, Tools › Tool Result › Embedded Resources;
 * Resources › Resource Contents › Binary Content).
 *
 * What a client does with an embedded resource is its own business: Claude
 * Code shows a PDF to the model or saves the bytes to a local file and gives
 * the model its path. The text block ahead of the resource names the file for
 * clients that drop the block.
 */

import { logger } from '../logger.js';

export type ContentBlock =
  | { type: 'text'; text: string }
  | { type: 'image'; data: string; mimeType: string }
  | { type: 'resource'; resource: { uri: string; mimeType: string; blob: string } };

export interface FileBytes {
  /** File name, shown to the caller and used to guess a missing media type. */
  name: string;
  bytes: Uint8Array;
  /** Media type as Nextcloud reports it, when it does. */
  mimeType?: string;
  /** URI the embedded resource is identified by. */
  uri: string;
}

const DEFAULT_MAX_EMBEDDED_FILE_SIZE = 20 * 1024 * 1024;
let warnedAbout: string | undefined;

/**
 * Largest file embedded in a tool result, in bytes (`MCP_MAX_EMBEDDED_FILE_SIZE`).
 * The default is the AIquila app's own limit on `/api/files/content`. Base64
 * makes the JSON-RPC message a third larger than the file.
 *
 * The value has to be a positive integer. Anything else (`0`, a negative
 * number, `20MB`) is ignored for the default, with a warning in the log.
 */
export function maxEmbeddedFileSize(): number {
  const raw = process.env.MCP_MAX_EMBEDDED_FILE_SIZE?.trim();
  if (!raw) return DEFAULT_MAX_EMBEDDED_FILE_SIZE;
  const value = /^\d+$/.test(raw) ? Number(raw) : NaN;
  if (Number.isSafeInteger(value) && value > 0) return value;
  if (warnedAbout !== raw) {
    warnedAbout = raw;
    logger.warn(
      { value: raw, default: DEFAULT_MAX_EMBEDDED_FILE_SIZE },
      '[config] MCP_MAX_EMBEDDED_FILE_SIZE is not a positive integer; using the default'
    );
  }
  return DEFAULT_MAX_EMBEDDED_FILE_SIZE;
}

/** Media types by extension, for when Nextcloud or a mail gives none of use. */
const MIME_BY_EXTENSION: Record<string, string> = {
  pdf: 'application/pdf',
  docx: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
  xlsx: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
  pptx: 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
  doc: 'application/msword',
  xls: 'application/vnd.ms-excel',
  ppt: 'application/vnd.ms-powerpoint',
  odt: 'application/vnd.oasis.opendocument.text',
  ods: 'application/vnd.oasis.opendocument.spreadsheet',
  odp: 'application/vnd.oasis.opendocument.presentation',
  rtf: 'application/rtf',
  epub: 'application/epub+zip',
  zip: 'application/zip',
  png: 'image/png',
  jpg: 'image/jpeg',
  jpeg: 'image/jpeg',
  gif: 'image/gif',
  webp: 'image/webp',
  mp3: 'audio/mpeg',
  wav: 'audio/wav',
  ogg: 'audio/ogg',
  mp4: 'video/mp4',
};

const SIGNATURES: Array<[number[], string]> = [
  [[0x25, 0x50, 0x44, 0x46, 0x2d], 'application/pdf'], // %PDF-
  [[0x89, 0x50, 0x4e, 0x47], 'image/png'],
  [[0xff, 0xd8, 0xff], 'image/jpeg'],
  [[0x47, 0x49, 0x46, 0x38], 'image/gif'],
];

/**
 * The media type of a file: the declared one, unless there is none or it is
 * the catch-all application/octet-stream, as for many mail attachments. Then
 * the extension decides, then the first bytes.
 */
export function fileMimeType(file: Pick<FileBytes, 'name' | 'bytes' | 'mimeType'>): string {
  const declared = file.mimeType?.split(';')[0].trim().toLowerCase();
  if (declared && declared !== 'application/octet-stream') return declared;
  const extension = file.name.includes('.') ? file.name.split('.').pop()!.toLowerCase() : '';
  if (extension in MIME_BY_EXTENSION) return MIME_BY_EXTENSION[extension];
  const signature = SIGNATURES.find(([bytes]) => bytes.every((b, i) => file.bytes[i] === b));
  return signature?.[1] ?? 'application/octet-stream';
}

/**
 * Formats that are binary by definition. A file of one of these types is sent
 * as a file whatever its first bytes look like: a small uncompressed PDF can
 * go 8 KB without a NUL.
 */
const BINARY_TYPES = new Set([
  'application/pdf',
  'application/zip',
  'application/epub+zip',
  'application/msword',
  'application/vnd.ms-excel',
  'application/vnd.ms-powerpoint',
]);

/** Whether a media type names a format that is never text. */
export function isBinaryType(mimeType: string): boolean {
  return (
    BINARY_TYPES.has(mimeType) ||
    mimeType.startsWith('application/vnd.openxmlformats-officedocument.') ||
    mimeType.startsWith('application/vnd.oasis.opendocument.')
  );
}

/** How many bytes from the start of a file `isText` looks at. */
export const TEXT_PROBE_LENGTH = 8192;

/** Whether the bytes are text: no NUL in the first block, as git and grep decide it. */
export function isText(bytes: Uint8Array): boolean {
  // UTF-16 text is full of NULs; its byte order mark tells it apart.
  if ((bytes[0] === 0xff && bytes[1] === 0xfe) || (bytes[0] === 0xfe && bytes[1] === 0xff)) {
    return true;
  }
  return !bytes.subarray(0, TEXT_PROBE_LENGTH).includes(0);
}

/** Whether a media type is one sent as an image block. SVG is text by nature. */
export function isImageType(mimeType: string): boolean {
  return mimeType.startsWith('image/') && mimeType !== 'image/svg+xml';
}

/**
 * Whether a file goes out as an image block or an embedded resource, and so
 * is held to the size limit; text is not. The first `TEXT_PROBE_LENGTH` bytes
 * are enough to tell.
 */
export function isSizeLimited(file: Pick<FileBytes, 'name' | 'bytes' | 'mimeType'>): boolean {
  const mimeType = fileMimeType(file);
  return isImageType(mimeType) || isBinaryType(mimeType) || !isText(file.bytes);
}

export function decodeText(bytes: Uint8Array): string {
  const encoding =
    bytes[0] === 0xff && bytes[1] === 0xfe
      ? 'utf-16le'
      : bytes[0] === 0xfe && bytes[1] === 0xff
        ? 'utf-16be'
        : 'utf-8';
  return new TextDecoder(encoding).decode(bytes);
}

export function formatSize(bytes: number): string {
  return bytes < 1024 * 1024
    ? `${(bytes / 1024).toFixed(1)} KB`
    : `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

function base64(bytes: Uint8Array): string {
  return Buffer.from(bytes.buffer, bytes.byteOffset, bytes.byteLength).toString('base64');
}

/** `nextcloud:///Documents/a%20b.pdf` for a path in the account's Files. */
export function fileUri(path: string): string {
  return `nextcloud:///${path.split('/').filter(Boolean).map(encodeURIComponent).join('/')}`;
}

/** The contents of a resource: text, or the bytes in base64 (MCP, Resources › Resource Contents). */
export type ResourceContents =
  { uri: string; mimeType: string; text: string } | { uri: string; mimeType: string; blob: string };

/** A file as resource contents: `text` for a text file, `blob` for anything else. */
export function resourceContents(file: FileBytes): ResourceContents {
  const mimeType = fileMimeType(file);
  return !isImageType(mimeType) && !isBinaryType(mimeType) && isText(file.bytes)
    ? { uri: file.uri, mimeType, text: decodeText(file.bytes) }
    : { uri: file.uri, mimeType, blob: base64(file.bytes) };
}

/**
 * The block that stands for a binary file or an image too large to send, or undefined when
 * it fits. It takes the size alone, so a caller that knows it beforehand can
 * refuse the file without downloading it.
 */
export function oversizedContent(
  label: string,
  mimeType: string,
  size: number
): ContentBlock[] | undefined {
  const limit = maxEmbeddedFileSize();
  if (size <= limit) return undefined;
  return [
    {
      type: 'text',
      text: `${label} (${mimeType}, ${size} bytes)\nBinary file of ${formatSize(size)}, over the ${formatSize(limit)} this server sends to a client (MCP_MAX_EMBEDDED_FILE_SIZE).`,
    },
  ];
}

/**
 * The blocks that hand a binary file to the client: a line of text naming it,
 * then the embedded resource. `label` starts the line, e.g. `File: a.pdf`.
 * A file over the size limit is described instead.
 */
export function binaryContent(file: FileBytes, label: string): ContentBlock[] {
  const mimeType = fileMimeType(file);
  const size = file.bytes.byteLength;
  return (
    oversizedContent(label, mimeType, size) ?? [
      {
        type: 'text',
        text: `${label} (${mimeType}, ${size} bytes)\nThe file itself follows as an embedded resource.`,
      },
      { type: 'resource', resource: { uri: file.uri, mimeType, blob: base64(file.bytes) } },
    ]
  );
}

/**
 * The blocks that hand an image to the client: `label (type, size)`, then the
 * image block. An image over the size limit is described instead.
 */
export function imageContent(bytes: Uint8Array, mimeType: string, label: string): ContentBlock[] {
  const size = bytes.byteLength;
  return (
    oversizedContent(label, mimeType, size) ?? [
      { type: 'text', text: `${label} (${mimeType}, ${size} bytes)` },
      { type: 'image', data: base64(bytes), mimeType },
    ]
  );
}

/**
 * MCP content for a file. With `header`, text and images are introduced by a
 * `File: name (type, size)` line, as binary files always are.
 */
export function fileContent(file: FileBytes, options: { header: boolean }): ContentBlock[] {
  const mimeType = fileMimeType(file);
  const about = `File: ${file.name} (${mimeType}, ${file.bytes.byteLength} bytes)`;

  // SVG is an image by type and text by nature; models read it as text.
  if (isImageType(mimeType)) return imageContent(file.bytes, mimeType, `File: ${file.name}`);
  if (!isBinaryType(mimeType) && isText(file.bytes)) {
    const text = decodeText(file.bytes);
    return [{ type: 'text', text: options.header ? `${about}\n\n${text}` : text }];
  }
  return binaryContent(file, `File: ${file.name}`);
}
