<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Stub for Nextcloud's global `OC` class — the legacy service locator.
 *
 * It lives in its own file because the rest of the private-namespace stubs in
 * {@see oc-private.php} are namespaced and PHP cannot mix namespaced and global
 * declarations without braces.
 *
 * Only the two statics the app reads are stubbed: `OccController` needs
 * `$SERVERROOT` to shell out to `occ`, and `CoworkerOutputWidget` needs
 * `$server` for the CSP nonce. Nothing here has an `OCP\` equivalent.
 */

if (!class_exists(OC::class)) {
    class OC {
        public static string $SERVERROOT = '';
        public static \OC\Server $server;
    }
}
