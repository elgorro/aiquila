<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service\Provider;

/**
 * Parser and validator for admin-supplied static request headers.
 *
 * Self-hosted model endpoints are commonly fronted by a proxy that wants extra
 * headers — Cloudflare Access sends a `CF-Access-Client-Id` /
 * `CF-Access-Client-Secret` pair, API gateways want a tenant or routing header.
 * Admins write them as one `Name: value` per line.
 *
 * The same code runs on both sides of storage: once when the value is saved, so
 * a mistake is a readable 400 rather than a cURL error hours later, and again
 * when it is read back, because a stored value is not trusted input — it may
 * predate a tightening of these rules, or have been written straight into the
 * credential store by hand.
 *
 * ## What is refused, and why
 *
 * - CR/LF anywhere. A newline in a header value is header injection: it lets
 *   the rest of the request be rewritten, and this provider talks to an
 *   endpoint that has the SSRF guard disabled.
 * - Hop-by-hop headers (RFC 9110 §7.6.1) plus `Content-Length`. These describe
 *   the single connection, not the message; setting them by hand desynchronises
 *   the client from what it actually sends.
 * - `Content-Type` and `Authorization`. Both are owned by the provider — the
 *   first is always JSON, the second is what `local_auth_mode` exists to
 *   configure. Silently letting an extra header win over the configured auth
 *   scheme would make the mode setting a lie.
 *
 * `Host` is deliberately allowed: the URL already decides which address is
 * contacted, so overriding it only selects a vhost at that address and adds no
 * reach.
 */
final class HeaderSpec {
    /** A field-name token, kept to the conservative subset proxies accept. */
    private const NAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9-]{0,63}$/';

    /** Lower-cased names an admin may not set. See the class docblock. */
    private const FORBIDDEN = [
        'content-type',
        'content-length',
        'authorization',
        'connection',
        'keep-alive',
        'proxy-authenticate',
        'proxy-authorization',
        'te',
        'trailer',
        'transfer-encoding',
        'upgrade',
    ];

    /** True when $name is a usable header name (no forbidden-list check). */
    public static function isValidName(string $name): bool {
        return preg_match(self::NAME_PATTERN, $name) === 1;
    }

    /**
     * Validate a single header name an admin may set, e.g. the custom auth
     * header. Returns the name unchanged.
     *
     * @throws \InvalidArgumentException
     */
    public static function requireName(string $name): string {
        if (!self::isValidName($name)) {
            throw new \InvalidArgumentException(
                '"' . $name . '" is not a valid header name. Use letters, digits and hyphens, for example X-API-Key.'
            );
        }
        if (in_array(strtolower($name), self::FORBIDDEN, true)) {
            throw new \InvalidArgumentException(
                'The "' . $name . '" header is set by AIquila itself and cannot be overridden.'
            );
        }
        return $name;
    }

    /**
     * Parse a `Name: value` block into a header map. Blank lines and `#`
     * comments are skipped so an admin can annotate the block.
     *
     * @return array<string, string>
     * @throws \InvalidArgumentException on a malformed or forbidden entry
     */
    public static function parse(string $raw): array {
        $headers = [];
        foreach (preg_split('/\R/', $raw) ?: [] as $index => $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $at = ' (line ' . ($index + 1) . ')';

            $split = strpos($line, ':');
            if ($split === false || $split === 0) {
                throw new \InvalidArgumentException(
                    'Expected "Name: value"' . $at . ', got "' . $line . '".'
                );
            }

            $name = self::requireNameAt(trim(substr($line, 0, $split)), $at);
            $value = trim(substr($line, $split + 1));
            if ($value === '') {
                throw new \InvalidArgumentException('The "' . $name . '" header has no value' . $at . '.');
            }
            // Redundant after the line split, but this method is also the guard
            // for values that reach it any other way.
            if (preg_match('/[\r\n]/', $value) === 1) {
                throw new \InvalidArgumentException('The "' . $name . '" header value contains a line break' . $at . '.');
            }
            if (isset($headers[$name])) {
                throw new \InvalidArgumentException('The "' . $name . '" header is set twice' . $at . '.');
            }

            $headers[$name] = $value;
        }

        return $headers;
    }

    /** @throws \InvalidArgumentException */
    private static function requireNameAt(string $name, string $at): string {
        try {
            return self::requireName($name);
        } catch (\InvalidArgumentException $e) {
            throw new \InvalidArgumentException(rtrim($e->getMessage(), '.') . $at . '.');
        }
    }
}
