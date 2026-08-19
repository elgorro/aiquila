// SPDX-License-Identifier: MIT

import { createHash, timingSafeEqual } from 'node:crypto';
import { SignJWT, jwtVerify, type JWTPayload } from 'jose';
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
import {
  InvalidGrantError,
  InvalidTokenError,
} from '@modelcontextprotocol/sdk/server/auth/errors.js';
import { ClientsStore, CodeStore, RefreshStore } from './store.js';
import { LOGIN_STYLESHEET_PATH } from './login-page-css.js';
import { logger } from '../logger.js';

// --- JWT helpers (HMAC-SHA256 via jose) ---

async function signJwt(
  payload: Record<string, unknown>,
  secret: string,
  expiresInSecs: number
): Promise<string> {
  const key = new TextEncoder().encode(secret);
  const now = Math.floor(Date.now() / 1000);
  return new SignJWT(payload)
    .setProtectedHeader({ alg: 'HS256' })
    .setIssuedAt(now)
    .setExpirationTime(now + expiresInSecs)
    .sign(key);
}

async function verifyJwt(
  token: string,
  secret: string,
  opts?: { issuer?: string; audience?: string }
): Promise<JWTPayload | null> {
  try {
    const key = new TextEncoder().encode(secret);
    const { payload } = await jwtVerify(token, key, {
      algorithms: ['HS256'],
      ...(opts?.issuer && { issuer: opts.issuer }),
      ...(opts?.audience && { audience: opts.audience }),
    });
    return payload;
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

/**
 * Security headers for every HTML response served by the OAuth login flow.
 *
 * The page has no inline <script> and no inline styles (the stylesheet is served
 * separately from LOGIN_STYLESHEET_PATH), so the policy can deny everything by
 * default and allow only same-origin styles.
 */
export const LOGIN_PAGE_CSP =
  "frame-ancestors 'none'; default-src 'none'; style-src 'self'; form-action 'self'; base-uri 'none'";

/** Applies the login-flow security headers to a response. Returns the response for chaining. */
export function applySecurityHeaders<T extends { set(name: string, value: string): T }>(res: T): T {
  return res
    .set('X-Frame-Options', 'DENY')
    .set('X-Content-Type-Options', 'nosniff')
    .set('Referrer-Policy', 'no-referrer')
    .set('Content-Security-Policy', LOGIN_PAGE_CSP);
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
  <link rel="stylesheet" href="${LOGIN_STYLESHEET_PATH}">
</head>
<body>
  <div class="card">
    <h1>Sign in with Nextcloud</h1>
    <div class="consent-banner">
      <strong>${opts.clientName ? escapeHtml(opts.clientName) : 'An application'}</strong> is requesting access to your Nextcloud account.
      ${opts.scope ? `<span class="detail">Scopes: <code>${escapeHtml(opts.scope)}</code></span>` : ''}
      <span class="detail">Redirecting to: <code>${escapeHtml(opts.redirectUri)}</code></span>
    </div>
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

// Access tokens are valid for 1 hour; refresh tokens handle longer sessions.
const ACCESS_TOKEN_TTL_SECS = 60 * 60;

// --- OAuth Provider ---

export class NextcloudOAuthProvider implements OAuthServerProvider {
  private readonly _clientsStore = ClientsStore.fromEnv();
  private readonly codeStore = new CodeStore();
  private readonly refreshStore = RefreshStore.fromEnv();

  get clientsStore(): OAuthRegisteredClientsStore {
    return this._clientsStore;
  }

  async authorize(
    client: OAuthClientInformationFull,
    params: AuthorizationParams,
    res: any
  ): Promise<void> {
    applySecurityHeaders(res)
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
      throw new InvalidGrantError('Invalid authorization code');
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
      throw new InvalidGrantError('Invalid authorization code');
    }
    if (redirectUri && entry.redirectUri !== redirectUri) {
      logger.warn(
        { client: client.client_id },
        '[token] Auth code exchange failed: redirect_uri mismatch'
      );
      throw new InvalidGrantError('Redirect URI mismatch');
    }
    this.codeStore.delete(authorizationCode);

    const accessToken = await signJwt(
      {
        sub: entry.userId,
        client_id: entry.clientId,
        scopes: entry.scopes,
        iss: this.getIssuer(),
        aud: this.getResourceUrl(),
      },
      this.getSecret(),
      ACCESS_TOKEN_TTL_SECS
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
      expires_in: ACCESS_TOKEN_TTL_SECS,
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
      throw new InvalidGrantError('Invalid refresh token');
    }
    const effectiveScopes = scopes ?? entry.scopes;
    this.refreshStore.delete(refreshToken);

    const accessToken = await signJwt(
      {
        sub: entry.userId,
        client_id: entry.clientId,
        scopes: effectiveScopes,
        iss: this.getIssuer(),
        aud: this.getResourceUrl(),
      },
      this.getSecret(),
      ACCESS_TOKEN_TTL_SECS
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
      expires_in: ACCESS_TOKEN_TTL_SECS,
      refresh_token: newRefresh,
      scope: effectiveScopes.join(' '),
    };
  }

  async verifyAccessToken(token: string): Promise<AuthInfo> {
    // Internal token bypass for service-to-service auth (e.g. NC → MCP on Docker network)
    const internalToken = process.env.MCP_INTERNAL_TOKEN;
    if (internalToken) {
      const hash = (s: string) => createHash('sha256').update(s).digest();
      if (timingSafeEqual(hash(token), hash(internalToken))) {
        return {
          token,
          clientId: 'internal',
          scopes: [],
          expiresAt: Math.floor(Date.now() / 1000) + 86400 * 365,
        };
      }
    }

    const issuer = process.env.MCP_AUTH_ISSUER;
    const claims = await verifyJwt(
      token,
      this.getSecret(),
      issuer ? { issuer, audience: new URL('/mcp', issuer).toString() } : undefined
    );
    if (!claims) {
      logger.warn('[auth] Access token verification failed: invalid or expired token');
      // InvalidTokenError (not a bare Error) so the SDK answers 401 with a
      // WWW-Authenticate challenge. A bare Error becomes a 500, and clients
      // treat that as a server fault rather than a cue to re-authenticate.
      throw new InvalidTokenError('Invalid or expired access token');
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

  private getIssuer(): string {
    const issuer = process.env.MCP_AUTH_ISSUER;
    if (!issuer) throw new Error('MCP_AUTH_ISSUER is not set');
    return issuer;
  }

  private getResourceUrl(): string {
    return new URL('/mcp', this.getIssuer()).toString();
  }
}
