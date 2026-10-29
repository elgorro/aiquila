// SPDX-License-Identifier: MIT

import { createHash, createHmac, randomUUID, timingSafeEqual } from 'node:crypto';
import { SignJWT, jwtVerify, type JWTPayload } from 'jose';
import {
  OAuthError,
  OAuthErrorCode,
  type AuthInfo,
  type OAuthClientInformationFull,
  type OAuthTokens,
  type OAuthTokenRevocationRequest,
} from '@modelcontextprotocol/server';
import { ClientsStore, CodeStore, RefreshStore, RevocationList } from './store.js';
import { checkNextcloudUser, type NextcloudUserCheck } from './nc-user.js';
import { looksLikeClientIdUrl } from './cimd.js';
import { isInternalToken } from './internal-token.js';
import { LOGIN_STYLESHEET_PATH } from './login-page-css.js';
import { logger } from '../logger.js';

// --- JWT helpers (HMAC-SHA256 via jose) ---

// RFC 9068 §2.1: JWT access tokens are explicitly typed, so no other JWT
// signed with MCP_AUTH_SECRET can pass for one (RFC 8725 §3.11).
const ACCESS_TOKEN_TYP = 'at+jwt';

async function signJwt(
  payload: Record<string, unknown>,
  secret: string,
  expiresInSecs: number
): Promise<string> {
  const key = new TextEncoder().encode(secret);
  const now = Math.floor(Date.now() / 1000);
  return new SignJWT(payload)
    .setProtectedHeader({ alg: 'HS256', typ: ACCESS_TOKEN_TYP })
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
      // RFC 9068 §4: the resource server MUST check the typ header.
      typ: ACCESS_TOKEN_TYP,
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

/**
 * The authorization request as the login form carries it from /authorize to
 * /auth/login. It passes through the user agent, so it is MACed: otherwise a
 * login could be posted with a code_challenge, scope or redirect_uri that
 * /authorize never checked.
 */
export interface LoginFormRequest {
  clientId: string;
  redirectUri: string;
  codeChallenge: string;
  state?: string;
  scope?: string;
  // Seconds since the epoch, when /authorize rendered the form
  issuedAt: number;
}

// How long a rendered login form stays valid.
const LOGIN_FORM_TTL_SECS = 30 * 60;

export function loginRequestMac(req: LoginFormRequest): string {
  const secret = process.env.MCP_AUTH_SECRET;
  if (!secret) throw new Error('MCP_AUTH_SECRET is not set');
  return createHmac('sha256', secret)
    .update(
      JSON.stringify([
        'aiquila-login-form-v1',
        req.clientId,
        req.redirectUri,
        req.codeChallenge,
        req.state ?? '',
        req.scope ?? '',
        req.issuedAt,
      ])
    )
    .digest('base64url');
}

/** True when `mac` belongs to `req` and the form has not expired. */
export function verifyLoginRequestMac(req: LoginFormRequest, mac: string | undefined): boolean {
  if (!mac || !Number.isSafeInteger(req.issuedAt)) return false;
  const age = Math.floor(Date.now() / 1000) - req.issuedAt;
  if (age < 0 || age > LOGIN_FORM_TTL_SECS) return false;
  const hash = (v: string) => createHash('sha256').update(v).digest();
  return timingSafeEqual(hash(mac), hash(loginRequestMac(req)));
}

export function renderLoginForm(opts: {
  clientName?: string;
  clientId: string;
  redirectUri: string;
  state?: string;
  codeChallenge: string;
  scope?: string;
  issuedAt?: number;
  requestMac?: string;
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
      <input type="hidden" name="request_iat" value="${opts.issuedAt ?? ''}">
      <input type="hidden" name="request_mac" value="${escapeHtml(opts.requestMac ?? '')}">
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

/**
 * The name the consent page shows for a client. A Client ID Metadata Document
 * is self-asserted, so its client_name is whatever the document's author chose
 * — possibly another product's name. The host of the client_id URL is the one
 * thing about such a client that is verified (it served the document over TLS).
 */
export function clientDisplayName(client: OAuthClientInformationFull): string | undefined {
  if (looksLikeClientIdUrl(client.client_id)) {
    try {
      return new URL(client.client_id).host;
    } catch {
      return undefined;
    }
  }
  return client.client_name;
}

/** A validated authorization request, as the /authorize endpoint hands it over. */
export interface AuthorizationParams {
  redirectUri: string;
  codeChallenge: string;
  state?: string;
  scopes: string[];
}

// --- OAuth Provider ---

export interface ProviderOptions {
  // Asks Nextcloud whether a grant's user may still use it; checked on refresh.
  checkUser?: NextcloudUserCheck;
}

export class NextcloudOAuthProvider {
  private readonly _clientsStore: ClientsStore;
  private readonly codeStore = new CodeStore();
  private readonly refreshStore = RefreshStore.fromEnv();
  private readonly revocations = RevocationList.fromEnv();
  private readonly checkUser: NextcloudUserCheck;

  constructor(clientsStore: ClientsStore = ClientsStore.fromEnv(), options: ProviderOptions = {}) {
    this._clientsStore = clientsStore;
    this.checkUser = options.checkUser ?? checkNextcloudUser;
  }

  get clientsStore(): ClientsStore {
    return this._clientsStore;
  }

  async authorize(
    client: OAuthClientInformationFull,
    params: AuthorizationParams,
    res: any
  ): Promise<void> {
    const request: LoginFormRequest = {
      clientId: client.client_id,
      redirectUri: params.redirectUri,
      state: params.state,
      codeChallenge: params.codeChallenge,
      scope: params.scopes?.join(' '),
      issuedAt: Math.floor(Date.now() / 1000),
    };
    applySecurityHeaders(res)
      .status(200)
      .type('html')
      .send(
        renderLoginForm({
          ...request,
          clientName: clientDisplayName(client),
          requestMac: loginRequestMac(request),
        })
      );
  }

  async challengeForAuthorizationCode(
    client: OAuthClientInformationFull,
    authorizationCode: string
  ): Promise<string> {
    const entry = this.codeStore.get(authorizationCode);
    if (!entry || entry.clientId !== client.client_id) {
      throw new OAuthError(OAuthErrorCode.InvalidGrant, 'Invalid authorization code');
    }
    return entry.pkceChallenge;
  }

  async exchangeAuthorizationCode(
    client: OAuthClientInformationFull,
    authorizationCode: string,
    redirectUri?: string
  ): Promise<OAuthTokens> {
    const entry = this.codeStore.get(authorizationCode);
    if (!entry || entry.clientId !== client.client_id) {
      logger.warn(
        { client: client.client_id },
        '[token] Auth code exchange failed: invalid or expired code'
      );
      throw new OAuthError(OAuthErrorCode.InvalidGrant, 'Invalid authorization code');
    }
    // OAuth 2.1 (draft-ietf-oauth-v2-1-16 §10.2): PKCE, required on every
    // code here, prevents code injection, so the token request no longer
    // needs redirect_uri. An OAuth 2.0 client may still send it, and then it
    // must match the authorization request (RFC 6749 §4.1.3).
    if (redirectUri !== undefined && entry.redirectUri !== redirectUri) {
      logger.warn(
        { client: client.client_id },
        '[token] Auth code exchange failed: redirect_uri mismatch'
      );
      throw new OAuthError(OAuthErrorCode.InvalidGrant, 'Redirect URI mismatch');
    }
    // OAuth 2.1 (draft-ietf-oauth-v2-1-16 §4.1.3, §7.5.3; RFC 6749 §4.1.2): a
    // code used twice MUST be denied and the tokens issued from it SHOULD be
    // revoked. The router has already checked client and code_verifier, so
    // this replay carries valid parameters and the grant ends.
    if (entry.redeemedGrantId) {
      this.revokeGrant(entry.redeemedGrantId);
      logger.warn(
        { user: entry.userId, client: client.client_id },
        '[token] Authorization code replayed — grant revoked'
      );
      throw new OAuthError(OAuthErrorCode.InvalidGrant, 'Invalid authorization code');
    }

    const grantId = randomUUID();
    this.codeStore.markRedeemed(authorizationCode, grantId);
    const accessToken = await this.signAccessToken(
      grantId,
      entry.userId,
      entry.clientId,
      entry.scopes
    );
    const refreshToken = this.refreshStore.store({
      grantId,
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
    scopes?: string[]
  ): Promise<OAuthTokens> {
    const entry = this.refreshStore.get(refreshToken);
    if (!entry || entry.clientId !== client.client_id) {
      logger.warn(
        { client: client.client_id },
        '[token] Refresh token exchange failed: invalid or expired token'
      );
      throw new OAuthError(OAuthErrorCode.InvalidGrant, 'Invalid refresh token');
    }
    // RFC 9700 §4.14.2: a rotated token presented again means two parties
    // hold the grant. Which one is the attacker cannot be told, so the whole
    // grant ends and the legitimate client signs in again.
    if (entry.spent) {
      this.revokeGrant(entry.grantId);
      logger.warn(
        { user: entry.userId, client: client.client_id },
        '[token] Refresh token reuse detected — grant revoked'
      );
      throw new OAuthError(OAuthErrorCode.InvalidGrant, 'Invalid refresh token');
    }
    // RFC 6749 §6: the requested scope MUST NOT include any scope not
    // originally granted; omitted means the original scope.
    const exceeding = scopes?.filter((s) => !entry.scopes.includes(s)) ?? [];
    if (exceeding.length > 0) {
      throw new OAuthError(
        OAuthErrorCode.InvalidScope,
        `Scope exceeds the original grant: ${exceeding.join(' ')}`
      );
    }
    const effectiveScopes = scopes ?? entry.scopes;
    // Spend the token before the first await, so two concurrent requests
    // cannot both redeem it.
    this.refreshStore.markSpent(refreshToken);

    // A user disabled or deleted in Nextcloud loses the grant at the next
    // refresh. RFC 6749 §5.2: a grant that is no longer valid is invalid_grant.
    const userState = await this.checkUser(entry.userId);
    if (userState === 'disabled' || userState === 'missing') {
      this.revokeGrant(entry.grantId);
      logger.warn(
        { user: entry.userId, client: client.client_id, userState },
        '[token] Refresh refused: Nextcloud user is no longer active — grant revoked'
      );
      throw new OAuthError(OAuthErrorCode.InvalidGrant, 'The user is no longer active');
    }

    const accessToken = await this.signAccessToken(
      entry.grantId,
      entry.userId,
      entry.clientId,
      effectiveScopes
    );
    const newRefresh = this.refreshStore.store({
      grantId: entry.grantId,
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
    // Internal token for service-to-service calls on the internal network.
    // Where it may be used from is enforced in the HTTP transport.
    if (isInternalToken(token)) {
      return {
        token,
        clientId: 'internal',
        scopes: [],
        expiresAt: Math.floor(Date.now() / 1000) + 86400 * 365,
      };
    }

    const claims = await this.verifyOwnJwt(token);
    if (!claims || this.isRevoked(claims)) {
      logger.warn('[auth] Access token verification failed: invalid or expired token');
      // OAuthError(InvalidToken) — not a bare Error — so requireBearerAuth
      // answers 401 with a WWW-Authenticate challenge (RFC 6750 §3.1).
      // Anything else becomes a 500, and clients treat that as a server fault
      // rather than a cue to re-authenticate.
      throw new OAuthError(OAuthErrorCode.InvalidToken, 'Invalid or expired access token');
    }
    return {
      token,
      clientId: claims.client_id as string,
      scopes: typeof claims.scope === 'string' ? claims.scope.split(' ').filter(Boolean) : [],
      expiresAt: claims.exp as number,
    };
  }

  /**
   * Revokes a refresh or access token (RFC 7009 §2.1). A refresh token ends
   * its whole grant, including the access tokens issued under it, as §2.1
   * says it SHOULD. An access token is revoked on its own. The server checks
   * the token was issued to the requesting client; an invalid or unknown
   * token is not an error (§2.2). token_type_hint is only a hint, so both
   * kinds are tried whatever it says.
   */
  async revokeToken(
    client: OAuthClientInformationFull,
    request: OAuthTokenRevocationRequest
  ): Promise<void> {
    const entry = this.refreshStore.get(request.token);
    if (entry) {
      if (entry.clientId === client.client_id) {
        this.revokeGrant(entry.grantId);
        logger.info({ user: entry.userId, client: client.client_id }, '[token] Grant revoked');
      }
      return;
    }
    const claims = await this.verifyOwnJwt(request.token);
    if (
      claims &&
      claims.client_id === client.client_id &&
      typeof claims.jti === 'string' &&
      typeof claims.exp === 'number'
    ) {
      this.revocations.revokeAccessToken(claims.jti, claims.exp * 1000);
      logger.info({ user: claims.sub, client: client.client_id }, '[token] Access token revoked');
    }
  }

  /** Ends a grant: its refresh tokens go, and its access tokens stop verifying. */
  private revokeGrant(grantId: string): void {
    this.refreshStore.deleteGrant(grantId);
    this.revocations.revokeGrant(grantId, Date.now() + ACCESS_TOKEN_TTL_SECS * 1000);
  }

  private isRevoked(claims: JWTPayload): boolean {
    // Tokens without a grant id predate revocation support; refusing them
    // makes the client refresh once.
    if (typeof claims.gid !== 'string' || typeof claims.jti !== 'string') return true;
    return (
      this.revocations.isGrantRevoked(claims.gid) ||
      this.revocations.isAccessTokenRevoked(claims.jti)
    );
  }

  private signAccessToken(
    grantId: string,
    userId: string,
    clientId: string,
    scopes: string[]
  ): Promise<string> {
    return signJwt(
      {
        sub: userId,
        client_id: clientId,
        // RFC 9068 §2.2.3: a space-separated string, as in RFC 6749 §3.3
        scope: scopes.join(' '),
        gid: grantId,
        jti: randomUUID(),
        iss: this.getIssuer(),
        aud: this.getResourceUrl(),
      },
      this.getSecret(),
      ACCESS_TOKEN_TTL_SECS
    );
  }

  private verifyOwnJwt(token: string): Promise<JWTPayload | null> {
    const issuer = process.env.MCP_AUTH_ISSUER;
    return verifyJwt(
      token,
      this.getSecret(),
      issuer ? { issuer, audience: new URL('/mcp', issuer).toString() } : undefined
    );
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

  /**
   * The issuer identifier exactly as the AS metadata publishes it (`issuer`),
   * for the `iss` authorization-response parameter (RFC 9207 §2).
   */
  get issuerIdentifier(): string {
    return new URL(this.getIssuer()).href;
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
