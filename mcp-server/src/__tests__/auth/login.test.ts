import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { NextcloudOAuthProvider } from '../../auth/provider.js';
import { loginHandler } from '../../auth/login.js';

global.fetch = vi.fn();

const BASE_BODY = {
  username: 'alice',
  password: 'secret',
  client_id: 'c1',
  redirect_uri: 'https://example.com/callback',
  state: 'xyz',
  code_challenge: 'challenge-abc',
  scope: 'read write',
};

function makeRes() {
  const res = {
    status: vi.fn().mockReturnThis(),
    type: vi.fn().mockReturnThis(),
    send: vi.fn().mockReturnThis(),
    redirect: vi.fn(),
  };
  return res;
}

describe('loginHandler', () => {
  let provider: NextcloudOAuthProvider;
  const savedEnv = process.env;

  beforeEach(() => {
    process.env = {
      ...savedEnv,
      NEXTCLOUD_URL: 'https://cloud.example.com',
      MCP_AUTH_SECRET: 'test-secret-at-least-32-chars-long!!',
    };
    provider = new NextcloudOAuthProvider();
    vi.clearAllMocks();
  });

  afterEach(() => {
    process.env = savedEnv;
  });

  it('redirects with ?code= on successful Nextcloud auth', async () => {
    (global.fetch as any).mockResolvedValueOnce({ ok: true });
    const res = makeRes();
    await loginHandler(provider)({ body: BASE_BODY } as any, res as any);
    expect(res.redirect).toHaveBeenCalledWith(expect.stringContaining('code='));
  });

  it('includes state in the redirect URL', async () => {
    (global.fetch as any).mockResolvedValueOnce({ ok: true });
    const res = makeRes();
    await loginHandler(provider)({ body: BASE_BODY } as any, res as any);
    const url = (res.redirect as any).mock.calls[0][0] as string;
    expect(new URL(url).searchParams.get('state')).toBe('xyz');
  });

  it('omits state when state is empty', async () => {
    (global.fetch as any).mockResolvedValueOnce({ ok: true });
    const res = makeRes();
    await loginHandler(provider)({ body: { ...BASE_BODY, state: '' } } as any, res as any);
    const url = (res.redirect as any).mock.calls[0][0] as string;
    expect(new URL(url).searchParams.has('state')).toBe(false);
  });

  it('redirect target matches the requested redirect_uri', async () => {
    (global.fetch as any).mockResolvedValueOnce({ ok: true });
    const res = makeRes();
    await loginHandler(provider)({ body: BASE_BODY } as any, res as any);
    const url = (res.redirect as any).mock.calls[0][0] as string;
    expect(url).toContain('https://example.com/callback');
  });

  it('re-renders login form with error on 401 from Nextcloud', async () => {
    (global.fetch as any).mockResolvedValueOnce({ ok: false, status: 401 });
    const res = makeRes();
    await loginHandler(provider)({ body: BASE_BODY } as any, res as any);
    expect(res.redirect).not.toHaveBeenCalled();
    expect(res.send).toHaveBeenCalledWith(expect.stringContaining('Invalid Nextcloud credentials'));
  });

  it('re-renders login form on network failure', async () => {
    (global.fetch as any).mockRejectedValueOnce(new Error('Network error'));
    const res = makeRes();
    await loginHandler(provider)({ body: BASE_BODY } as any, res as any);
    expect(res.redirect).not.toHaveBeenCalled();
    expect(res.send).toHaveBeenCalledWith(expect.stringContaining('Authentication failed'));
  });

  it('returns 500 when NEXTCLOUD_URL is not configured', async () => {
    delete process.env.NEXTCLOUD_URL;
    const res = makeRes();
    await loginHandler(provider)({ body: BASE_BODY } as any, res as any);
    expect(res.status).toHaveBeenCalledWith(500);
    expect(res.redirect).not.toHaveBeenCalled();
  });

  it('returns 400 when required form fields are missing', async () => {
    const res = makeRes();
    await loginHandler(provider)({ body: { username: 'u', password: 'p' } } as any, res as any);
    expect(res.status).toHaveBeenCalledWith(400);
    expect(res.redirect).not.toHaveBeenCalled();
  });

  it('calls Nextcloud with Basic auth header', async () => {
    (global.fetch as any).mockResolvedValueOnce({ ok: true });
    const res = makeRes();
    await loginHandler(provider)({ body: BASE_BODY } as any, res as any);
    const [url, opts] = (global.fetch as any).mock.calls[0];
    expect(url).toContain('/ocs/v2.php/cloud/user');
    const expectedAuth = 'Basic ' + Buffer.from('alice:secret').toString('base64');
    expect(opts.headers.Authorization).toBe(expectedAuth);
  });

  it('calls Nextcloud with OCS-APIRequest header', async () => {
    (global.fetch as any).mockResolvedValueOnce({ ok: true });
    const res = makeRes();
    await loginHandler(provider)({ body: BASE_BODY } as any, res as any);
    const [, opts] = (global.fetch as any).mock.calls[0];
    expect(opts.headers['OCS-APIRequest']).toBe('true');
  });

  it('strips trailing slash from NEXTCLOUD_URL to avoid double-slash in path', async () => {
    process.env.NEXTCLOUD_URL = 'https://cloud.example.com/';
    (global.fetch as any).mockResolvedValueOnce({ ok: true });
    const res = makeRes();
    await loginHandler(provider)({ body: BASE_BODY } as any, res as any);
    const [url] = (global.fetch as any).mock.calls[0];
    expect(url).not.toContain('//ocs');
    expect(url).toContain('/ocs/v2.php/cloud/user');
  });

  it('issued code is verifiable via the provider', async () => {
    (global.fetch as any).mockResolvedValueOnce({ ok: true });
    const res = makeRes();
    await loginHandler(provider)({ body: BASE_BODY } as any, res as any);
    const url = (res.redirect as any).mock.calls[0][0] as string;
    const code = new URL(url).searchParams.get('code')!;
    // challengeForAuthorizationCode should return the stored PKCE challenge
    const fakeClient = { client_id: 'c1', redirect_uris: [] as any[] } as any;
    const challenge = await provider.challengeForAuthorizationCode(fakeClient, code);
    expect(challenge).toBe('challenge-abc');
  });

  it('handles scope string correctly — splits into array and stores', async () => {
    (global.fetch as any).mockResolvedValueOnce({ ok: true });
    const res = makeRes();
    await loginHandler(provider)(
      { body: { ...BASE_BODY, scope: 'read write' } } as any,
      res as any
    );
    const url = (res.redirect as any).mock.calls[0][0] as string;
    const code = new URL(url).searchParams.get('code')!;
    const fakeClient = { client_id: 'c1', redirect_uris: [] as any[] } as any;
    // If the code is valid we can exchange it
    const tokens = await provider.exchangeAuthorizationCode(fakeClient, code);
    expect(tokens.scope).toBe('read write');
  });

  it('handles empty scope gracefully', async () => {
    (global.fetch as any).mockResolvedValueOnce({ ok: true });
    const res = makeRes();
    await loginHandler(provider)({ body: { ...BASE_BODY, scope: '' } } as any, res as any);
    expect(res.redirect).toHaveBeenCalled();
  });
});
