// SPDX-License-Identifier: MIT

import { describe, it, expect, vi, beforeEach } from 'vitest';
import {
  ApiError,
  aiquilaErrorDetail,
  fetchAiquilaAPI,
  formatOccError,
} from '../client/aiquila.js';

global.fetch = vi.fn();

function errorResponse(status: number, statusText: string, body: string): Response {
  return {
    ok: false,
    status,
    statusText,
    text: async () => body,
  } as unknown as Response;
}

async function rejection(promise: Promise<unknown>): Promise<ApiError> {
  try {
    await promise;
  } catch (e) {
    expect(e).toBeInstanceOf(ApiError);
    return e as ApiError;
  }
  throw new Error('expected the request to reject');
}

describe('fetchAiquilaAPI errors', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    process.env.NEXTCLOUD_URL = 'https://cloud.example.com';
    process.env.NEXTCLOUD_USER = 'admin';
    process.env.NEXTCLOUD_PASSWORD = 'testpass';
  });

  it("appends the app's error message from its JSON error shape", async () => {
    const body = JSON.stringify({ error: 'Invalid cron schedule: nope', errorId: '' });
    vi.mocked(global.fetch).mockResolvedValue(errorResponse(400, 'Bad Request', body));

    const e = await rejection(fetchAiquilaAPI('/coworkers/1', { method: 'PUT', body: {} }));
    expect(e.message).toBe('AIquila API error: 400 Bad Request: Invalid cron schedule: nope');
    expect(e.statusCode).toBe(400);
    expect(e.responseBody).toBe(body);
  });

  it('includes the errorId of a server-side failure', async () => {
    const body = JSON.stringify({ error: 'Failed to create archive', errorId: 'a1b2c3d4' });
    vi.mocked(global.fetch).mockResolvedValue(errorResponse(500, 'Internal Server Error', body));

    const e = await rejection(fetchAiquilaAPI('/files/zip', { method: 'POST', body: {} }));
    expect(e.message).toBe(
      'AIquila API error: 500 Internal Server Error: Failed to create archive (error ID a1b2c3d4)'
    );
  });

  it('ignores an HTML error page', async () => {
    const body = '<!DOCTYPE html><html><body><h1>Internal Server Error</h1></body></html>';
    vi.mocked(global.fetch).mockResolvedValue(errorResponse(500, 'Internal Server Error', body));

    const e = await rejection(fetchAiquilaAPI('/projects'));
    expect(e.message).toBe('AIquila API error: 500 Internal Server Error');
    expect(e.detail).toBeUndefined();
  });

  it('ignores an empty body', async () => {
    vi.mocked(global.fetch).mockResolvedValue(errorResponse(404, 'Not Found', ''));

    const e = await rejection(fetchAiquilaAPI('/projects/9'));
    expect(e.message).toBe('AIquila API error: 404 Not Found');
  });
});

describe('aiquilaErrorDetail', () => {
  it('ignores JSON that is not the app error shape', () => {
    expect(aiquilaErrorDetail('{"message":"Trace: /var/www/html/lib/x.php"}')).toBeUndefined();
    expect(aiquilaErrorDetail('{"error":{"trace":"/var/www"}}')).toBeUndefined();
    expect(aiquilaErrorDetail('"just a string"')).toBeUndefined();
    expect(aiquilaErrorDetail('null')).toBeUndefined();
    expect(aiquilaErrorDetail('{"error":"   "}')).toBeUndefined();
  });

  it('collapses whitespace and caps the length', () => {
    expect(aiquilaErrorDetail('{"error":"line one\\n  line two"}')).toBe('line one line two');
    const long = aiquilaErrorDetail(JSON.stringify({ error: 'x'.repeat(1000) }));
    expect(long).toHaveLength(300);
    expect(long?.endsWith('…')).toBe(true);
  });

  it('drops an errorId that is not a short hex token', () => {
    expect(aiquilaErrorDetail('{"error":"Boom","errorId":"<script>"}')).toBe('Boom');
  });
});

describe('formatOccError', () => {
  it('adds the app reason to the generic fallback', () => {
    const e = new ApiError(400, 'Bad Request', '', 'No command provided');
    expect(formatOccError(e)).toBe('Request failed (HTTP 400 Bad Request): No command provided');
  });

  it('keeps the plain fallback without a reason', () => {
    expect(formatOccError(new ApiError(502, 'Bad Gateway', ''))).toBe(
      'Request failed (HTTP 502 Bad Gateway).'
    );
  });
});
