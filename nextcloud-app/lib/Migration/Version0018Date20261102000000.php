<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Migration;

use Closure;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Backfills `aiquila_usage_stats` from the token counts already stored on
 * assistant messages.
 *
 * Nothing wrote usage rows before UsageStatService, so the usage widget and
 * the OpenMetrics token counter started from zero on every instance. Chat
 * history did keep per-message tokens; this turns each such message into a
 * `chat` row with the conversation's owner and model.
 *
 * Runs only while the table is empty, so it never double-counts rows the
 * service has written since. Usage of other request types (tasks, coworkers,
 * the API) was never stored anywhere and cannot be recovered.
 */
class Version0018Date20261102000000 extends SimpleMigrationStep {
    private const CHUNK = 1000;

    public function __construct(
        private readonly IDBConnection $db,
    ) {
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        $schema = $schemaClosure();
        if (!$schema->hasTable('aiquila_usage_stats') || !$schema->hasTable('aiquila_messages')
            || !$schema->hasTable('aiquila_conversations')) {
            return;
        }
        if ($this->hasUsageRows()) {
            return;
        }

        $copied = 0;
        $lastId = 0;
        do {
            $rows = $this->assistantMessagesAfter($lastId);
            if ($rows === []) {
                break;
            }
            $this->db->beginTransaction();
            try {
                foreach ($rows as $row) {
                    $lastId = (int)$row['id'];
                    $input = max(0, (int)($row['input_tokens'] ?? 0));
                    $outputTokens = max(0, (int)($row['output_tokens'] ?? 0));
                    $cacheCreation = $row['cache_creation_tokens'] !== null ? max(0, (int)$row['cache_creation_tokens']) : null;
                    $cacheRead = $row['cache_read_tokens'] !== null ? max(0, (int)$row['cache_read_tokens']) : null;
                    if ($input + $outputTokens + ($cacheCreation ?? 0) + ($cacheRead ?? 0) === 0) {
                        continue;
                    }
                    $insert = $this->db->getQueryBuilder();
                    $insert->insert('aiquila_usage_stats')->values([
                        'user_id' => $insert->createNamedParameter(mb_substr((string)$row['user_id'], 0, 64)),
                        'model' => $insert->createNamedParameter(mb_substr((string)$row['model'], 0, 100)),
                        'input_tokens' => $insert->createNamedParameter($input, IQueryBuilder::PARAM_INT),
                        'output_tokens' => $insert->createNamedParameter($outputTokens, IQueryBuilder::PARAM_INT),
                        'cache_creation_tokens' => $insert->createNamedParameter($cacheCreation, $cacheCreation === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_INT),
                        'cache_read_tokens' => $insert->createNamedParameter($cacheRead, $cacheRead === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_INT),
                        'request_type' => $insert->createNamedParameter('chat'),
                        'conversation_id' => $insert->createNamedParameter((int)$row['conversation_id'], IQueryBuilder::PARAM_INT),
                        'created_at' => $insert->createNamedParameter((int)$row['created_at'], IQueryBuilder::PARAM_INT),
                    ])->executeStatement();
                    $copied++;
                }
                $this->db->commit();
            } catch (\Throwable $e) {
                $this->db->rollBack();
                throw $e;
            }
        } while (count($rows) === self::CHUNK);

        if ($copied > 0) {
            $output->info(sprintf('AIquila: backfilled %d usage rows from chat history', $copied));
        }
    }

    private function hasUsageRows(): bool {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('id')->from('aiquila_usage_stats')->setMaxResults(1)->executeQuery();
        $found = $result->fetch() !== false;
        $result->closeCursor();
        return $found;
    }

    /** @return list<array<string, mixed>> */
    private function assistantMessagesAfter(int $lastId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('m.id', 'm.conversation_id', 'm.input_tokens', 'm.output_tokens', 'm.cache_creation_tokens', 'm.cache_read_tokens', 'm.created_at', 'c.user_id', 'c.model')
            ->from('aiquila_messages', 'm')
            ->innerJoin('m', 'aiquila_conversations', 'c', $qb->expr()->eq('m.conversation_id', 'c.id'))
            ->where($qb->expr()->eq('m.role', $qb->createNamedParameter('assistant')))
            ->andWhere($qb->expr()->gt('m.id', $qb->createNamedParameter($lastId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->orX(
                $qb->expr()->isNotNull('m.input_tokens'),
                $qb->expr()->isNotNull('m.output_tokens'),
            ))
            ->orderBy('m.id', 'ASC')
            ->setMaxResults(self::CHUNK);
        $result = $qb->executeQuery();
        $rows = [];
        while ($row = $result->fetch()) {
            $rows[] = $row;
        }
        $result->closeCursor();
        return $rows;
    }
}
