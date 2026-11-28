<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Fails when statement coverage in a PHPUnit clover report drops below the
 * recorded baseline. The baseline is a ratchet: raise it when a change raises
 * coverage. Policy: docs/dev/ci-cd.md#coverage.
 *
 * Usage: php tools/coverage-check.php <clover.xml> <baseline file>
 */

if ($argc !== 3) {
	fwrite(STDERR, "Usage: php tools/coverage-check.php <clover.xml> <baseline file>\n");
	exit(2);
}

$xml = @simplexml_load_file($argv[1]);
if ($xml === false || !isset($xml->project->metrics)) {
	fwrite(STDERR, "Cannot read a clover report from {$argv[1]}. Is pcov or Xdebug enabled?\n");
	exit(2);
}

$metrics = $xml->project->metrics;
$statements = (int)$metrics['statements'];
$covered = (int)$metrics['coveredstatements'];
if ($statements === 0) {
	fwrite(STDERR, "The clover report counts no statements. Is pcov or Xdebug enabled?\n");
	exit(2);
}

// Truncated to one decimal, like Go's and vitest's summaries.
$total = floor($covered / $statements * 1000) / 10;
$baseline = (float)trim((string)file_get_contents($argv[2]));
printf("Nextcloud app statement coverage: %.1f%% (%d/%d, baseline %.1f%%)\n", $total, $covered, $statements, $baseline);

$summary = getenv('GITHUB_STEP_SUMMARY');
if ($summary !== false && $summary !== '') {
	file_put_contents($summary, sprintf("**Nextcloud app coverage:** %.1f%% of statements (baseline %.1f%%)\n", $total, $baseline), FILE_APPEND);
}

if ($total < $baseline) {
	printf("::error::Nextcloud app coverage %.1f%% is below the baseline %.1f%%. Add tests for the code this change adds or touches.\n", $total, $baseline);
	exit(1);
}
if ($total >= $baseline + 1) {
	printf("::notice::Nextcloud app coverage rose to %.1f%%. Raise nextcloud-app/tests/coverage-baseline.txt to lock it in.\n", $total);
}
