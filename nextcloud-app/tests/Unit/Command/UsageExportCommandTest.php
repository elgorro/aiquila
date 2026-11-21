<?php

declare(strict_types=1);

namespace OCA\AIquila\Tests\Unit\Command;

use OCA\AIquila\Command\UsageExportCommand;
use OCA\AIquila\Service\UsageExport\UsageExportFilter;
use OCA\AIquila\Service\UsageExport\UsageExportService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * occ aiquila:usage:export: the admin export's filters, to stdout or a file.
 */
class UsageExportCommandTest extends TestCase {
    /** @var list<array{UsageExportFilter, list<string>, bool}> */
    private array $streamed = [];

    private function tester(): CommandTester {
        $service = $this->createStub(UsageExportService::class);
        $service->method('stream')->willReturnCallback(function (UsageExportFilter $f, array $userIds, bool $includeUser): \Generator {
            $this->streamed[] = [$f, $userIds, $includeUser];
            return (static function () {
                yield "a,b\r\n";
                yield "1,2\r\n";
            })();
        });
        $service->method('allUserIds')->willReturn(['alice', 'bob']);
        $service->method('groupUserIds')->willReturnCallback(static fn (string $gid): ?array => $gid === 'finance' ? ['carol'] : null);
        return new CommandTester(new UsageExportCommand($service, $this->createStub(ITimeFactory::class)));
    }

    public function testWritesEveryUsersRowsToStdout(): void {
        $tester = $this->tester();

        $this->assertSame(0, $tester->execute([]));
        $this->assertSame("a,b\r\n1,2\r\n", $tester->getDisplay());
        [$filter, $userIds, $includeUser] = $this->streamed[0];
        $this->assertSame(['alice', 'bob'], $userIds);
        $this->assertTrue($includeUser);
        $this->assertSame('csv', $filter->format);
        $this->assertSame('request', $filter->granularity);
    }

    public function testPassesEveryFilter(): void {
        $this->tester()->execute([
            '--format' => 'json', '--granularity' => 'week', '--from' => '2026-01-01', '--to' => '2026-03-31',
            '--model' => 'claude-opus-5', '--provider' => 'bedrock', '--type' => 'coworker', '--user' => 'bob',
        ]);

        [$filter, $userIds] = $this->streamed[0];
        $this->assertSame(['bob'], $userIds);
        $this->assertSame('json', $filter->format);
        $this->assertSame('week', $filter->granularity);
        $this->assertSame(gmmktime(0, 0, 0, 1, 1, 2026), $filter->from);
        $this->assertSame(gmmktime(0, 0, 0, 4, 1, 2026), $filter->to);
        $this->assertSame('claude-opus-5', $filter->model);
        $this->assertSame('bedrock', $filter->provider);
        $this->assertSame('coworker', $filter->requestType);
    }

    public function testFiltersByGroup(): void {
        $this->tester()->execute(['--group' => 'finance']);

        $this->assertSame(['carol'], $this->streamed[0][1]);
    }

    public function testWritesToAFile(): void {
        $file = tempnam(sys_get_temp_dir(), 'aiq-usage');
        try {
            $tester = $this->tester();
            $this->assertSame(0, $tester->execute(['--output' => $file]));
            $this->assertSame("a,b\r\n1,2\r\n", file_get_contents($file));
            $this->assertStringContainsString('Exported to', $tester->getDisplay());
        } finally {
            @unlink($file);
        }
    }

    /** @return array<string, array{array<string, string>, string}> */
    public static function failures(): array {
        return [
            'bad option' => [['--granularity' => 'hour'], 'granularity must be one of'],
            'user and group' => [['--user' => 'bob', '--group' => 'finance'], 'not both'],
            'unknown group' => [['--group' => 'nope'], 'not found'],
            'unwritable file' => [['--output' => '/nonexistent-dir/usage.csv'], 'Could not write'],
        ];
    }

    /** @param array<string, string> $input */
    #[\PHPUnit\Framework\Attributes\DataProvider('failures')]
    public function testFailsWithAMessage(array $input, string $message): void {
        $tester = $this->tester();

        $this->assertSame(1, $tester->execute($input));
        $this->assertStringContainsString($message, $tester->getDisplay());
    }
}
