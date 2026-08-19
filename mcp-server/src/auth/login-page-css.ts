// SPDX-License-Identifier: MIT

/**
 * Stylesheet for the OAuth login page.
 *
 * Served as a standalone document at LOGIN_STYLESHEET_PATH rather than inlined
 * in a <style> block, so the login page's CSP can drop 'unsafe-inline' entirely.
 * Keep this free of any interpolated/user-supplied value — it is served verbatim.
 */
export const LOGIN_STYLESHEET = `body { font-family: sans-serif; background: #f4f6f8; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; }
.card { background: #fff; border-radius: 8px; box-shadow: 0 2px 12px rgba(0,0,0,.12); padding: 2rem; width: 100%; max-width: 360px; }
h1 { font-size: 1.3rem; margin: 0 0 .25rem; }
.consent-banner { background: #eef6fc; border: 1px solid #b3d7f0; border-radius: 4px; padding: .75rem 1rem; margin-bottom: 1.25rem; font-size: .85rem; color: #333; line-height: 1.6; }
.consent-banner strong { color: #0082c9; }
.consent-banner .detail { display: block; margin-top: .25rem; }
.consent-banner code { background: #d6ecf9; border-radius: 3px; padding: 0 .3em; font-size: .85em; word-break: break-all; }
label { display: block; font-size: .85rem; font-weight: 600; margin-bottom: .25rem; }
input[type=text], input[type=password] { width: 100%; box-sizing: border-box; padding: .55rem .75rem; border: 1px solid #ccc; border-radius: 4px; font-size: 1rem; margin-bottom: 1rem; }
button { width: 100%; padding: .65rem; background: #0082c9; color: #fff; border: none; border-radius: 4px; font-size: 1rem; cursor: pointer; }
button:hover { background: #006fa3; }
.error { background: #fdecea; color: #c0392b; border-radius: 4px; padding: .6rem .9rem; margin-bottom: 1rem; font-size: .9rem; }
`;

/** Public path the login page links its stylesheet from. */
export const LOGIN_STYLESHEET_PATH = '/auth/login.css';
