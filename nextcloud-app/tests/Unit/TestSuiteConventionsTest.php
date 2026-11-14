<?php

declare(strict_types=1);

namespace OCA\AIquila\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Keeps the suite free of PHPUnit APIs that the next major turns into errors.
 *
 * PHPUnit 13 only soft-deprecates them, so `failOnPhpunitDeprecation` in
 * phpunit.xml does not catch a new use yet. Each entry names the replacement.
 */
class TestSuiteConventionsTest extends TestCase {
    /**
     * Method name => replacement. Matched as `->name(`, so a longer method
     * that starts with the same name (expectExceptionMessageIs) is not hit.
     */
    private const REMOVED_IN_NEXT_MAJOR = [
        // Hard-deprecated in PHPUnit 14 (sebastianbergmann/phpunit#6561).
        'expectExceptionMessage' => 'expectExceptionMessageIs() for an exact message, '
            . 'expectExceptionMessageIsOrContains() for a substring',
    ];

    public function testNoTestUsesAnApiTheNextPhpunitMajorRemoves(): void {
        $root = dirname(__DIR__);
        $offenders = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            /** @var \SplFileInfo $file */
            if ($file->getExtension() !== 'php' || $file->getRealPath() === __FILE__) {
                continue;
            }
            $lines = file($file->getPathname()) ?: [];
            foreach ($lines as $i => $line) {
                foreach (self::REMOVED_IN_NEXT_MAJOR as $method => $replacement) {
                    if (str_contains($line, '->' . $method . '(')) {
                        $offenders[] = sprintf('%s:%d uses %s(); use %s',
                            substr($file->getPathname(), strlen($root) + 1), $i + 1, $method, $replacement);
                    }
                }
            }
        }
        $this->assertSame([], $offenders, implode("\n", $offenders));
    }
}
