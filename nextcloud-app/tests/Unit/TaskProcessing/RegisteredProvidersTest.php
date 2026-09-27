<?php

namespace OCA\AIquila\Tests\Unit\TaskProcessing;

use OCA\AIquila\TaskProcessing\ProviderRegistry;
use OCP\IL10N;
use OCP\L10N\IFactory;
use OCP\TaskProcessing\EShapeType;
use OCP\TaskProcessing\ISynchronousProvider;
use OCP\TaskProcessing\ITaskType;
use OCP\TaskProcessing\ShapeDescriptor;
use OCP\TaskProcessing\ShapeEnumValue;
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
        'core:text2text:improve' => '35.0.0',
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
        // Application::register() registers ProviderRegistry::PROVIDERS as-is;
        // AIquilaCapabilityTest pins that side.
        foreach ($this->providerClasses() as $class) {
            $this->assertContains(
                $class,
                ProviderRegistry::PROVIDERS,
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

    /**
     * An Enum slot with no values cannot be filled: core rejects every input
     * against an empty list, and the Assistant's form dereferences the first
     * value — throwing, and taking every task type down with it.
     */
    public function testEveryEnumInputHasValuesAndValidDefaults(): void {
        $taskTypes = $this->shippedTaskTypes();

        foreach (ProviderRegistry::PROVIDERS as $class) {
            $provider = $this->instantiate($class);
            $this->assertInstanceOf(ISynchronousProvider::class, $provider);
            $taskType = $taskTypes[$provider->getTaskTypeId()] ?? null;
            if ($taskType === null) {
                continue; // absent from this OCP; the test above covers unknown ids
            }

            $this->assertEnumSlotsFilled(
                $class,
                $taskType->getInputShape(),
                $provider->getInputShapeEnumValues(),
                $provider->getInputShapeDefaults(),
            );
            $this->assertEnumSlotsFilled(
                $class,
                // Optional shapes are not part of ITaskType; most types add them.
                (method_exists($taskType, 'getOptionalInputShape') ? $taskType->getOptionalInputShape() : [])
                    + $provider->getOptionalInputShape(),
                $provider->getOptionalInputShapeEnumValues(),
                $provider->getOptionalInputShapeDefaults(),
            );
        }
    }

    /**
     * @param array<string, ShapeDescriptor> $shape
     * @param array<string, list<ShapeEnumValue>> $enumValues
     * @param array<string, mixed> $defaults
     */
    private function assertEnumSlotsFilled(string $class, array $shape, array $enumValues, array $defaults): void {
        foreach ($shape as $key => $descriptor) {
            if ($descriptor->getShapeType() !== EShapeType::Enum) {
                continue;
            }
            $this->assertNotEmpty($enumValues[$key] ?? [], "{$class}: Enum slot \"{$key}\" has no values");
            $this->assertContainsOnlyInstancesOf(ShapeEnumValue::class, $enumValues[$key]);
            if (array_key_exists($key, $defaults)) {
                $this->assertContains(
                    $defaults[$key],
                    array_map(static fn (ShapeEnumValue $v) => $v->getValue(), $enumValues[$key]),
                    "{$class}: default for \"{$key}\" is not one of its enum values"
                );
            }
        }
    }

    /** @return array<string, ITaskType> task type id => instance */
    private function shippedTaskTypes(): array {
        $taskTypes = [];
        foreach (glob(__DIR__ . '/../../../vendor/nextcloud/ocp/OCP/TaskProcessing/TaskTypes/*.php') ?: [] as $path) {
            $class = 'OCP\\TaskProcessing\\TaskTypes\\' . basename($path, '.php');
            if (!class_exists($class) || !is_subclass_of($class, ITaskType::class)) {
                continue;
            }
            $taskType = $this->instantiate($class);
            $this->assertInstanceOf(ITaskType::class, $taskType);
            $taskTypes[$taskType->getId()] = $taskType;
        }
        $this->assertNotEmpty($taskTypes, 'Could not load the OCP task types');
        return $taskTypes;
    }

    /** Builds $class with a mock for every constructor argument. */
    private function instantiate(string $class): object {
        $l10n = $this->createStub(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);
        $l10nFactory = $this->createStub(IFactory::class);
        $l10nFactory->method('get')->willReturn($l10n);
        $l10nFactory->method('getLanguages')->willReturn([
            'commonLanguages' => [['code' => 'en', 'name' => 'English']],
            'otherLanguages' => [['code' => 'de', 'name' => 'Deutsch']],
        ]);

        $args = [];
        foreach ((new \ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $param) {
            $type = $param->getType();
            $this->assertInstanceOf(\ReflectionNamedType::class, $type, "{$class}: untyped constructor argument");
            $args[] = match ($type->getName()) {
                IL10N::class => $l10n,
                IFactory::class => $l10nFactory,
                default => $this->createStub($type->getName()),
            };
        }
        return new $class(...$args);
    }
}
