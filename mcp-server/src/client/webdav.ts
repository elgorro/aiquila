// SPDX-License-Identifier: MIT

// WebDAV file operations on the signed-in account's Files (RFC 4918), on
// native fetch through the same request plumbing as the CalDAV/CardDAV tools.

import { decodeXmlEntities, fetchCalDAV } from './caldav.js';
import { davFilePath, davPath } from '../tools/dav-utils.js';
import { getNextcloudConfig } from '../tools/types.js';

/** One file or folder, as list_files reports it. */
export interface FileStat {
  /** Path inside the account's Files, starting with `/`. */
  filename: string;
  basename: string;
  /** `getlastmodified` as the server sent it (an HTTP-date, RFC 9110 §5.6.7). */
  lastmod: string | null;
  size: number;
  type: 'file' | 'directory';
  /** Entity tag without its quotes. */
  etag: string | null;
  /** Media type without parameters; files only. */
  mime?: string;
}

/** A stat with the extra properties that were asked for, by local name. */
export interface DetailedFileStat extends FileStat {
  props: Record<string, string>;
}

/**
 * A request the server answered with an error status. The message keeps the
 * form tools have always shown (`Invalid response: 404 Not Found`), and
 * `status` lets callers branch on it.
 */
export class WebDAVError extends Error {
  readonly status: number;
  constructor(status: number, statusText: string) {
    super(`Invalid response: ${status} ${statusText}`.trimEnd());
    this.name = 'WebDAVError';
    this.status = status;
  }
}

export interface WebDAVClient {
  /** Depth 1 PROPFIND; the folder itself is left out. */
  getDirectoryContents(path: string): Promise<FileStat[]>;
  /**
   * Depth 0 PROPFIND. `props` are extra properties as `prefix:name` with the
   * prefix `d` (DAV:), `oc` (owncloud) or `nc` (nextcloud).
   */
  stat(path: string, options?: { props?: string[] }): Promise<DetailedFileStat>;
  /** Whether the path exists; false on 404, any other error is thrown. */
  exists(path: string): Promise<boolean>;
  getFileContents(path: string, options?: { format?: 'text' }): Promise<string>;
  getFileContents(path: string, options: { format: 'binary' }): Promise<Uint8Array<ArrayBuffer>>;
  /** `overwrite: false` fails with 412 when the file exists (RFC 9110 §13.1.2). */
  putFileContents(
    path: string,
    data: string | Uint8Array<ArrayBuffer>,
    options?: { overwrite?: boolean }
  ): Promise<void>;
  deleteFile(path: string): Promise<void>;
  /** `overwrite` defaults to true, as RFC 4918 §10.6 does. */
  moveFile(source: string, destination: string, options?: { overwrite?: boolean }): Promise<void>;
  copyFile(source: string, destination: string, options?: { overwrite?: boolean }): Promise<void>;
  /** `recursive` creates missing parents and accepts a folder that already exists. */
  createDirectory(path: string, options?: { recursive?: boolean }): Promise<void>;
}

const NAMESPACES: Record<string, string> = {
  d: 'DAV:',
  oc: 'http://owncloud.org/ns',
  nc: 'http://nextcloud.org/ns',
};

/** What every PROPFIND asks for: enough to build a FileStat. */
const BASE_PROPS = [
  'd:getlastmodified',
  'd:getcontentlength',
  'd:resourcetype',
  'd:getcontenttype',
  'd:getetag',
];

function propfindBody(props: string[]): string {
  const xmlns = Object.entries(NAMESPACES)
    .map(([prefix, uri]) => `xmlns:${prefix}="${uri}"`)
    .join(' ');
  const elements = props.map((p) => {
    const [prefix] = p.split(':');
    if (!(prefix in NAMESPACES) || !/^[a-z]+:[A-Za-z][\w.-]*$/.test(p)) {
      throw new Error(`Unsupported WebDAV property: ${p}`);
    }
    return `<${p}/>`;
  });
  return `<?xml version="1.0" encoding="utf-8"?>\n<d:propfind ${xmlns}><d:prop>${elements.join('')}</d:prop></d:propfind>`;
}

/**
 * An element by local name, any namespace prefix. Unlike a bare prefix match,
 * the name has to end there, so `prop` does not match `<d:propstat>`.
 */
function element(localName: string, flags = ''): RegExp {
  const p = '(?:[A-Za-z][\\w.-]*:)?';
  return new RegExp(`<${p}${localName}(?:\\s[^>]*)?>([\\s\\S]*?)</${p}${localName}\\s*>`, flags);
}

/** The status code of a `<d:status>HTTP/1.1 200 OK</d:status>` line (RFC 4918 §14.28). */
function statusCode(block: string): number | undefined {
  const m = block.match(element('status'));
  const code = m?.[1].trim().match(/^HTTP\/\d(?:\.\d)?\s+(\d{3})/);
  return code ? Number(code[1]) : undefined;
}

/** The text of every property in the propstat blocks that report 200 (RFC 4918 §14.22). */
function okProps(response: string): Map<string, string> {
  const props = new Map<string, string>();
  const propstats = response.match(element('propstat', 'g')) ?? [];
  for (const propstat of propstats) {
    if (statusCode(propstat) !== 200) continue;
    const prop = propstat.match(element('prop'))?.[1] ?? '';
    // Children of <prop>: either <x:name .../> or <x:name ...>value</x:name>.
    const child =
      /<(?:([A-Za-z][\w.-]*):)?([A-Za-z][\w.-]*)([^>]*?)(?:\/>|>([\s\S]*?)<\/(?:\1:)?\2>)/g;
    for (const m of prop.matchAll(child)) {
      props.set(m[2], m[4] ?? '');
    }
  }
  return props;
}

function decodeHrefPath(href: string): string {
  // An href is an absolute URI or an absolute path (RFC 4918 §8.3).
  const path = /^[a-z][a-z0-9+.-]*:/i.test(href) ? new URL(href).pathname : href;
  try {
    return decodeURIComponent(path);
  } catch {
    return path;
  }
}

/**
 * Turn a 207 multistatus (RFC 4918 §13) into FileStats. `basePath` is the
 * decoded URL path of the account's Files root; hrefs outside it are skipped.
 */
export function parseMultistatus(
  xml: string,
  basePath: string,
  extra: string[] = []
): DetailedFileStat[] {
  const root = basePath.replace(/\/+$/, '');
  const extraNames = extra.map((p) => p.split(':')[1]);
  const responses = xml.match(element('response', 'g')) ?? [];
  const stats: DetailedFileStat[] = [];
  for (const response of responses) {
    const href = response.match(element('href'))?.[1];
    if (!href) continue;
    const path = decodeHrefPath(decodeXmlEntities(href.trim()));
    if (path !== root && !path.startsWith(`${root}/`)) continue;
    const filename = `/${path.slice(root.length).replace(/^\/+|\/+$/g, '')}`;
    const props = okProps(response);
    const type = /<(?:[A-Za-z][\w.-]*:)?collection\b/.test(props.get('resourcetype') ?? '')
      ? 'directory'
      : 'file';
    const etag = props.get('getetag');
    const stat: DetailedFileStat = {
      filename,
      basename: filename.split('/').pop() ?? '',
      lastmod: props.has('getlastmodified')
        ? decodeXmlEntities(props.get('getlastmodified')!)
        : null,
      size: parseInt(props.get('getcontentlength') ?? '0', 10) || 0,
      type,
      etag: etag !== undefined ? decodeXmlEntities(etag).replace(/"/g, '') : null,
      props: {},
    };
    if (type === 'file') {
      stat.mime = decodeXmlEntities(props.get('getcontenttype') ?? '')
        .split(';')[0]
        .trim();
    }
    for (const name of extraNames) {
      const value = props.get(name);
      if (value !== undefined) stat.props[name] = decodeXmlEntities(value).trim();
    }
    stats.push(stat);
  }
  return stats;
}

/** `/a//b/` → `/a/b`, the form FileStat.filename uses. */
function normalise(path: string): string {
  return `/${path.split('/').filter(Boolean).join('/')}`;
}

/**
 * Get the WebDAV client for the account of the request being served.
 *
 * It holds no connection or credentials of its own: every request reads the
 * account from the request context, so a signed-in user's app password is
 * not kept beyond the call that needs it.
 */
export function getWebDAVClient(): WebDAVClient {
  const config = getNextcloudConfig();
  const root = `${config.url}${davPath('files', config.user)}`;
  const basePath = decodeURIComponent(new URL(root).pathname);
  const url = (path: string, collection = false) => {
    const rel = davFilePath(path);
    return `${root}/${rel}${collection && rel ? '/' : ''}`;
  };

  async function send(
    method: string,
    target: string,
    init: { headers?: Record<string, string>; body?: string | Uint8Array<ArrayBuffer> } = {}
  ): Promise<Response> {
    const response = await fetchCalDAV(target, {
      method,
      headers: init.headers,
      body: init.body,
    });
    if (response.status >= 400) {
      // Drain the body so the connection can be reused.
      await response.text().catch(() => undefined);
      throw new WebDAVError(response.status, response.statusText);
    }
    return response;
  }

  async function propfind(
    path: string,
    depth: '0' | '1',
    extra: string[]
  ): Promise<DetailedFileStat[]> {
    const response = await send('PROPFIND', url(path, depth === '1'), {
      headers: { Depth: depth },
      body: propfindBody([...BASE_PROPS, ...extra]),
    });
    if (response.status !== 207) {
      throw new WebDAVError(response.status, `${response.statusText} (expected 207 Multi-Status)`);
    }
    return parseMultistatus(await response.text(), basePath, extra);
  }

  const transfer =
    (method: 'MOVE' | 'COPY') =>
    async (source: string, destination: string, options: { overwrite?: boolean } = {}) => {
      // Destination is an absolute URI (RFC 4918 §10.3); Overwrite is T or F (§10.6).
      await send(method, url(source), {
        headers: {
          Destination: url(destination),
          Overwrite: options.overwrite === false ? 'F' : 'T',
        },
      });
    };

  async function mkcol(path: string, tolerateExisting: boolean): Promise<void> {
    try {
      await send('MKCOL', url(path, true));
    } catch (error) {
      // 405: the resource already exists (RFC 4918 §9.3.1).
      if (tolerateExisting && error instanceof WebDAVError && error.status === 405) return;
      throw error;
    }
  }

  return {
    async getDirectoryContents(path) {
      const self = normalise(path);
      return (await propfind(path, '1', []))
        .filter((s) => s.filename !== self)
        .map(({ props: _props, ...stat }) => stat);
    },

    async stat(path, options = {}) {
      const [stat] = await propfind(path, '0', options.props ?? []);
      if (!stat) throw new WebDAVError(404, 'Not Found');
      return stat;
    },

    async exists(path) {
      try {
        await propfind(path, '0', []);
        return true;
      } catch (error) {
        if (error instanceof WebDAVError && error.status === 404) return false;
        throw error;
      }
    },

    getFileContents: (async (path: string, options: { format?: 'text' | 'binary' } = {}) => {
      const response = await send('GET', url(path));
      return options.format === 'binary'
        ? new Uint8Array(await response.arrayBuffer())
        : response.text();
    }) as WebDAVClient['getFileContents'],

    async putFileContents(path, data, options = {}) {
      await send('PUT', url(path), {
        headers: {
          'Content-Type': 'application/octet-stream',
          ...(options.overwrite === false ? { 'If-None-Match': '*' } : {}),
        },
        body: data,
      });
    },

    async deleteFile(path) {
      await send('DELETE', url(path));
    },

    moveFile: transfer('MOVE'),
    copyFile: transfer('COPY'),

    async createDirectory(path, options = {}) {
      const segments = path.split('/').filter(Boolean);
      if (!options.recursive) return mkcol(path, false);
      // MKCOL fails with 409 when a parent is missing (§9.3.1), so create each level.
      for (let i = 1; i <= segments.length; i++) {
        await mkcol(`/${segments.slice(0, i).join('/')}`, true);
      }
    },
  };
}
