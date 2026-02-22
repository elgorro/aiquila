import { createHmac, timingSafeEqual } from 'node:crypto';
import type {
  OAuthServerProvider,
  AuthorizationParams,
} from '@modelcontextprotocol/sdk/server/auth/provider.js';
import type { OAuthRegisteredClientsStore } from '@modelcontextprotocol/sdk/server/auth/clients.js';
import type { AuthInfo } from '@modelcontextprotocol/sdk/server/auth/types.js';
import type {
  OAuthClientInformationFull,
  OAuthTokens,
  OAuthTokenRevocationRequest,
} from '@modelcontextprotocol/sdk/shared/auth.js';
import { ClientsStore, CodeStore, RefreshStore } from './store.js';
import { logger } from '../logger.js';

// --- JWT helpers (HMAC-SHA256 via node:crypto — no extra deps) ---

function base64url(buf: Buffer): string {
  return buf.toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=/g, '');
}

function base64urlStr(s: string): string {
  return base64url(Buffer.from(s, 'utf8'));
}

function signJwt(payload: Record<string, unknown>, secret: string, expiresInSecs: number): string {
  const now = Math.floor(Date.now() / 1000);
  const claims = { ...payload, iat: now, exp: now + expiresInSecs };
  const header = base64urlStr(JSON.stringify({ alg: 'HS256', typ: 'JWT' }));
  const body = base64urlStr(JSON.stringify(claims));
  const signing = `${header}.${body}`;
  const sig = base64url(createHmac('sha256', secret).update(signing).digest());
  return `${signing}.${sig}`;
}

function verifyJwt(token: string, secret: string): Record<string, unknown> | null {
  const parts = token.split('.');
  if (parts.length !== 3) return null;
  const [header, body, sig] = parts;
  const expected = base64url(createHmac('sha256', secret).update(`${header}.${body}`).digest());
  const sigBuf = Buffer.from(sig);
  const expBuf = Buffer.from(expected);
  // HMAC-SHA256 base64url is always 43 chars — same length guaranteed
  if (sigBuf.length !== expBuf.length) return null;
  if (!timingSafeEqual(sigBuf, expBuf)) return null;
  try {
    const claims = JSON.parse(Buffer.from(body, 'base64url').toString('utf8'));
    if (typeof claims.exp === 'number' && Date.now() / 1000 > claims.exp) return null;
    return claims as Record<string, unknown>;
  } catch {
    return null;
  }
}

// --- HTML helper (shared with login handler) ---

function escapeHtml(s: string): string {
  return s
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

export function renderLoginForm(opts: {
  clientName?: string;
  clientId: string;
  redirectUri: string;
  state?: string;
  codeChallenge: string;
  scope?: string;
  error?: string;
}): string {
  return `<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>AIquila – Sign in with Nextcloud</title>
  <style>
    body { font-family: sans-serif; background: #f4f6f8; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; }
    .card { background: #fff; border-radius: 8px; box-shadow: 0 2px 12px rgba(0,0,0,.12); padding: 2rem; width: 100%; max-width: 360px; }
    h1 { font-size: 1.3rem; margin: 0 0 .25rem; }
    p.sub { color: #555; font-size: .9rem; margin: 0 0 1.5rem; }
    label { display: block; font-size: .85rem; font-weight: 600; margin-bottom: .25rem; }
    input[type=text], input[type=password] { width: 100%; box-sizing: border-box; padding: .55rem .75rem; border: 1px solid #ccc; border-radius: 4px; font-size: 1rem; margin-bottom: 1rem; }
    button { width: 100%; padding: .65rem; background: #0082c9; color: #fff; border: none; border-radius: 4px; font-size: 1rem; cursor: pointer; }
    button:hover { background: #006fa3; }
    .error { background: #fdecea; color: #c0392b; border-radius: 4px; padding: .6rem .9rem; margin-bottom: 1rem; font-size: .9rem; }
  </style>
</head>
<body>
  <div class="card">
    <h1>Sign in with Nextcloud</h1>
    <p class="sub">${opts.clientName ? escapeHtml(opts.clientName) + ' is requesting access.' : 'An application is requesting access.'}</p>
    ${opts.error ? `<div class="error">${escapeHtml(opts.error)}</div>` : ''}
    <form method="POST" action="/auth/login">
      <input type="hidden" name="client_id" value="${escapeHtml(opts.clientId)}">
      <input type="hidden" name="redirect_uri" value="${escapeHtml(opts.redirectUri)}">
      <input type="hidden" name="state" value="${escapeHtml(opts.state ?? '')}">
      <input type="hidden" name="code_challenge" value="${escapeHtml(opts.codeChallenge)}">
      <input type="hidden" name="scope" value="${escapeHtml(opts.scope ?? '')}">
      <label for="username">Nextcloud Username</label>
      <input type="text" id="username" name="username" autocomplete="username" required>
      <label for="password">Password / App Password</label>
      <input type="password" id="password" name="password" autocomplete="current-password" required>
      <button type="submit">Authorize</button>
    </form>
  </div>
</body>
</html>`;
}

// --- OAuth Provider ---

export class NextcloudOAuthProvider implements OAuthServerProvider {
  private readonly _clientsStore = ClientsStore.fromEnv();
  private readonly codeStore = new CodeStore();
  private readonly refreshStore = new RefreshStore();

  get clientsStore(): OAuthRegisteredClientsStore {
    return this._clientsStore;
  }

  async authorize(
    client: OAuthClientInformationFull,
    params: AuthorizationParams,
    res: any
  ): Promise<void> {
    res
      .status(200)
      .type('html')
      .send(
        renderLoginForm({
          clientName: client.client_name,
          clientId: client.client_id,
          redirectUri: params.redirectUri,
          state: params.state,
          codeChallenge: params.codeChallenge,
          scope: params.scopes?.join(' '),
        })
      );
  }

  async challengeForAuthorizationCode(
    client: OAuthClientInformationFull,
    authorizationCode: string
  ): Promise<string> {
    const entry = this.codeStore.get(authorizationCode);
    if (!entry || entry.clientId !== client.client_id) {
      throw new Error('Invalid authorization code');
    }
    return entry.pkceChallenge;
  }

  async exchangeAuthorizationCode(
    client: OAuthClientInformationFull,
    authorizationCode: string,
    _codeVerifier?: string,
    redirectUri?: string,
    _resource?: URL
  ): Promise<OAuthTokens> {
    const entry = this.codeStore.get(authorizationCode);
    if (!entry || entry.clientId !== client.client_id) {
      logger.warn(
        { client: client.client_id },
        '[token] Auth code exchange failed: invalid or expired code'
      );
      throw new Error('Invalid authorization code');
    }
    if (redirectUri && entry.redirectUri !== redirectUri) {
      logger.warn(
        { client: client.client_id },
        '[token] Auth code exchange failed: redirect_uri mismatch'
      );
      throw new Error('Redirect URI mismatch');
    }
    this.codeStore.delete(authorizationCode);

    const accessToken = signJwt(
      { sub: entry.userId, client_id: entry.clientId, scopes: entry.scopes },
      this.getSecret(),
      3600
    );
    const refreshToken = this.refreshStore.store({
      userId: entry.userId,
      clientId: entry.clientId,
      scopes: entry.scopes,
    });

    logger.info(
      { user: entry.userId, client: client.client_id, scopes: entry.scopes },
      '[token] Access token issued'
    );
    return {
      access_token: accessToken,
      token_type: 'bearer',
      expires_in: 3600,
      refresh_token: refreshToken,
      scope: entry.scopes.join(' '),
    };
  }

  async exchangeRefreshToken(
    client: OAuthClientInformationFull,
    refreshToken: string,
    scopes?: string[],
    _resource?: URL
  ): Promise<OAuthTokens> {
    const entry = this.refreshStore.get(refreshToken);
    if (!entry || entry.clientId !== client.client_id) {
      logger.warn(
        { client: client.client_id },
        '[token] Refresh token exchange failed: invalid or expired token'
      );
      throw new Error('Invalid refresh token');
    }
    const effectiveScopes = scopes ?? entry.scopes;
    this.refreshStore.delete(refreshToken);

    const accessToken = signJwt(
      { sub: entry.userId, client_id: entry.clientId, scopes: effectiveScopes },
      this.getSecret(),
      3600
    );
    const newRefresh = this.refreshStore.store({
      userId: entry.userId,
      clientId: entry.clientId,
      scopes: effectiveScopes,
    });

    logger.info({ user: entry.userId, client: client.client_id }, '[token] Token refreshed');
    return {
      access_token: accessToken,
      token_type: 'bearer',
      expires_in: 3600,
      refresh_token: newRefresh,
      scope: effectiveScopes.join(' '),
    };
  }

  async verifyAccessToken(token: string): Promise<AuthInfo> {
    const claims = verifyJwt(token, this.getSecret());
    if (!claims) {
      logger.warn('[auth] Access token verification failed: invalid or expired token');
      throw new Error('Invalid or expired access token');
    }
    return {
      token,
      clientId: claims.client_id as string,
      scopes: claims.scopes as string[],
      expiresAt: claims.exp as number,
    };
  }

  async revokeToken(
    _client: OAuthClientInformationFull,
    request: OAuthTokenRevocationRequest
  ): Promise<void> {
    this.refreshStore.delete(request.token);
  }

  /** Called by the login handler to issue an auth code after successful Nextcloud auth. */
  issueAuthCode(params: {
    pkceChallenge: string;
    clientId: string;
    scopes: string[];
    redirectUri: string;
    userId: string;
    state?: string;
  }): string {
    return this.codeStore.store(params);
  }

  private getSecret(): string {
    const secret = process.env.MCP_AUTH_SECRET;
    if (!secret) throw new Error('MCP_AUTH_SECRET is not set');
    return secret;
  }
}
