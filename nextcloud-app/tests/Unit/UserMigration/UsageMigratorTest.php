<?php

declare(strict_types=1);

namespace OCA\AIquila\Tests\Unit\UserMigration;

use OCA\AIquila\Db\UsageStat;
use OCA\AIquila\Db\UsageStatMapper;
use OCA\AIquila\Service\UsageExport\UsageExportFilter;
use OCA\AIquila\Service\UsageExport\UsageExportService;
use OCA\AIquila\UserMigration\UsageMigrator;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUser;
use OCP\UserMigration\IExportDestination;
use OCP\UserMigration\IImportSource;
use OCP\UserMigration\UserMigrationException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\NullOutput;

/**
 * A user's token usage in Nextcloud's user data export, and back.
 */
class UsageMigratorTest extends TestCase {
    /** @var list<UsageStat> */
    private array $inserted = [];

    private function migrator(): UsageMigrator {
        $mapper = $this->createStub(UsageStatMapper::class);
        $mapper->method('exportRequestPage')->willReturnCallback(
            static fn (string $userId, UsageExportFilter $f, int $afterId): array => $afterId > 0 || $userId !== 'alice' ? [] : [
                ['id' => 1, 'created_at' => 1767225600, 'provider' => 'anthropic', 'model' => 'claude-opus-5', 'request_type' => 'chat',
                    'conversation_id' => 7, 'input_tokens' => 120, 'output_tokens' => 30, 'cache_creation_tokens' => null, 'cache_read_tokens' => 900],
                ['id' => 2, 'created_at' => 1767229200, 'provider' => null, 'model' => 'mistral-large', 'request_type' => 'task',
                    'conversation_id' => null, 'input_tokens' => 5, 'output_tokens' => 6, 'cache_creation_tokens' => null, 'cache_read_tokens' => null],
            ],
        );
        $mapper->method('insert')->willReturnCallback(function (UsageStat $row): UsageStat {
            $this->inserted[] = $row;
            return $row;
        });
        $mapper->method('countByUser')->willReturn(4000);
        $l10n = $this->createStub(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);

        return new UsageMigrator(
            new UsageExportService($mapper, $this->createStub(IGroupManager::class)),
            $mapper,
            $this->createStub(ITimeFactory::class),
            $l10n,
        );
    }

    private function user(string $uid): IUser {
        $user = $this->createStub(IUser::class);
        $user->method('getUID')->willReturn($uid);
        return $user;
    }

    private function exported(): string {
        $files = [];
        $destination = $this->createStub(IExportDestination::class);
        $destination->method('addFileAsStream')->willReturnCallback(static function (string $path, $stream) use (&$files): void {
            $files[$path] = stream_get_contents($stream);
        });
        $this->migrator()->export($this->user('alice'), $destination, new NullOutput());
        $this->assertSame(['aiquila/usage.json'], array_keys($files));
        return $files['aiquila/usage.json'];
    }

    public function testExportsThePersonalJsonExport(): void {
        $doc = json_decode($this->exported(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('request', $doc['granularity']);
        $this->assertCount(2, $doc['rows']);
        $this->assertArrayNotHasKey('user', $doc['rows'][0]);
        $this->assertSame('2026-01-01T00:00:00Z', $doc['rows'][0]['timestamp']);
    }

    public function testImportRestoresTheRowsUnderTheNewAccount(): void {
        $json = $this->exported();
        $source = $this->createStub(IImportSource::class);
        $source->method('getMigratorVersion')->willReturn(1);
        $source->method('pathExists')->willReturn(true);
        $source->method('getFileContents')->willReturn($json);

        $this->migrator()->import($this->user('alice2'), $source, new NullOutput());

        $this->assertCount(2, $this->inserted);
        [$first, $second] = $this->inserted;
        $this->assertSame('alice2', $first->getUserId());
        $this->assertSame(1767225600, $first->getCreatedAt());
        $this->assertSame('anthropic', $first->getProvider());
        $this->assertSame('claude-opus-5', $first->getModel());
        $this->assertSame('chat', $first->getRequestType());
        $this->assertSame(120, $first->getInputTokens());
        $this->assertSame(30, $first->getOutputTokens());
        $this->assertNull($first->getCacheCreationTokens());
        $this->assertSame(900, $first->getCacheReadTokens());
        // Conversation ids belong to the old instance.
        $this->assertNull($first->getConversationId());
        $this->assertNull($second->getProvider());
    }

    public function testImportSkipsRowsItDidNotWrite(): void {
        $source = $this->createStub(IImportSource::class);
        $source->method('getMigratorVersion')->willReturn(1);
        $source->method('pathExists')->willReturn(true);
        $source->method('getFileContents')->willReturn(json_encode(['rows' => [
            ['timestamp' => 'garbage', 'model' => 'm', 'request_type' => 'chat'],
            ['timestamp' => '2026-01-01T00:00:00Z', 'model' => 'm', 'request_type' => 'unknown'],
            'not a row',
            ['timestamp' => '2026-01-01T00:00:00Z', 'model' => 'm', 'request_type' => 'api', 'input_tokens' => -4],
        ]]));

        $this->migrator()->import($this->user('alice'), $source, new NullOutput());

        $this->assertCount(1, $this->inserted);
        $this->assertSame(0, $this->inserted[0]->getInputTokens());
    }

    public function testImportWithoutAnExportFromThisMigratorDoesNothing(): void {
        $source = $this->createStub(IImportSource::class);
        $source->method('getMigratorVersion')->willReturn(null);

        $this->migrator()->import($this->user('alice'), $source, new NullOutput());

        $this->assertSame([], $this->inserted);
    }

    public function testImportRejectsAFileThatIsNotJson(): void {
        $source = $this->createStub(IImportSource::class);
        $source->method('getMigratorVersion')->willReturn(1);
        $source->method('pathExists')->willReturn(true);
        $source->method('getFileContents')->willReturn('{');

        $this->expectException(UserMigrationException::class);
        $this->migrator()->import($this->user('alice'), $source, new NullOutput());
    }

    public function testEstimatesTheSizeInKibibytes(): void {
        $this->assertSame(1016.0, $this->migrator()->getEstimatedExportSize($this->user('alice')));
    }
}
