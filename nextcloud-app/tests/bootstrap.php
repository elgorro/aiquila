<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * PHPUnit bootstrap for AIquila tests.
 *
 * The `OCP\` API comes from the official `nextcloud/ocp` dev dependency, pinned
 * to the minimum Nextcloud version in `appinfo/info.xml`. Do not hand-write
 * `OCP\` stubs here: a stub that drifts from upstream makes the tests pass
 * against an API that does not exist in production (see #422/#423).
 */

require_once __DIR__ . '/../vendor/autoload.php';

// nextcloud/ocp declares no `autoload` section — it is published for static
// analysers, which read the sources directly. Map it ourselves so PHPUnit can
// mock the real interfaces.
spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'OCP\\' => __DIR__ . '/../vendor/nextcloud/ocp/OCP/',
        'NCU\\' => __DIR__ . '/../vendor/nextcloud/ocp/NCU/',
    ];

    foreach ($prefixes as $prefix => $baseDir) {
        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            continue;
        }

        $file = $baseDir . str_replace('\\', '/', substr($class, $len)) . '.php';
        if (file_exists($file)) {
            require $file;
        }

        return;
    }
});

require_once __DIR__ . '/stubs/oc-private.php';
