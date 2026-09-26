<?php

declare(strict_types=1);

namespace OCA\AIquila\Tests\Unit\Settings;

use OCA\AIquila\Service\SearchSettings;
use OCA\AIquila\Settings\AdminSettings;
use OCP\IConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AdminSettingsTest extends TestCase {
    /** @return array<string, array{?string, bool}> */
    public static function storedValues(): array {
        return [
            'unset' => [null, true],
            'on'    => ['1', true],
            'off'   => ['0', false],
        ];
    }

    #[DataProvider('storedValues')]
    public function testSearchToggleMatchesStoredValue(?string $stored, bool $expected): void {
        $config = $this->createStub(IConfig::class);
        $config->method('getAppValue')->willReturnCallback(
            fn (string $app, string $key, string $default): string =>
                $key === SearchSettings::KEY ? ($stored ?? $default) : $default
        );

        $settings = new AdminSettings($config, new SearchSettings($config));

        $this->assertSame($expected, $settings->getForm()->getParams()['search_enabled']);
    }
}
