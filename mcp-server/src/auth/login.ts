// SPDX-License-Identifier: MIT

import {
  NextcloudOAuthProvider,
  renderLoginForm,
  applySecurityHeaders,
  verifyLoginRequestMac,
  type LoginFormRequest,
} from './provider.js';
import { isRegisteredRedirectUri } from './redirect-uri.js';
import { logger } from '../logger.js';

/**
 * The user id from a /cloud/user response. A login name (an email address, an
 * LDAP name) can differ from the uid, and the uid is what grants are bound to
 * and what the provisioning API looks up later.
 */
async function nextcloudUid(resp: Response, loginName: string): Promise<string> {
  try {
    const body = (await resp.json()) as { ocs?: { data?: { id?: unknown } } };
    const id = body?.ocs?.data?.id;
    if (typeof id === 'string' && id !== '') return id;
  } catch {
    // fall through
  }
  return loginName;
}

export function loginHandler(provider: NextcloudOAuthProvider) {
  return async (req: any, res: any): Promise<void> => {
    const body = req.body as Record<string, string>;
    const { username, password, client_id, redirect_uri, state, code_challenge, scope } = body;
    const request: LoginFormRequest = {
      clientId: client_id ?? '',
      redirectUri: redirect_uri ?? '',
      redirectUriExplicit: body.redirect_uri_explicit !== '',
      codeChallenge: code_challenge ?? '',
      state: state || undefined,
      scope: scope || undefined,
      issuedAt: Number(body.request_iat),
    };

    // Re-renders the form with the request as received, so the user can retry.
    const fail = (status: number, error: string, form: Partial<LoginFormRequest> = {}) => {
      applySecurityHeaders(res)
        .status(status)
        .type('html')
        .send(renderLoginForm({ ...request, ...form, requestMac: body.request_mac, error }));
    };

    const ncUrl = process.env.NEXTCLOUD_URL?.replace(/\/+$/, '');
    if (!ncUrl) {
      res.status(500).send('Server configuration error: NEXTCLOUD_URL not set');
      return;
    }

    if (!username || !password || !client_id || !redirect_uri || !code_challenge) {
      fail(400, 'Missing required parameters');
      return;
    }

    // The form must be the one /authorize rendered, unchanged.
    if (!verifyLoginRequestMac(request, body.request_mac)) {
      logger.warn({ client: client_id }, '[auth] Login form failed its integrity check');
      fail(
        400,
        'This sign-in request is invalid or has expired. Start again from your application.'
      );
      return;
    }

    const client = await provider.clientsStore.getClient(client_id);
    if (!client) {
      fail(400, 'Unknown client');
      return;
    }
    // Same rule as /authorize, so a native client's ephemeral loopback port
    // passes here too (RFC 8252 §7.3).
    if (!isRegisteredRedirectUri(redirect_uri, client.redirect_uris)) {
      fail(400, 'Invalid redirect URI', { redirectUri: '' });
      return;
    }

    logger.info({ user: username, client: client_id, nc: ncUrl }, '[auth] Login attempt');

    try {
      const credentials = Buffer.from(`${username}:${password}`).toString('base64');
      const ncResp = await fetch(`${ncUrl}/ocs/v2.php/cloud/user`, {
        headers: {
          Authorization: `Basic ${credentials}`,
          'OCS-APIRequest': 'true',
          Accept: 'application/json',
        },
      });

      if (!ncResp.ok) {
        logger.warn({ user: username, status: ncResp.status }, '[auth] Login failed');
        fail(200, 'Invalid Nextcloud credentials');
        return;
      }

      const userId = await nextcloudUid(ncResp, username);
      const code = provider.issueAuthCode({
        pkceChallenge: code_challenge,
        clientId: client_id,
        scopes: scope ? scope.split(' ').filter(Boolean) : [],
        redirectUri: redirect_uri,
        redirectUriExplicit: request.redirectUriExplicit,
        userId,
        state: state || undefined,
      });

      logger.info({ user: userId, client: client_id }, '[auth] Login successful');

      const redirectUrl = new URL(redirect_uri);
      redirectUrl.searchParams.set('code', code);
      if (state) redirectUrl.searchParams.set('state', state);
      // The metadata advertises authorization_response_iss_parameter_supported,
      // so every authorization response must carry iss, and clients reject one
      // that lacks it (RFC 9207 §2, §2.4).
      redirectUrl.searchParams.set('iss', provider.issuerIdentifier);
      res.redirect(redirectUrl.toString());
    } catch (err) {
      logger.error({ user: username, err }, '[auth] Login error');
      fail(200, 'Authentication failed. Please try again.');
    }
  };
}
