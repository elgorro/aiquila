<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Template;

use OCA\AIquila\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\IURLGenerator;
use OCP\Server;
use OCP\Util;

/**
 * Loads a Vite entry (module script + its stylesheets) into the page.
 *
 * Two things make this necessary instead of Util::addScript():
 *
 *  - The build emits ES modules with code-split chunks, and addScript() writes a
 *    classic <script> tag under which those chunk imports never resolve.
 *  - Vite only injects CSS automatically for *dynamically* imported chunks. The
 *    stylesheet of an entry (and of the static chunks it pulls in, notably
 *    vendor-nextcloud-vue) has to be linked by the host page, which is why the
 *    settings pages rendered unstyled before this existed.
 *
 * The entry -> asset mapping comes from Vite's build manifest.
 */
final class ViteAssets {
    private const MANIFEST = 'js/dist/manifest.json';

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $manifest = null;

    /**
     * Emit the module script for $entry plus every stylesheet it depends on.
     *
     * @param string $entry Vite entry name, e.g. `aiquila-admin`.
     */
    public static function load(string $entry): void {
        $urlGenerator = Server::get(IURLGenerator::class);
        $version = Server::get(IAppManager::class)->getAppVersion(Application::APP_ID);

        $url = static function (string $path) use ($urlGenerator, $version): string {
            return $urlGenerator->linkTo(Application::APP_ID, 'js/dist/' . $path) . '?v=' . $version;
        };

        $manifest = self::manifest();
        $key = self::manifestKey($manifest, $entry);

        // Stylesheets first so the page never paints an unstyled frame.
        foreach (self::stylesheets($manifest, $key) as $css) {
            Util::addHeader('link', [
                'rel' => 'stylesheet',
                'href' => $url($css),
            ], '');
        }

        // A build without a manifest still resolves because entryFileNames is
        // `[name].js`; going unstyled beats a fatal error on a half-built tree.
        $file = $key !== null && isset($manifest[$key]['file']) && is_string($manifest[$key]['file'])
            ? $manifest[$key]['file']
            : $entry . '.js';

        Util::addHeader('script', [
            'type' => 'module',
            'src' => $url($file),
            'nonce' => \OC::$server->getContentSecurityPolicyNonceManager()->getNonce(),
        ], '');
    }

    /**
     * CSS emitted for the entry and for the static chunks it imports, in
     * dependency order (dependencies first) and de-duplicated.
     *
     * @param array<string, array<string, mixed>> $manifest
     * @return list<string>
     */
    private static function stylesheets(array $manifest, ?string $key): array {
        if ($key === null) {
            return [];
        }

        $css = [];
        self::collect($manifest, $key, $css, []);

        return array_values(array_unique($css));
    }

    /**
     * @param array<string, array<string, mixed>> $manifest
     * @param list<string> $css
     * @param list<string> $seen
     * @param-out list<string> $css
     */
    private static function collect(array $manifest, string $key, array &$css, array $seen): void {
        if (in_array($key, $seen, true) || !isset($manifest[$key])) {
            return;
        }
        $seen[] = $key;
        $chunk = $manifest[$key];

        // Depth first: a chunk's imports are its dependencies, so their styles
        // must come before its own for cascade order to match the module graph.
        if (isset($chunk['imports']) && is_array($chunk['imports'])) {
            foreach ($chunk['imports'] as $import) {
                if (is_string($import)) {
                    self::collect($manifest, $import, $css, $seen);
                }
            }
        }

        if (isset($chunk['css']) && is_array($chunk['css'])) {
            foreach ($chunk['css'] as $file) {
                if (is_string($file)) {
                    $css[] = $file;
                }
            }
        }
    }

    /**
     * Manifest keys are source paths (`src/admin.js`), so match on the chunk
     * name rather than guessing the path.
     *
     * @param array<string, array<string, mixed>> $manifest
     */
    private static function manifestKey(array $manifest, string $entry): ?string {
        foreach ($manifest as $key => $chunk) {
            if (($chunk['isEntry'] ?? false) === true && ($chunk['name'] ?? null) === $entry) {
                return $key;
            }
        }

        return null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function manifest(): array {
        if (self::$manifest !== null) {
            return self::$manifest;
        }

        $path = Server::get(IAppManager::class)->getAppPath(Application::APP_ID) . '/' . self::MANIFEST;
        $raw = is_readable($path) ? file_get_contents($path) : false;
        $decoded = $raw === false ? null : json_decode($raw, true);

        self::$manifest = is_array($decoded) ? $decoded : [];

        return self::$manifest;
    }
}
