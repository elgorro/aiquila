<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service;

use OCA\AIquila\AppInfo\Application;

/**
 * Which OCC invocations `POST /api/occ` may run.
 *
 * Mirrors the MCP server's `run_occ` allowlist so the endpoint is not wider than
 * the tools that call it. An entry is a command followed by the exact arguments
 * it permits: `cmd` allows no arguments, `cmd a b` exactly `a b`, and a trailing
 * `*` any further arguments. The command name must match exactly, so Symfony's
 * command abbreviations (`maint:mode`) are refused.
 *
 * The `occ_allowlist` app config value (comma-separated entries) replaces the
 * default list.
 */
class OccAllowlist {
    public const KEY = 'occ_allowlist';

    /**
     * Read-only commands for `run_occ`, plus the commands the MCP server's
     * dedicated tools issue (`install_app`, `uninstall_app`, `aiquila_*`).
     */
    public const DEFAULT = [
        'status *',
        'app:list *',
        'setupchecks *',
        'integrity:check-core *',
        'integrity:check-app *',
        'user:list *',
        'user:info *',
        'group:list *',
        'update:check',
        'maintenance:mode',
        'theming:config',
        'app:install *',
        'app:remove *',
        'aiquila:configure *',
        'aiquila:test *',
    ];

    public function __construct(
        private readonly AppSettings $config,
    ) {
    }

    /** @return list<string> */
    public function getEntries(): array {
        $configured = trim($this->config->getAppValue(Application::APP_ID, self::KEY, ''));
        if ($configured === '') {
            return self::DEFAULT;
        }
        return array_values(array_filter(
            array_map('trim', explode(',', $configured)),
            static fn (string $entry): bool => $entry !== '',
        ));
    }

    /** @param list<string> $args */
    public function isAllowed(string $command, array $args): bool {
        foreach ($this->getEntries() as $entry) {
            if (self::matches($entry, $command, $args)) {
                return true;
            }
        }
        return false;
    }

    /** @param list<string> $args */
    public static function matches(string $entry, string $command, array $args): bool {
        $pattern = preg_split('/\s+/', $entry, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $name = array_shift($pattern);
        if ($name !== $command) {
            return false;
        }
        $wildcard = $pattern !== [] && $pattern[count($pattern) - 1] === '*';
        if ($wildcard) {
            array_pop($pattern);
            if (count($args) < count($pattern)) {
                return false;
            }
        } elseif (count($args) !== count($pattern)) {
            return false;
        }
        foreach ($pattern as $i => $arg) {
            if ($args[$i] !== $arg) {
                return false;
            }
        }
        return true;
    }
}
