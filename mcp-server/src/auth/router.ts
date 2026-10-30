// SPDX-License-Identifier: MIT

// The OAuth 2.0 authorization server: metadata, /authorize, /token, /register
// and /revoke. One grant pair (authorization_code + refresh_token), PKCE S256
// only, public clients plus confidential clients registered via RFC 7591.
// Errors are the v2 SDK's OAuthError throughout, the same type the bearer
// check on /mcp uses.

import { createHash, randomBytes, timingSafeEqual } from 'node:crypto';
import express from 'express';
import { rateLimit } from 'express-rate-limit';
import { mcpAuthMetadataRouter } from '@modelcontextprotocol/express';
import {
  OAuthError,
  OAuthErrorCode,
  specTypeSchemas,
  type OAuthClientInformationFull,
  type OAuthClientMetadata,
  type OAuthMetadata,
} from '@modelcontextprotocol/server';
import {
  browserBindingCookie,
  newBrowserBinding,
  readBrowserBinding,
  type NextcloudOAuthProvider,
} from './provider.js';
import { isRegisteredRedirectUri } from './redirect-uri.js';
import { logger } from '../logger.js';

// Lifetime of a client secret issued by POST /register (30 days).
const CLIENT_SECRET_TTL_SECS = 30 * 24 * 60 * 60;

// RFC 7636 §4.2: BASE64URL(SHA256(verifier)) is always 43 characters.
const S256_CHALLENGE = /^[A-Za-z0-9_-]{43}$/;
// RFC 7636 §4.1: code_verifier = 43*128unreserved
const CODE_VERIFIER = /^[A-Za-z0-9._~-]{43,128}$/;

export interface AuthRouterOptions {
  provider: NextcloudOAuthProvider;
  issuerUrl: URL;
  resourceServerUrl: URL;
  serviceDocumentationUrl?: URL;
  scopesSupported: string[];
  // When set, POST /register requires `Authorization: Bearer <token>`.
  registrationToken?: string;
}

/** Authorization server metadata (RFC 8414 §2). */
export function buildAuthorizationServerMetadata(options: AuthRouterOptions): OAuthMetadata {
  const { provider, issuerUrl } = options;
  const endpoint = (path: string) => new URL(path, issuerUrl).href;
  const registration = !!provider.clientsStore.registerClient;
  return {
    issuer: issuerUrl.href,
    service_documentation: options.serviceDocumentationUrl?.href,
    authorization_endpoint: endpoint('/authorize'),
    response_types_supported: ['code'],
    code_challenge_methods_supported: ['S256'],
    token_endpoint: endpoint('/token'),
    token_endpoint_auth_methods_supported: ['client_secret_post', 'none'],
    grant_types_supported: ['authorization_code', 'refresh_token'],
    scopes_supported: options.scopesSupported,
    revocation_endpoint: endpoint('/revoke'),
    // Public clients revoke with client_id alone, like they use /token.
    revocation_endpoint_auth_methods_supported: ['client_secret_post', 'none'],
    registration_endpoint: registration ? endpoint('/register') : undefined,
    // Every authorization response carries iss, success and error (RFC 9207 §2).
    authorization_response_iss_parameter_supported: true,
    // draft-ietf-oauth-client-id-metadata-document; MCP clients pick CIMD over
    // registration when this is true and "none" is a token auth method.
    client_id_metadata_document_supported: provider.clientsStore.clientIdMetadataDocumentsEnabled
      ? true
      : undefined,
  };
}

/** HTTP status for an error from the token, registration or revocation endpoint (RFC 6749 §5.2). */
function statusFor(err: OAuthError): number {
  switch (err.code) {
    case OAuthErrorCode.ServerError:
      return 500;
    case OAuthErrorCode.TemporarilyUnavailable:
      return 503;
    case OAuthErrorCode.TooManyRequests:
      return 429;
    case OAuthErrorCode.MethodNotAllowed:
      return 405;
    default:
      // invalid_client is 400 too: 401 is required only when the client
      // authenticated via the Authorization header, and client_secret_post
      // does not use it.
      return 400;
  }
}

function asOAuthError(err: unknown, context: string): OAuthError {
  if (err instanceof OAuthError) return err;
  logger.error({ err }, `[oauth] ${context} failed`);
  return new OAuthError(OAuthErrorCode.ServerError, 'Internal Server Error');
}

function sendJsonError(res: any, err: unknown, context: string): void {
  const oauthErr = asOAuthError(err, context);
  res.status(statusFor(oauthErr)).json(oauthErr.toResponseObject());
}

/**
 * Request parameters as single strings. RFC 6749 §3.1: a parameter sent
 * without a value is treated as omitted, and none may appear more than once.
 */
function params(source: unknown): Record<string, string> {
  const out: Record<string, string> = {};
  if (typeof source !== 'object' || source === null) return out;
  for (const [key, value] of Object.entries(source)) {
    if (Array.isArray(value)) {
      throw new OAuthError(
        OAuthErrorCode.InvalidRequest,
        `Parameter ${key} is included more than once`
      );
    }
    if (typeof value === 'string' && value !== '') out[key] = value;
  }
  return out;
}

function required(p: Record<string, string>, name: string): string {
  const value = p[name];
  if (value === undefined) {
    throw new OAuthError(OAuthErrorCode.InvalidRequest, `Missing required parameter: ${name}`);
  }
  return value;
}

/** A URI compared the way the MCP canonical-URI rules allow: case-folded scheme and host, no trailing slash. */
function canonicalResource(url: URL): string {
  return `${url.protocol}//${url.host}${url.pathname.replace(/\/+$/, '')}${url.search}`;
}

/**
 * RFC 8707 §2: the resource indicator must be an absolute URI without a
 * fragment, and one this server issues tokens for; anything else is
 * invalid_target. Tokens are always audience-restricted to the MCP endpoint,
 * so the accepted values are that URL and the server's origin, both of which
 * the MCP specification (2026-07-28, Canonical Server URI) lists as canonical
 * forms. Omitting resource is allowed (§2: the AS MAY use a default).
 */
function resourceChecker(options: AuthRouterOptions): (resource: string | undefined) => void {
  const accepted = new Set([
    canonicalResource(options.resourceServerUrl),
    canonicalResource(new URL(options.resourceServerUrl.origin)),
  ]);
  return (resource) => {
    if (resource === undefined) return;
    if (!URL.canParse(resource) || new URL(resource).hash) {
      throw new OAuthError(OAuthErrorCode.InvalidTarget, 'resource must be an absolute URI');
    }
    if (!accepted.has(canonicalResource(new URL(resource)))) {
      throw new OAuthError(
        OAuthErrorCode.InvalidTarget,
        `This server issues tokens only for ${options.resourceServerUrl.href}`
      );
    }
  };
}

function secretsEqual(a: string, b: string): boolean {
  const hash = (s: string) => createHash('sha256').update(s).digest();
  return timingSafeEqual(hash(a), hash(b));
}

/** RFC 7636 §4.6: BASE64URL-ENCODE(SHA256(ASCII(code_verifier))) == code_challenge. */
function pkceMatches(verifier: string, challenge: string): boolean {
  const computed = createHash('sha256').update(verifier, 'ascii').digest('base64url');
  return computed.length === challenge.length && secretsEqual(computed, challenge);
}

/**
 * Client authentication for /token and /revoke: client_secret_post for
 * clients that hold a secret (RFC 6749 §2.3.1), client_id alone for public
 * clients (§2.1). Every failure is invalid_client.
 */
async function authenticateClient(
  provider: NextcloudOAuthProvider,
  p: Record<string, string>
): Promise<OAuthClientInformationFull> {
  const clientId = required(p, 'client_id');
  const client = await provider.clientsStore.getClient(clientId);
  if (!client) throw new OAuthError(OAuthErrorCode.InvalidClient, 'Invalid client_id');
  if (client.client_secret) {
    const secret = p.client_secret;
    if (!secret) throw new OAuthError(OAuthErrorCode.InvalidClient, 'Client secret is required');
    if (!secretsEqual(secret, client.client_secret)) {
      throw new OAuthError(OAuthErrorCode.InvalidClient, 'Invalid client_secret');
    }
    if (
      client.client_secret_expires_at &&
      client.client_secret_expires_at < Math.floor(Date.now() / 1000)
    ) {
      throw new OAuthError(OAuthErrorCode.InvalidClient, 'Client secret has expired');
    }
  }
  return client;
}

/** Answers any method not in `methods` with 405 and an Allow header. */
function allowMethods(methods: string[]) {
  return (req: any, res: any, next: any) => {
    if (methods.includes(req.method)) return next();
    res
      .status(405)
      .set('Allow', methods.join(', '))
      .json(
        new OAuthError(
          OAuthErrorCode.MethodNotAllowed,
          `The method ${req.method} is not allowed for this endpoint`
        ).toResponseObject()
      );
  };
}

/**
 * Lets any origin read the response. These endpoints take no cookies, so a
 * wildcard is safe; the app-wide CORS middleware has already echoed a trusted
 * origin with credentials, and that is left in place.
 */
function anyOrigin(_req: any, res: any, next: any) {
  if (!res.getHeader('Access-Control-Allow-Origin')) {
    res.setHeader('Access-Control-Allow-Origin', '*');
  }
  next();
}

function limiter(windowMs: number, limit: number, what: string) {
  return rateLimit({
    windowMs,
    limit,
    standardHeaders: true,
    legacyHeaders: false,
    message: new OAuthError(
      OAuthErrorCode.TooManyRequests,
      `You have exceeded the rate limit for ${what} requests`
    ).toResponseObject(),
  });
}

function authorizationHandler(options: AuthRouterOptions) {
  const { provider } = options;
  const checkResource = resourceChecker(options);
  const issuer = options.issuerUrl.href;

  return async (req: any, res: any) => {
    res.setHeader('Cache-Control', 'no-store');

    // Until client_id and redirect_uri are verified, errors go to the user
    // agent, never to the redirect URI (RFC 6749 §4.1.2.1).
    let p: Record<string, string>;
    let client: OAuthClientInformationFull;
    let redirectUri: string;
    try {
      p = params(req.method === 'POST' ? req.body : req.query);
      client = await provider.clientsStore.getClient(required(p, 'client_id')).then((c) => {
        if (!c) throw new OAuthError(OAuthErrorCode.InvalidClient, 'Invalid client_id');
        return c;
      });
      if (p.redirect_uri !== undefined) {
        if (!isRegisteredRedirectUri(p.redirect_uri, client.redirect_uris)) {
          throw new OAuthError(OAuthErrorCode.InvalidRequest, 'Unregistered redirect_uri');
        }
        redirectUri = p.redirect_uri;
      } else if (client.redirect_uris.length === 1) {
        // RFC 6749 §3.1.2.3: optional when exactly one is registered.
        redirectUri = String(client.redirect_uris[0]);
      } else {
        throw new OAuthError(
          OAuthErrorCode.InvalidRequest,
          'redirect_uri must be specified when client has multiple registered URIs'
        );
      }
    } catch (err) {
      sendJsonError(res, err, 'authorize');
      return;
    }

    // From here on, errors are reported to the client through its redirect URI,
    // with state and iss (RFC 6749 §4.1.2.1, RFC 9207 §2).
    const state = p.state;
    try {
      if (p.response_type !== 'code') {
        throw new OAuthError(
          OAuthErrorCode.UnsupportedResponseType,
          'response_type must be "code"'
        );
      }
      // RFC 7636 §4.4.1: PKCE is required here, and only S256 is supported.
      const codeChallenge = required(p, 'code_challenge');
      if (p.code_challenge_method !== 'S256') {
        throw new OAuthError(OAuthErrorCode.InvalidRequest, 'code_challenge_method must be S256');
      }
      if (!S256_CHALLENGE.test(codeChallenge)) {
        throw new OAuthError(OAuthErrorCode.InvalidRequest, 'code_challenge is malformed');
      }
      checkResource(p.resource);
      // Bind the sign-in to this browser (see AuthorizationParams.browserBinding).
      const browserBinding = readBrowserBinding(req) ?? newBrowserBinding();
      res.append('Set-Cookie', browserBindingCookie(browserBinding));
      await provider.authorize(
        client,
        {
          redirectUri,
          codeChallenge,
          state,
          scopes: p.scope ? p.scope.split(' ').filter(Boolean) : [],
          browserBinding,
          clientAddress: req.ip,
        },
        res
      );
    } catch (err) {
      const oauthErr = asOAuthError(err, 'authorize');
      const target = new URL(redirectUri);
      target.searchParams.set('error', String(oauthErr.code));
      target.searchParams.set('error_description', oauthErr.message);
      if (oauthErr.errorUri) target.searchParams.set('error_uri', oauthErr.errorUri);
      if (state !== undefined) target.searchParams.set('state', state);
      target.searchParams.set('iss', issuer);
      res.redirect(302, target.href);
    }
  };
}

function tokenHandler(options: AuthRouterOptions) {
  const { provider } = options;
  const checkResource = resourceChecker(options);
  return async (req: any, res: any) => {
    // RFC 6749 §5.1: responses carrying tokens must not be cached.
    res.setHeader('Cache-Control', 'no-store');
    res.setHeader('Pragma', 'no-cache');
    try {
      const p = params(req.body);
      const grantType = required(p, 'grant_type');
      if (grantType !== 'authorization_code' && grantType !== 'refresh_token') {
        throw new OAuthError(
          OAuthErrorCode.UnsupportedGrantType,
          'The grant type is not supported by this authorization server.'
        );
      }
      const client = await authenticateClient(provider, p);
      checkResource(p.resource);

      if (grantType === 'authorization_code') {
        const code = required(p, 'code');
        const verifier = required(p, 'code_verifier');
        if (!CODE_VERIFIER.test(verifier)) {
          throw new OAuthError(OAuthErrorCode.InvalidRequest, 'code_verifier is malformed');
        }
        const challenge = await provider.challengeForAuthorizationCode(client, code);
        if (!pkceMatches(verifier, challenge)) {
          throw new OAuthError(
            OAuthErrorCode.InvalidGrant,
            'code_verifier does not match the challenge'
          );
        }
        res
          .status(200)
          .json(await provider.exchangeAuthorizationCode(client, code, p.redirect_uri));
      } else {
        const refreshToken = required(p, 'refresh_token');
        const scopes = p.scope?.split(' ').filter(Boolean);
        res.status(200).json(await provider.exchangeRefreshToken(client, refreshToken, scopes));
      }
    } catch (err) {
      sendJsonError(res, err, 'token');
    }
  };
}

function registrationHandler(provider: NextcloudOAuthProvider) {
  const registerClient = provider.clientsStore.registerClient!;
  return async (req: any, res: any) => {
    res.setHeader('Cache-Control', 'no-store');
    try {
      const result = specTypeSchemas.OAuthClientMetadata['~standard'].validate(req.body ?? {});
      if (result instanceof Promise || result.issues) {
        const detail =
          result instanceof Promise
            ? ''
            : result.issues
                .map((i) => `${(i.path ?? []).map(String).join('.')}: ${i.message}`)
                .join('; ');
        // RFC 7591 §3.2.2: invalid_redirect_uri when it is the redirect URIs
        // that are wrong, invalid_client_metadata for anything else.
        const onlyRedirects =
          !(result instanceof Promise) &&
          result.issues.length > 0 &&
          result.issues.every((i) => String(i.path?.[0] ?? '') === 'redirect_uris') &&
          Array.isArray((req.body ?? {}).redirect_uris);
        throw new OAuthError(
          onlyRedirects ? OAuthErrorCode.InvalidRedirectUri : OAuthErrorCode.InvalidClientMetadata,
          detail || 'Invalid metadata'
        );
      }
      const metadata = result.value as OAuthClientMetadata;
      const isPublic = metadata.token_endpoint_auth_method === 'none';
      const issuedAt = Math.floor(Date.now() / 1000);
      const client = registerClient({
        ...metadata,
        client_secret: isPublic ? undefined : randomBytes(32).toString('hex'),
        client_secret_expires_at: isPublic ? undefined : issuedAt + CLIENT_SECRET_TTL_SECS,
      });
      // RFC 7591 §3.2.1
      res.status(201).json(client);
    } catch (err) {
      sendJsonError(res, err, 'register');
    }
  };
}

function revocationHandler(provider: NextcloudOAuthProvider) {
  return async (req: any, res: any) => {
    res.setHeader('Cache-Control', 'no-store');
    try {
      const p = params(req.body);
      const client = await authenticateClient(provider, p);
      await provider.revokeToken(client, {
        token: required(p, 'token'),
        token_type_hint: p.token_type_hint,
      });
      // RFC 7009 §2.2: 200 whether or not the token was valid.
      res.status(200).json({});
    } catch (err) {
      sendJsonError(res, err, 'revoke');
    }
  };
}

/**
 * The authorization server's routes. Mount at the application root. Rate
 * limits per client IP: /authorize 100 per 15 min, /auth/flow 600 per 15 min,
 * /token 50 per 15 min, /register 20 per hour, /revoke 50 per 15 min.
 */
export function authRouter(options: AuthRouterOptions): express.Router {
  const { provider, registrationToken } = options;
  const router = express.Router();
  const form = express.urlencoded({ extended: false });
  const minutes = (n: number) => n * 60 * 1000;

  // RFC 8414 + RFC 9728 discovery documents. The SDK's metadata router only
  // serves what it is given, so the AS metadata stays entirely ours.
  router.use(
    mcpAuthMetadataRouter({
      oauthMetadata: buildAuthorizationServerMetadata(options),
      resourceServerUrl: options.resourceServerUrl,
      serviceDocumentationUrl: options.serviceDocumentationUrl,
      scopesSupported: options.scopesSupported,
    })
  );

  router.use(
    '/authorize',
    allowMethods(['GET', 'POST']),
    form,
    limiter(minutes(15), 100, 'authorization'),
    authorizationHandler(options)
  );

  // The sign-in page reloads itself here every FLOW_PAGE_REFRESH_SECS while the
  // user signs in on Nextcloud (up to 20 minutes): about 300 reloads per tab
  // per window, and several people may sign in from behind one address. The
  // provider polls Nextcloud at most once per second per flow whatever the rate.
  router.get(
    '/auth/flow/:id',
    limiter(minutes(15), 3000, 'sign-in'),
    async (req: any, res: any) => {
      res.setHeader('Cache-Control', 'no-store');
      await provider.continueFlow(String(req.params.id), readBrowserBinding(req), res);
    }
  );

  router.use(
    '/token',
    anyOrigin,
    allowMethods(['POST']),
    form,
    limiter(minutes(15), 50, 'token'),
    tokenHandler(options)
  );

  if (provider.clientsStore.registerClient) {
    router.use(
      '/register',
      anyOrigin,
      allowMethods(['POST']),
      ...(registrationToken
        ? [
            (req: any, res: any, next: any) => {
              const header = req.headers.authorization;
              if (
                typeof header !== 'string' ||
                !secretsEqual(header, `Bearer ${registrationToken}`)
              ) {
                res.status(401).json({
                  error: 'unauthorized_client',
                  error_description: 'Valid registration token required',
                });
                return;
              }
              next();
            },
          ]
        : []),
      express.json(),
      limiter(minutes(60), 20, 'client registration'),
      registrationHandler(provider)
    );
  }

  router.use(
    '/revoke',
    anyOrigin,
    allowMethods(['POST']),
    form,
    limiter(minutes(15), 50, 'token revocation'),
    revocationHandler(provider)
  );

  return router;
}
