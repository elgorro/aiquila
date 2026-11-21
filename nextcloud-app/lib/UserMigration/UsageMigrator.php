<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\UserMigration;

use OCA\AIquila\Db\UsageStat;
use OCA\AIquila\Db\UsageStatMapper;
use OCA\AIquila\Service\UsageExport\UsageExportFilter;
use OCA\AIquila\Service\UsageExport\UsageExportService;
use OCA\AIquila\Service\UsageStatService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use OCP\IUser;
use OCP\UserMigration\IExportDestination;
use OCP\UserMigration\IImportSource;
use OCP\UserMigration\IMigrator;
use OCP\UserMigration\ISizeEstimationMigrator;
use OCP\UserMigration\TMigratorBasicVersionHandling;
use OCP\UserMigration\UserMigrationException;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Puts a user's token usage into Nextcloud's user data export (the
 * user_migration app) and reads it back on import.
 *
 * The file is the personal JSON export at request granularity, so what a
 * user downloads from their settings and what travels with their account are
 * the same document. Conversation ids are instance-local and are dropped on
 * import.
 */
class UsageMigrator implements IMigrator, ISizeEstimationMigrator {
    use TMigratorBasicVersionHandling;

    private const PATH = 'aiquila/usage.json';
    /** Rough size of one JSON row, for the export size estimate. */
    private const ROW_BYTES = 260;

    public function __construct(
        private UsageExportService $export,
        private UsageStatMapper $mapper,
        private ITimeFactory $time,
        private IL10N $l10n,
    ) {
    }

    public function getId(): string {
        return 'aiquila_usage';
    }

    public function getDisplayName(): string {
        return $this->l10n->t('AIquila token usage');
    }

    public function getDescription(): string {
        return $this->l10n->t('The tokens your AIquila requests used, one entry per request.');
    }

    public function getEstimatedExportSize(IUser $user): int|float {
        // In KiB, per ISizeEstimationMigrator.
        return ceil($this->mapper->countByUser($user->getUID()) * self::ROW_BYTES / 1024);
    }

    public function export(IUser $user, IExportDestination $exportDestination, OutputInterface $output): void {
        $output->writeln('Exporting AIquila token usage…');
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new UserMigrationException('Could not open a buffer for the AIquila usage export');
        }
        try {
            $filter = UsageExportFilter::fromInput(UsageExportFilter::FORMAT_JSON);
            foreach ($this->export->stream($filter, [$user->getUID()], false, $this->time->getTime()) as $chunk) {
                fwrite($stream, $chunk);
            }
            rewind($stream);
            $exportDestination->addFileAsStream(self::PATH, $stream);
        } catch (UserMigrationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new UserMigrationException('Could not export AIquila token usage', 0, $e);
        } finally {
            fclose($stream);
        }
    }

    public function import(IUser $user, IImportSource $importSource, OutputInterface $output): void {
        if ($importSource->getMigratorVersion($this->getId()) === null || !$importSource->pathExists(self::PATH)) {
            $output->writeln('No AIquila token usage to import…');
            return;
        }
        $output->writeln('Importing AIquila token usage…');

        try {
            $data = json_decode($importSource->getFileContents(self::PATH), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new UserMigrationException('The AIquila usage file is not valid JSON', 0, $e);
        }
        if (!is_array($data) || !is_array($data['rows'] ?? null)) {
            throw new UserMigrationException('The AIquila usage file has no rows');
        }

        $imported = 0;
        foreach ($data['rows'] as $row) {
            $stat = is_array($row) ? $this->entity($user->getUID(), $row) : null;
            if ($stat !== null) {
                $this->mapper->insert($stat);
                $imported++;
            }
        }
        $output->writeln(sprintf('Imported %d AIquila usage row(s)', $imported));
    }

    /**
     * One exported row back as an entity, or null when it is not one we wrote.
     *
     * @param array<array-key, mixed> $row
     */
    private function entity(string $userId, array $row): ?UsageStat {
        $time = is_string($row['timestamp'] ?? null) ? strtotime($row['timestamp']) : false;
        $model = $row['model'] ?? null;
        $type = $row['request_type'] ?? null;
        if ($time === false || !is_string($model) || !in_array($type, UsageStatService::TYPES, true)) {
            return null;
        }
        $provider = $row['provider'] ?? null;

        $stat = new UsageStat();
        $stat->setUserId($userId);
        $stat->setModel(mb_substr($model, 0, 100));
        $stat->setProvider(is_string($provider) && $provider !== '' ? mb_substr($provider, 0, 32) : null);
        $stat->setRequestType($type);
        $stat->setInputTokens(self::tokens($row['input_tokens'] ?? 0) ?? 0);
        $stat->setOutputTokens(self::tokens($row['output_tokens'] ?? 0) ?? 0);
        $stat->setCacheCreationTokens(self::tokens($row['cache_creation_tokens'] ?? null));
        $stat->setCacheReadTokens(self::tokens($row['cache_read_tokens'] ?? null));
        $stat->setCreatedAt($time);
        return $stat;
    }

    private static function tokens(mixed $value): ?int {
        return is_int($value) ? max(0, $value) : null;
    }
}
