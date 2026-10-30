// SPDX-License-Identifier: MIT

import { randomBytes, randomUUID } from 'node:crypto';
import { SignJWT, jwtVerify, type JWTPayload } from 'jose';
import {
  OAuthError,
  OAuthErrorCode,
  type AuthInfo,
  type OAuthClientInformationFull,
  type OAuthTokens,
  type OAuthTokenRevocationRequest,
} from '@modelcontextprotocol/server';
import { ClientsStore, CodeStore, RefreshStore, RevocationList, refreshTtlMs } from './store.js';
import { CredentialStore } from './credential-store.js';
import {
  checkCredential,
  deleteAppPassword,
  pollLoginFlow,
  startLoginFlow,
  type GrantedFlow,
  type StartedFlow,
} from './login-flow.js';
import type { NextcloudUserCredential } from '../nc-context.js';
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

// --- HTML helper ---

function escapeHtml(s: string): string {
  return s
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

/**
 * Security headers for every HTML response served by the OAuth sign-in flow.
 *
 * The page has no script and no inline styles (the stylesheet is served
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

// How long the sign-in binding cookie lasts: as long as a Login Flow v2
// token (20 minutes), with room to spare.
const LOGIN_FORM_TTL_SECS = 30 * 60;

const BROWSER_BINDING = /^[A-Za-z0-9_-]{43}$/;

/**
 * The login-binding cookie's name. With an https issuer it carries the
 * __Host- prefix, so no other host (a sibling subdomain, say) can plant one
 * (RFC 6265bis §4.1.3.2). Browsers refuse that prefix over plain http, where
 * the bare name is used.
 */
export function loginCookieName(): string {
  const issuer = process.env.MCP_AUTH_ISSUER ?? '';
  return issuer.startsWith('https:') ? '__Host-aiquila-login' : 'aiquila-login';
}

/** The login-binding value from a request's Cookie header, if well-formed. */
export function readBrowserBinding(req: { headers?: Record<string, unknown> }): string | undefined {
  const header = req.headers?.cookie;
  if (typeof header !== 'string') return undefined;
  const name = loginCookieName();
  for (const part of header.split(';')) {
    const eq = part.indexOf('=');
    if (eq === -1 || part.slice(0, eq).trim() !== name) continue;
    const value = part.slice(eq + 1).trim();
    return BROWSER_BINDING.test(value) ? value : undefined;
  }
  return undefined;
}

/**
 * The Set-Cookie value binding this browser to the sign-ins it starts. One
 * value per browser, reused while it lasts, so several sign-ins in parallel
 * tabs all stay valid. SameSite=Lax keeps it off cross-site POSTs.
 */
export function browserBindingCookie(value: string): string {
  const secure = loginCookieName().startsWith('__Host-') ? '; Secure' : '';
  return `${loginCookieName()}=${value}; Path=/; Max-Age=${LOGIN_FORM_TTL_SECS}; HttpOnly; SameSite=Lax${secure}`;
}

export function newBrowserBinding(): string {
  return randomBytes(32).toString('base64url');
}

export type FlowPage =
  | { kind: 'waiting'; clientName?: string; redirectUri: string; loginUrl: string; flowId: string }
  | { kind: 'error'; message: string };

// How often the waiting page reloads itself to check on the sign-in.
export const FLOW_PAGE_REFRESH_SECS = 3;

/**
 * The page /authorize shows while the user signs in on Nextcloud. It has no
 * script: a meta refresh reloads it until the grant arrives, and the reload
 * that finds it is answered with the redirect to the client.
 */
export function renderFlowPage(page: FlowPage): string {
  const body =
    page.kind === 'error'
      ? `<h1>Sign-in failed</h1>
    <div class="error">${escapeHtml(page.message)}</div>`
      : `<h1>Sign in with Nextcloud</h1>
    <div class="consent-banner">
      <strong>${page.clientName ? escapeHtml(page.clientName) : 'An application'}</strong> is requesting access to your Nextcloud account.
      <span class="detail">Redirecting to: <code>${escapeHtml(page.redirectUri)}</code></span>
    </div>
    <p>Sign in on your Nextcloud and grant access. This page continues on its own once you have.</p>
    <a class="button" href="${escapeHtml(page.loginUrl)}" target="_blank" rel="noopener noreferrer">Open Nextcloud</a>`;
  const refresh =
    page.kind === 'waiting'
      ? `\n  <meta http-equiv="refresh" content="${FLOW_PAGE_REFRESH_SECS};url=/auth/flow/${escapeHtml(page.flowId)}">`
      : '';
  return `<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">${refresh}
  <title>AIquila – Sign in with Nextcloud</title>
  <link rel="stylesheet" href="${LOGIN_STYLESHEET_PATH}">
</head>
<body>
  <div class="card">
    ${body}
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
  // The sign-in binding cookie of the browser /authorize answered. The flow
  // it starts completes only in that browser (login CSRF, RFC 6749 §10.12).
  browserBinding?: string;
}

// --- OAuth Provider ---

export interface ProviderOptions {
  // Where grants' Nextcloud credentials are kept; from the environment by default.
  credentials?: CredentialStore;
}

// A Login Flow v2 token is valid for 20 minutes (Nextcloud Login Flow docs).
const FLOW_TTL_MS = 20 * 60 * 1000;
// Nextcloud asks clients to poll at most once per second.
const FLOW_POLL_INTERVAL_MS = 1000;
// How long a credential Nextcloud confirmed is trusted before it is asked again.
// Bounds how long a revoked app password, or a disabled user, keeps working.
const CREDENTIAL_RECHECK_MS = 5 * 60 * 1000;
const SWEEP_INTERVAL_MS = 60 * 1000;
// After a failed tool call the credential is checked again, at most this often.
const FAILURE_RECHECK_MS = 30 * 1000;
const FLOW_ID = /^[A-Za-z0-9_-]{43}$/;

/** A sign-in in progress: the authorization request and its Login Flow v2. */
interface PendingFlow {
  flow: StartedFlow;
  clientId: string;
  clientName?: string;
  params: AuthorizationParams;
  expiresAt: number;
  lastPolledAt: number;
  polling?: Promise<void>;
  // Set once Nextcloud has answered: the user's credential, or why it failed.
  granted?: { userId: string } & GrantedFlow;
  error?: string;
}

/** The device name Nextcloud shows for the app password: printable and short. */
function deviceName(clientName: string | undefined): string {
  const name = (clientName ?? '')
    .replace(/[^\x20-\x7e]/g, '')
    .trim()
    .slice(0, 60);
  return name ? `AIquila MCP (${name})` : 'AIquila MCP';
}

export class NextcloudOAuthProvider {
  private readonly _clientsStore: ClientsStore;
  private readonly codeStore = new CodeStore();
  private readonly refreshStore = RefreshStore.fromEnv();
  private readonly revocations = RevocationList.fromEnv();
  private readonly credentials: CredentialStore;
  private readonly flows = new Map<string, PendingFlow>();
  // When Nextcloud last confirmed each grant's credential.
  private readonly confirmedAt = new Map<string, number>();
  // When a failed tool call last made a grant's credential be checked.
  private readonly failureCheckedAt = new Map<string, number>();

  constructor(clientsStore: ClientsStore = ClientsStore.fromEnv(), options: ProviderOptions = {}) {
    this._clientsStore = clientsStore;
    this.credentials = options.credentials ?? CredentialStore.fromEnv();
    setInterval(() => void this.sweep(), SWEEP_INTERVAL_MS).unref();
  }

  get clientsStore(): ClientsStore {
    return this._clientsStore;
  }

  /**
   * Starts a Login Flow v2 for a validated authorization request and shows the
   * page that waits for it. The user signs in on Nextcloud itself; this server
   * never sees their password.
   */
  async authorize(
    client: OAuthClientInformationFull,
    params: AuthorizationParams,
    res: any
  ): Promise<void> {
    const clientName = clientDisplayName(client);
    let flow: StartedFlow;
    try {
      flow = await startLoginFlow(deviceName(clientName));
    } catch (err) {
      logger.error({ err: (err as Error).message }, '[auth] Could not start Login Flow v2');
      // RFC 6749 §4.1.2.1: reported to the client through its redirect URI.
      throw new OAuthError(OAuthErrorCode.ServerError, 'Nextcloud sign-in is unavailable');
    }
    const flowId = randomBytes(32).toString('base64url');
    this.flows.set(flowId, {
      flow,
      clientId: client.client_id,
      clientName,
      params,
      expiresAt: Date.now() + FLOW_TTL_MS,
      lastPolledAt: 0,
    });
    logger.info({ client: client.client_id }, '[auth] Login Flow v2 started');
    this.sendFlowPage(res, 200, {
      kind: 'waiting',
      clientName,
      redirectUri: params.redirectUri,
      loginUrl: flow.loginUrl,
      flowId,
    });
  }

  /**
   * GET /auth/flow/:id — the waiting page's reload. Polls Nextcloud; once the
   * user has granted access, issues the authorization code and redirects to
   * the client with it.
   */
  async continueFlow(flowId: string, browserBinding: string | undefined, res: any): Promise<void> {
    const pending = FLOW_ID.test(flowId) ? this.flows.get(flowId) : undefined;
    // The flow completes only in the browser that started it.
    if (!pending || !browserBinding || pending.params.browserBinding !== browserBinding) {
      this.sendFlowPage(res, 400, {
        kind: 'error',
        message:
          'This sign-in request is invalid or has expired. Start again from your application.',
      });
      return;
    }
    if (pending.expiresAt <= Date.now()) {
      this.dropFlow(flowId);
      this.sendFlowPage(res, 400, {
        kind: 'error',
        message: 'This sign-in request has expired. Start again from your application.',
      });
      return;
    }
    await this.pollFlow(pending);

    if (pending.error) {
      this.flows.delete(flowId);
      this.sendFlowPage(res, 200, { kind: 'error', message: pending.error });
      return;
    }
    if (!pending.granted) {
      this.sendFlowPage(res, 200, {
        kind: 'waiting',
        clientName: pending.clientName,
        redirectUri: pending.params.redirectUri,
        loginUrl: pending.flow.loginUrl,
        flowId,
      });
      return;
    }

    this.flows.delete(flowId);
    const { userId, loginName, appPassword } = pending.granted;
    const { params } = pending;
    const code = this.issueAuthCode({
      pkceChallenge: params.codeChallenge,
      clientId: pending.clientId,
      scopes: params.scopes,
      redirectUri: params.redirectUri,
      userId,
      state: params.state,
      credential: { loginName, appPassword },
    });
    logger.info({ user: userId, client: pending.clientId }, '[auth] Login successful');

    const redirectUrl = new URL(params.redirectUri);
    redirectUrl.searchParams.set('code', code);
    if (params.state) redirectUrl.searchParams.set('state', params.state);
    // The metadata advertises authorization_response_iss_parameter_supported,
    // so every authorization response must carry iss, and clients reject one
    // that lacks it (RFC 9207 §2, §2.4).
    redirectUrl.searchParams.set('iss', this.issuerIdentifier);
    res.redirect(302, redirectUrl.toString());
  }

  /** Polls a pending flow, at most once per interval and once at a time. */
  private pollFlow(pending: PendingFlow): Promise<void> {
    if (pending.granted || pending.error) return Promise.resolve();
    if (pending.polling) return pending.polling;
    if (Date.now() - pending.lastPolledAt < FLOW_POLL_INTERVAL_MS) return Promise.resolve();
    pending.lastPolledAt = Date.now();
    pending.polling = (async () => {
      try {
        const granted = await pollLoginFlow(pending.flow);
        if (!granted) return;
        const check = await checkCredential(granted.loginName, granted.appPassword);
        if (check.state !== 'valid') {
          void deleteAppPassword(granted.loginName, granted.appPassword);
          pending.error = 'Nextcloud did not confirm the new app password. Please try again.';
          return;
        }
        pending.granted = { userId: check.userId, ...granted };
      } catch (err) {
        logger.warn({ err: (err as Error).message }, '[auth] Login Flow v2 poll failed');
      } finally {
        pending.polling = undefined;
      }
    })();
    return pending.polling;
  }

  /** Forgets a flow; an app password it already obtained is deleted in Nextcloud. */
  private dropFlow(flowId: string): void {
    const pending = this.flows.get(flowId);
    this.flows.delete(flowId);
    if (pending?.granted) {
      void deleteAppPassword(pending.granted.loginName, pending.granted.appPassword);
    }
  }

  private sendFlowPage(res: any, status: number, page: FlowPage): void {
    applySecurityHeaders(res).status(status).type('html').send(renderFlowPage(page));
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

    const credential = entry.credential;
    if (!credential) {
      throw new OAuthError(OAuthErrorCode.InvalidGrant, 'Invalid authorization code');
    }
    const grantId = randomUUID();
    // Also drops the credential from the code: from here on the grant holds it.
    this.codeStore.markRedeemed(authorizationCode, grantId);
    await this.credentials.put(
      { grantId, userId: entry.userId, ...credential },
      Date.now() + refreshTtlMs()
    );
    this.confirmedAt.set(grantId, Date.now());
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

    // The grant is only as good as its app password. Nextcloud rejects it
    // once the user revokes the device, or is disabled or deleted, and then
    // the grant ends: RFC 6749 §5.2, a grant no longer valid is invalid_grant.
    if (!(await this.confirmCredential(entry.grantId, entry.userId, true))) {
      logger.warn(
        { user: entry.userId, client: client.client_id },
        "[token] Refresh refused: Nextcloud no longer accepts the grant's app password"
      );
      throw new OAuthError(OAuthErrorCode.InvalidGrant, 'Sign in to Nextcloud again');
    }
    this.credentials.extend(entry.grantId, Date.now() + refreshTtlMs());

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
        extra: { service: true },
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
      extra: { userId: claims.sub, grantId: claims.gid },
    };
  }

  /**
   * The Nextcloud credential a verified access token acts with, or undefined
   * when the grant has none any more (it ended, or Nextcloud rejected it).
   */
  async credentialFor(auth: AuthInfo): Promise<NextcloudUserCredential | undefined> {
    const userId = auth.extra?.userId;
    const grantId = auth.extra?.grantId;
    if (typeof userId !== 'string' || typeof grantId !== 'string') return undefined;
    if (!(await this.confirmCredential(grantId, userId, false))) return undefined;
    return this.credentials.get(grantId, userId);
  }

  /**
   * A tool of this grant failed. Nextcloud may have rejected the app password
   * (the device was removed, the user disabled): check now rather than at the
   * next periodic check, so the next request is answered 401 and the client
   * signs in again.
   */
  recheckAfterFailure(user: NextcloudUserCredential): void {
    const last = this.failureCheckedAt.get(user.grantId) ?? 0;
    if (Date.now() - last < FAILURE_RECHECK_MS) return;
    this.failureCheckedAt.set(user.grantId, Date.now());
    void this.confirmCredential(user.grantId, user.userId, true);
  }

  /**
   * True while the grant's credential exists and Nextcloud accepts it. Asks
   * Nextcloud when `force` is set or the last confirmation is older than
   * CREDENTIAL_RECHECK_MS. A rejection ends the grant; Nextcloud being
   * unreachable does not.
   */
  private async confirmCredential(
    grantId: string,
    userId: string,
    force: boolean
  ): Promise<boolean> {
    const cred = await this.credentials.get(grantId, userId);
    if (!cred) {
      this.revokeGrant(grantId);
      return false;
    }
    const last = this.confirmedAt.get(grantId);
    if (!force && last !== undefined && Date.now() - last < CREDENTIAL_RECHECK_MS) return true;
    const check = await checkCredential(cred.loginName, cred.appPassword);
    if (check.state === 'rejected' || (check.state === 'valid' && check.userId !== userId)) {
      logger.warn({ user: userId }, "[auth] Nextcloud rejected the grant's app password");
      this.revokeGrant(grantId);
      return false;
    }
    if (check.state === 'valid') this.confirmedAt.set(grantId, Date.now());
    return true;
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

  /**
   * Ends a grant: its refresh tokens go, its access tokens stop verifying, and
   * its app password is deleted here and in Nextcloud.
   */
  private revokeGrant(grantId: string): void {
    this.refreshStore.deleteGrant(grantId);
    this.revocations.revokeGrant(grantId, Date.now() + ACCESS_TOKEN_TTL_SECS * 1000);
    this.confirmedAt.delete(grantId);
    this.failureCheckedAt.delete(grantId);
    void this.forgetCredential(grantId);
  }

  private async forgetCredential(grantId: string): Promise<void> {
    if (!this.credentials.has(grantId)) return;
    const cred = await this.credentials.getForCleanup(grantId);
    this.credentials.delete(grantId);
    if (cred && !(await deleteAppPassword(cred.loginName, cred.appPassword))) {
      logger.warn(
        { user: cred.userId },
        '[auth] Could not delete the app password in Nextcloud — the user can remove the ' +
          '"AIquila MCP" device under Security → Devices & sessions'
      );
    }
  }

  /**
   * Housekeeping: ends grants whose credential expired, deletes the app
   * passwords of codes never redeemed and of flows never finished, and polls
   * flows whose page was closed so their app password is not left behind.
   */
  async sweep(): Promise<void> {
    for (const { grantId } of this.credentials.expired()) this.revokeGrant(grantId);
    for (const entry of this.codeStore.takeExpired()) {
      if (entry.credential && !entry.redeemedGrantId) {
        void deleteAppPassword(entry.credential.loginName, entry.credential.appPassword);
      }
    }
    const now = Date.now();
    for (const [flowId, pending] of this.flows) {
      if (pending.expiresAt <= now) this.dropFlow(flowId);
      else await this.pollFlow(pending);
    }
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

  /** Issues an authorization code for a user who signed in on Nextcloud. */
  issueAuthCode(params: {
    pkceChallenge: string;
    clientId: string;
    scopes: string[];
    redirectUri: string;
    userId: string;
    state?: string;
    credential: { loginName: string; appPassword: string };
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
