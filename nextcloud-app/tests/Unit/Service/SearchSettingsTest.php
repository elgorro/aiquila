<?php

declare(strict_types=1);

namespace OCA\AIquila\Tests\Unit\Service;

use OCA\AIquila\Service\SearchSettings;
use OCP\IConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SearchSettingsTest extends TestCase {
    /** @return array<string, array{?string, bool}> */
    public static function storedValues(): array {
        return [
            'unset' => [null, true],
            'on'    => ['1', true],
            'off'   => ['0', false],
        ];
    }

    #[DataProvider('storedValues')]
    public function testIsEnabled(?string $stored, bool $expected): void {
        $config = $this->createMock(IConfig::class);
        $config->expects($this->once())
            ->method('getAppValue')
            ->with('aiquila', 'search_enabled', SearchSettings::DEFAULT)
            ->willReturnCallback(fn (string $app, string $key, string $default): string => $stored ?? $default);

        $this->assertSame($expected, (new SearchSettings($config))->isEnabled());
    }

    public function testSetEnabledWritesOne(): void {
        $config = $this->createMock(IConfig::class);
        $config->expects($this->once())->method('setAppValue')->with('aiquila', 'search_enabled', '1');

        (new SearchSettings($config))->setEnabled(true);
    }

    public function testSetEnabledWritesZero(): void {
        $config = $this->createMock(IConfig::class);
        $config->expects($this->once())->method('setAppValue')->with('aiquila', 'search_enabled', '0');

        (new SearchSettings($config))->setEnabled(false);
    }
}
