<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service;

use OCA\AIquila\Db\UsageStat;
use OCA\AIquila\Db\UsageStatMapper;
use OCA\AIquila\Service\Provider\LLMProviderInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;

/**
 * Records the tokens one model call spent as a row in `aiquila_usage_stats`,
 * which the usage dashboard widget and the OpenMetrics token counter read.
 *
 * Callers hand over the provider's result array as it came back: its `usage`
 * block (input, output and cache tokens) and `model` are read from there, with
 * the provider's configured model as the fallback. A result that reports no
 * tokens, failed calls included, writes nothing.
 *
 * Best-effort: a failure here is logged and never fails the request that
 * spent the tokens.
 */
class UsageStatService {
    public const TYPE_CHAT = 'chat';
    public const TYPE_API = 'api';
    public const TYPE_TASK = 'task';
    public const TYPE_COWORKER = 'coworker';
    public const TYPE_TALK = 'talk';
    public const TYPE_FLOW = 'flow';

    // Column widths in Version0001Date20260218000000.
    private const MAX_USER_ID = 64;
    private const MAX_MODEL = 100;

    public function __construct(
        private UsageStatMapper $mapper,
        private ITimeFactory $time,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param ?string $userId The user the tokens were spent for; null (a Talk guest) is stored as ''
     * @param array<array-key, mixed> $result The provider's result, or just its `usage` under that key
     */
    public function record(?string $userId, LLMProviderInterface $provider, array $result, string $type, ?int $conversationId = null): void {
        try {
            $usage = is_array($result['usage'] ?? null) ? $result['usage'] : [];
            $input = self::tokens($usage['input_tokens'] ?? null);
            $output = self::tokens($usage['output_tokens'] ?? null);
            $cacheCreation = self::tokens($usage['cache_creation_tokens'] ?? null);
            $cacheRead = self::tokens($usage['cache_read_tokens'] ?? null);
            if ($input + $output + $cacheCreation + $cacheRead === 0) {
                return;
            }

            $model = is_string($result['model'] ?? null) && $result['model'] !== ''
                ? $result['model']
                : $provider->getModel($userId);

            $row = new UsageStat();
            $row->setUserId(mb_substr($userId ?? '', 0, self::MAX_USER_ID));
            $row->setModel(mb_substr($model, 0, self::MAX_MODEL));
            $row->setInputTokens($input);
            $row->setOutputTokens($output);
            $row->setCacheCreationTokens(isset($usage['cache_creation_tokens']) ? $cacheCreation : null);
            $row->setCacheReadTokens(isset($usage['cache_read_tokens']) ? $cacheRead : null);
            $row->setRequestType($type);
            $row->setConversationId($conversationId);
            $row->setCreatedAt($this->time->getTime());
            $this->mapper->insert($row);
        } catch (\Throwable $e) {
            $this->logger->warning('AIquila: could not record token usage', [
                'type' => $type,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private static function tokens(mixed $value): int {
        return is_int($value) || (is_string($value) && ctype_digit($value)) ? max(0, (int)$value) : 0;
    }
}
