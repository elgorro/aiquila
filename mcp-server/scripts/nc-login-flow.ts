/**
 * nc-login-flow.ts
 *
 * Plays the browser through the sign-in the MCP server's /authorize starts,
 * for the test scripts in this directory. The server shows a page linking to
 * a Nextcloud Login Flow v2; the user signs in there and grants access; the
 * page, reloaded, redirects to the client with the authorization code.
 *
 * Nextcloud renders its login pages from base64 JSON "initial state" and takes
 * a CSRF token from /csrftoken, so this follows what its scripts do rather
 * than parsing forms. docker/standalone/scripts/nc-login-flow.sh is the same
 * for the shell scripts.
 */
import * as crypto from 'node:crypto';

/** A minimal cookie jar: name → value, per origin. Enough for these flows. */
class CookieJar {
  private readonly cookies = new Map<string, Map<string, string>>();

  store(url: string, res: Response): void {
    const origin = new URL(url).origin;
    const jar = this.cookies.get(origin) ?? new Map<string, string>();
    for (const c of res.headers.getSetCookie()) {
      const [pair] = c.split(';');
      const eq = pair.indexOf('=');
      if (eq > 0) jar.set(pair.slice(0, eq).trim(), pair.slice(eq + 1).trim());
    }
    this.cookies.set(origin, jar);
  }

  header(url: string): string {
    const jar = this.cookies.get(new URL(url).origin);
    return jar ? [...jar].map(([k, v]) => `${k}=${v}`).join('; ') : '';
  }
}

/** fetch with the jar, following redirects itself so cookies are kept. */
async function request(
  jar: CookieJar,
  url: string,
  init: RequestInit & { follow?: boolean } = {}
): Promise<{ res: Response; url: string }> {
  let current = url;
  for (let hop = 0; hop < 10; hop++) {
    const headers = new Headers(init.headers);
    const cookie = jar.header(current);
    if (cookie) headers.set('Cookie', cookie);
    headers.set('User-Agent', 'Mozilla/5.0 (aiquila-test)');
    headers.set('Origin', new URL(current).origin);
    const res = await fetch(current, { ...init, headers, redirect: 'manual' });
    jar.store(current, res);
    const location = res.headers.get('location');
    if (init.follow === false || res.status < 300 || res.status >= 400 || !location) {
      return { res, url: current };
    }
    current = new URL(location, current).href;
    init = { follow: true };
  }
  throw new Error(`too many redirects from ${url}`);
}

function initialState(html: string, key: string): any {
  const m = html.match(new RegExp(`id="initial-state-core-${key}" value="([^"]*)"`));
  return m ? JSON.parse(Buffer.from(m[1], 'base64').toString()) : undefined;
}

function form(fields: Record<string, string>): RequestInit {
  return {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams(fields).toString(),
  };
}

/** The current TOTP code for a base32 secret (RFC 6238, SHA-1, 30 s, 6 digits). */
function totp(secret: string): string {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  let bits = '';
  for (const ch of secret.toUpperCase().replace(/=+$/, '')) {
    bits += alphabet.indexOf(ch).toString(2).padStart(5, '0');
  }
  const key = Buffer.from(bits.match(/.{8}/g)!.map((b) => parseInt(b, 2)));
  const counter = Buffer.alloc(8);
  counter.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 30000)));
  const h = crypto.createHmac('sha1', key).update(counter).digest();
  const o = h[h.length - 1] & 15;
  return String((h.readUInt32BE(o) & 0x7fffffff) % 1000000).padStart(6, '0');
}

/**
 * Signs in on Nextcloud and grants the flow behind `loginUrl` access.
 * `password` may be an app password (Nextcloud's app-password route, no 2FA
 * needed) or the account password (the web login, with a TOTP code when the
 * account has 2FA).
 */
export async function grantLoginFlow(
  loginUrl: string,
  user: string,
  password: string,
  totpSecret?: string
): Promise<void> {
  const jar = new CookieJar();
  const origin = new URL(loginUrl).origin;
  const csrf = async () =>
    ((await (await request(jar, `${origin}/index.php/csrftoken`)).res.json()) as { token: string })
      .token;

  let flow = await (await request(jar, loginUrl)).res.text();
  let auth = initialState(flow, 'loginFlowAuth');
  if (!auth) throw new Error(`${loginUrl} is not a Nextcloud Login Flow v2 page`);

  // An app password goes through the flow's own app-password route.
  const viaApp = await request(
    jar,
    auth.appTokenUrl,
    form({ user, password, stateToken: auth.stateToken, requesttoken: await csrf() })
  );
  if (initialState(await viaApp.res.text(), 'loginFlowState') === 'done') return;

  // Otherwise the account password. The refused attempt ended the flow's
  // session state, so open the flow again first.
  flow = await (await request(jar, loginUrl)).res.text();
  auth = initialState(flow, 'loginFlowAuth');
  const grantPath = String(auth.loginRedirectUrl).slice(origin.length);
  const login = await request(jar, `${origin}/index.php/login`, {
    ...form({
      user,
      password,
      requesttoken: await csrf(),
      redirect_url: grantPath,
      timezone: 'UTC',
      timezone_offset: '0',
    }),
    follow: false,
  });
  const next = login.res.headers.get('location') ?? '';
  if (next.includes('/login/challenge') || next.includes('/login/selectchallenge')) {
    if (!totpSecret) {
      throw new Error(`${user} has two-factor authentication; set a TOTP secret or use an app password`);
    }
    await request(jar, new URL(next, origin).href);
    await request(
      jar,
      `${origin}/index.php/login/challenge/totp`,
      form({ challenge: totp(totpSecret), requesttoken: await csrf(), redirect_url: grantPath })
    );
  }
  const grant = initialState(
    await (await request(jar, auth.loginRedirectUrl)).res.text(),
    'loginFlowGrant'
  );
  if (!grant) throw new Error(`Nextcloud sign-in failed for ${user}`);
  const done = await request(
    jar,
    grant.actionUrl,
    form({ stateToken: grant.stateToken, requesttoken: await csrf() })
  );
  if (initialState(await done.res.text(), 'loginFlowState') !== 'done') {
    throw new Error('Nextcloud did not grant access');
  }
}

/**
 * GET /authorize, the Nextcloud sign-in, then the sign-in page's reload.
 * Returns the authorization code.
 */
export async function authorizeWithLoginFlow(opts: {
  baseUrl: string;
  params: Record<string, string>;
  user: string;
  password: string;
  totpSecret?: string;
  log?: (msg: string) => void;
}): Promise<string> {
  const authorizeUrl = new URL(`${opts.baseUrl}/authorize`);
  authorizeUrl.search = new URLSearchParams(opts.params).toString();
  const page = await fetch(authorizeUrl, { redirect: 'manual' });
  // The sign-in completes only in the browser that started it: keep the cookie.
  const cookie = page.headers
    .getSetCookie()
    .map((c) => c.split(';')[0])
    .join('; ');
  const html = await page.text();
  const login = html.match(/<a class="button" href="([^"]+)"/)?.[1]?.replace(/&amp;/g, '&');
  const flowId = html.match(/url=\/auth\/flow\/([A-Za-z0-9_-]+)"/)?.[1];
  if (!login || !flowId) {
    throw new Error(`/authorize did not render the sign-in page:\n${html.slice(0, 200)}`);
  }
  opts.log?.(`Signing in on ${new URL(login).origin} as ${opts.user}`);
  await grantLoginFlow(login, opts.user, opts.password, opts.totpSecret);

  // The page reloads itself until the server has collected the grant.
  for (let i = 0; i < 5; i++) {
    await new Promise((r) => setTimeout(r, 1200));
    const res = await fetch(`${opts.baseUrl}/auth/flow/${flowId}`, {
      headers: { cookie },
      redirect: 'manual',
    });
    const location = res.headers.get('location');
    if (location) {
      const code = new URL(location).searchParams.get('code');
      if (!code) throw new Error(`Sign-in failed: ${location}`);
      return code;
    }
  }
  throw new Error('The sign-in page never redirected');
}
