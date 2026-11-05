<?php

namespace OCA\AIquila\Tests\Unit\Migration;

use OCA\AIquila\Migration\RepairHetznerBaseUrl;
use OCA\AIquila\Service\AppSettings;
use OCP\Migration\IOutput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RepairHetznerBaseUrlTest extends TestCase {
    private function repair(string $stored, bool $expectDelete): void {
        $settings = $this->createMock(AppSettings::class);
        $settings->method('getAppValue')->willReturn($stored);
        $settings->expects($expectDelete ? $this->once() : $this->never())
            ->method('deleteAppValue')
            ->with('aiquila', 'hetzner_base_url');

        (new RepairHetznerBaseUrl($settings))->run($this->createStub(IOutput::class));
    }

    /** @return array<string, array{string}> */
    public static function retiredValues(): array {
        return [
            'exact' => ['https://inference.hetzner.com/api/v1'],
            'trailing slash' => ['https://inference.hetzner.com/api/v1/'],
            'surrounding space' => [' https://inference.hetzner.com/api/v1 '],
        ];
    }

    #[DataProvider('retiredValues')]
    public function testRemovesTheRetiredEndpoint(string $stored): void {
        $this->repair($stored, true);
    }

    /** @return array<string, array{string}> */
    public static function otherValues(): array {
        return [
            'unset' => [''],
            'current endpoint' => ['https://inference.hetzner.com/v1'],
            'custom gateway' => ['https://gateway.example.com/api/v1'],
        ];
    }

    #[DataProvider('otherValues')]
    public function testLeavesEveryOtherValueAlone(string $stored): void {
        $this->repair($stored, false);
    }
}
