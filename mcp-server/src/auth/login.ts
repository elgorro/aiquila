import { NextcloudOAuthProvider, renderLoginForm } from './provider.js';

export function loginHandler(provider: NextcloudOAuthProvider) {
  return async (req: any, res: any): Promise<void> => {
    const body = req.body as Record<string, string>;
    const { username, password, client_id, redirect_uri, state, code_challenge, scope } = body;

    const ncUrl = process.env.NEXTCLOUD_URL;
    if (!ncUrl) {
      res.status(500).send('Server configuration error: NEXTCLOUD_URL not set');
      return;
    }

    if (!username || !password || !client_id || !redirect_uri || !code_challenge) {
      res.status(400).type('html').send(
        renderLoginForm({ clientId: client_id ?? '', redirectUri: redirect_uri ?? '', codeChallenge: code_challenge ?? '', state, scope, error: 'Missing required parameters' })
      );
      return;
    }

    try {
      const credentials = Buffer.from(`${username}:${password}`).toString('base64');
      const ncResp = await fetch(`${ncUrl}/ocs/v2.php/cloud/user`, {
        headers: {
          Authorization: `Basic ${credentials}`,
          'OCS-APIRequest': 'true',
        },
      });

      if (!ncResp.ok) {
        res.status(200).type('html').send(
          renderLoginForm({ clientId: client_id, redirectUri: redirect_uri, codeChallenge: code_challenge, state, scope, error: 'Invalid Nextcloud credentials' })
        );
        return;
      }

      const scopes = scope ? scope.split(' ').filter(Boolean) : [];
      const code = provider.issueAuthCode({
        pkceChallenge: code_challenge,
        clientId: client_id,
        scopes,
        redirectUri: redirect_uri,
        userId: username,
        state: state || undefined,
      });

      const redirectUrl = new URL(redirect_uri);
      redirectUrl.searchParams.set('code', code);
      if (state) redirectUrl.searchParams.set('state', state);
      res.redirect(redirectUrl.toString());
    } catch {
      res.status(200).type('html').send(
        renderLoginForm({ clientId: client_id, redirectUri: redirect_uri, codeChallenge: code_challenge, state, scope, error: 'Authentication failed. Please try again.' })
      );
    }
  };
}
