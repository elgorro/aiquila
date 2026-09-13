<?php

namespace OCA\AIquila\Tests\Unit\TaskProcessing;

use PHPUnit\Framework\TestCase;

/**
 * Guards the registration itself.
 *
 * A provider bound to a task type Nextcloud does not register is not an error
 * anywhere — Manager iterates over task types and asks each for a preferred
 * provider, so an unknown id simply means the provider is never reached. That
 * is how a provider for the invented id "core:image2text" sat in the app
 * doing nothing. This test makes the same mistake fail loudly instead.
 */
class RegisteredProvidersTest extends TestCase {
    /**
     * Task type ids that are real but whose OCP class is newer than the app's
     * declared min-version, so the provider spells the id out rather than
     * referencing a constant on a class that may be absent.
     */
    private const NEWER_THAN_MIN_VERSION = [
        'core:text2text:reformatparagraphs' => '34.0.0',
    ];

    /** @return list<string> */
    private function providerClasses(): array {
        $classes = [];
        foreach (glob(__DIR__ . '/../../../lib/TaskProcessing/*Provider.php') ?: [] as $path) {
            $name = basename($path, '.php');
            if ($name === 'ProviderResolver') {
                continue;
            }
            $classes[] = 'OCA\\AIquila\\TaskProcessing\\' . $name;
        }
        sort($classes);
        return $classes;
    }

    public function testEveryProviderIsRegisteredInApplication(): void {
        $application = file_get_contents(__DIR__ . '/../../../lib/AppInfo/Application.php');
        $this->assertIsString($application);

        foreach ($this->providerClasses() as $class) {
            $this->assertStringContainsString(
                'registerTaskProcessingProvider(\\' . $class . '::class)',
                $application,
                $class . ' exists but is never registered'
            );
        }
    }

    public function testEveryProviderTargetsATaskTypeNextcloudShips(): void {
        $shipped = [];
        foreach (glob(__DIR__ . '/../../../vendor/nextcloud/ocp/OCP/TaskProcessing/TaskTypes/*.php') ?: [] as $path) {
            $source = (string)file_get_contents($path);
            if (preg_match("/public const ID = '([^']+)'/", $source, $m) === 1) {
                $shipped[] = $m[1];
            }
        }
        $this->assertNotEmpty($shipped, 'Could not read the OCP task type list');

        foreach ($this->providerClasses() as $class) {
            $taskTypeId = (new \ReflectionClass($class))
                ->newInstanceWithoutConstructor()
                ->getTaskTypeId();

            $this->assertContains(
                $taskTypeId,
                $shipped,
                $class . ' targets "' . $taskTypeId . '", which no Nextcloud task type declares'
            );
        }
    }

    public function testOnlyKnownTaskTypesSkipTheOcpConstant(): void {
        foreach ($this->providerClasses() as $class) {
            $source = (string)file_get_contents((string)(new \ReflectionClass($class))->getFileName());
            if (preg_match("/public function getTaskTypeId\(\): string \{\s*return '([^']+)';/", $source, $m) !== 1) {
                continue;
            }
            $this->assertArrayHasKey(
                $m[1],
                self::NEWER_THAN_MIN_VERSION,
                $class . ' hardcodes "' . $m[1] . '" — use the OCP TaskTypes constant instead'
            );
        }
    }
}
