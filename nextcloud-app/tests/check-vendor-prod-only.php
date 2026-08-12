#!/usr/bin/env php
<?php
/**
 * Guard against dev dependencies leaking into the packaged app.
 *
 * The release tarball ships vendor/ verbatim. Anything installed there that is not a
 * production dependency (symfony/console, amphp/amp, doctrine/dbal, …) collides with
 * Nextcloud core and other apps at runtime — see GH #453.
 *
 * Usage: php tests/check-vendor-prod-only.php [path-to-app-root]
 */

declare(strict_types=1);

$root = rtrim($argv[1] ?? dirname(__DIR__), '/');
$lockFile = $root . '/composer.lock';
$vendorDir = $root . '/vendor';

if (!is_file($lockFile)) {
	fwrite(STDERR, "ERROR: composer.lock not found at {$lockFile}\n");
	exit(1);
}
if (!is_dir($vendorDir)) {
	fwrite(STDERR, "ERROR: vendor/ not found at {$vendorDir}\n");
	exit(1);
}

$lock = json_decode((string)file_get_contents($lockFile), true, 512, JSON_THROW_ON_ERROR);
$allowed = array_column($lock['packages'] ?? [], 'name');

// composer's own bookkeeping dirs are not packages.
$ignored = ['composer', 'bin'];

$found = [];
$unexpected = [];
foreach (scandir($vendorDir) ?: [] as $ns) {
	if ($ns === '.' || $ns === '..' || in_array($ns, $ignored, true)) {
		continue;
	}
	$nsPath = $vendorDir . '/' . $ns;
	if (!is_dir($nsPath)) {
		continue;
	}
	foreach (scandir($nsPath) ?: [] as $pkg) {
		if ($pkg === '.' || $pkg === '..' || !is_dir($nsPath . '/' . $pkg)) {
			continue;
		}
		$name = $ns . '/' . $pkg;
		$found[] = $name;
		if (!in_array($name, $allowed, true)) {
			$unexpected[] = $name;
		}
	}
}

if ($unexpected !== []) {
	sort($unexpected);
	fwrite(STDERR, "ERROR: vendor/ contains " . count($unexpected) . " package(s) that are not production dependencies:\n");
	foreach ($unexpected as $name) {
		fwrite(STDERR, "  - {$name}\n");
	}
	fwrite(STDERR, "\nRun: rm -rf vendor && composer install --no-dev --optimize-autoloader\n");
	exit(1);
}

$missing = array_diff($allowed, $found);
if ($missing !== []) {
	fwrite(STDERR, "ERROR: vendor/ is missing production package(s): " . implode(', ', $missing) . "\n");
	exit(1);
}

echo 'OK: vendor/ is production-only (' . count($found) . " packages)\n";
