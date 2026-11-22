// SPDX-License-Identifier: MIT

/**
 * MCP resources: the files and mail attachments that tools embed in their
 * results, readable by URI.
 *
 * MCP 2026-07-28, Tools › Tool Result › Embedded Resources: "Servers that use
 * embedded resources SHOULD implement the `resources` capability". read_file,
 * get_file_content and mail_get_attachment embed a file under a URI; here that
 * URI answers `resources/read`, so a client can fetch the file again or attach
 * one on its own.
 *
 * Resources › Capabilities: the set `resources/list` returns "MAY be empty".
 * It is: an account's files are not enumerated. What can be read is described
 * by two templates (RFC 6570), in custom schemes as Resources › Common URI
 * Schemes asks for content a client cannot fetch from the web by itself:
 *
 * - `nextcloud:///{+path}`, a file in the account's Files;
 * - `nextcloud-mail:///messages/{messageId}/attachments/{attachmentId}`.
 *
 * `listChanged` and `subscribe` are not offered: the templates never change,
 * and nothing watches the files.
 */

import {
  ProtocolError,
  ProtocolErrorCode,
  ResourceNotFoundError,
  ResourceTemplate,
  type McpServer,
} from '@modelcontextprotocol/server';
import { getWebDAVClient, WebDAVError } from './client/webdav.js';
import { logger } from './logger.js';
import { reportToolFailure } from './nc-context.js';
import { AttachmentError, fetchAttachment } from './tools/apps/mail.js';
import {
  maxEmbeddedFileSize,
  resourceContents,
  type ResourceContents,
} from './tools/file-content.js';

export const FILE_URI_TEMPLATE = 'nextcloud:///{+path}';
export const MAIL_ATTACHMENT_URI_TEMPLATE =
  'nextcloud-mail:///messages/{messageId}/attachments/{attachmentId}';

function invalid(uri: URL, message: string): ProtocolError {
  return new ProtocolError(ProtocolErrorCode.InvalidParams, message, { uri: uri.href });
}

function tooLarge(uri: URL, size: number): ProtocolError {
  return invalid(
    uri,
    `Resource is ${size} bytes, over the ${maxEmbeddedFileSize()} this server sends (MCP_MAX_EMBEDDED_FILE_SIZE)`
  );
}

/**
 * The Files path a `nextcloud:///` URI names. Resources › Security
 * Considerations: "Servers MUST validate all resource URIs". The URL parser has
 * already resolved dot-segments (RFC 3986 §5.2.4); a segment that still decodes
 * to one, or to something holding a slash, names no file and is refused before
 * WebDAV sees it.
 */
export function filePathFromUri(uri: URL): string {
  if (uri.host !== '' || uri.search !== '' || uri.hash !== '') {
    throw invalid(uri, 'A file URI has the form nextcloud:///<path>');
  }
  const segments = uri.pathname.split('/').filter((segment) => segment !== '');
  if (segments.length === 0) throw invalid(uri, 'The URI names no file');
  const decoded = segments.map((segment) => {
    let name: string;
    try {
      name = decodeURIComponent(segment);
    } catch {
      throw invalid(uri, 'The URI has a malformed percent-encoding');
    }
    if (name === '.' || name === '..' || name.includes('/')) {
      throw invalid(uri, 'The URI has a path segment that names no file');
    }
    return name;
  });
  return `/${decoded.join('/')}`;
}

async function readFile(uri: URL): Promise<ResourceContents> {
  const path = filePathFromUri(uri);
  const client = getWebDAVClient();
  try {
    const stat = await client.stat(path);
    if (stat.type === 'directory') throw invalid(uri, 'The URI names a folder, not a file');
    if (stat.size > maxEmbeddedFileSize()) throw tooLarge(uri, stat.size);
    const bytes = await client.getFileContents(path, { format: 'binary' });
    return resourceContents({ name: stat.basename, bytes, mimeType: stat.mime, uri: uri.href });
  } catch (error) {
    // Resources › Error Handling: a resource that does not exist is an error,
    // never an empty `contents`.
    if (error instanceof WebDAVError && error.status === 404) {
      throw new ResourceNotFoundError(uri.href);
    }
    throw error;
  }
}

async function readAttachment(
  uri: URL,
  messageId: string,
  attachmentId: string
): Promise<ResourceContents> {
  if (!/^\d+$/.test(messageId)) throw new ResourceNotFoundError(uri.href);
  let id: string;
  try {
    id = decodeURIComponent(attachmentId);
  } catch {
    throw invalid(uri, 'The URI has a malformed percent-encoding');
  }
  try {
    const file = await fetchAttachment(Number(messageId), id);
    if (file.bytes.byteLength > maxEmbeddedFileSize()) throw tooLarge(uri, file.bytes.byteLength);
    return resourceContents({ ...file, uri: uri.href });
  } catch (error) {
    // Mail answers 403 for a message that is not the caller's, 404 for one
    // that is gone: to the caller, neither exists.
    if (error instanceof AttachmentError && (error.status === 403 || error.status === 404)) {
      throw new ResourceNotFoundError(uri.href);
    }
    throw error;
  }
}

/** Run a read, with the logging and credential check a tool call gets. */
async function served(
  kind: string,
  uri: URL,
  read: () => Promise<ResourceContents>
): Promise<{ contents: ResourceContents[] }> {
  const start = Date.now();
  try {
    const contents = await read();
    logger.debug({ resource: kind, ms: Date.now() - start }, '[resource] Read');
    return { contents: [contents] };
  } catch (error) {
    if (!(error instanceof ProtocolError)) reportToolFailure();
    logger.warn(
      {
        resource: kind,
        error: error instanceof Error ? error.message : String(error),
        ms: Date.now() - start,
      },
      '[resource] Read failed'
    );
    throw error;
  }
}

/** Which resources go with a set of tools: those the tools embed in their results. */
function kinds(toolNames: ReadonlySet<string>): { files: boolean; mail: boolean } {
  return {
    files: toolNames.has('read_file') || toolNames.has('get_file_content'),
    mail: toolNames.has('mail_get_attachment'),
  };
}

/** Whether a server with these tools serves resources, and so declares the capability. */
export function servesResources(toolNames: ReadonlySet<string>): boolean {
  const { files, mail } = kinds(toolNames);
  return files || mail;
}

/**
 * Register the resource templates that match the tools in use: files where a
 * file tool embeds them, mail attachments where mail_get_attachment does.
 */
export function registerResources(server: McpServer, toolNames: ReadonlySet<string>): void {
  const { files, mail } = kinds(toolNames);

  if (files) {
    server.registerResource(
      'nextcloud-file',
      new ResourceTemplate(FILE_URI_TEMPLATE, { list: undefined }),
      {
        title: 'Nextcloud file',
        description:
          "A file in the signed-in account's Nextcloud Files, by its path: text as text, any other format as its bytes.",
      },
      (uri) => served('file', uri, () => readFile(uri))
    );
  }
  if (mail) {
    server.registerResource(
      'nextcloud-mail-attachment',
      new ResourceTemplate(MAIL_ATTACHMENT_URI_TEMPLATE, { list: undefined }),
      {
        title: 'Nextcloud Mail attachment',
        description:
          'An attachment of a message in Nextcloud Mail, by message ID and attachment ID (both shown by mail_read_message).',
      },
      (uri, variables) =>
        served('mail-attachment', uri, () =>
          readAttachment(uri, String(variables.messageId), String(variables.attachmentId))
        )
    );
  }
}
