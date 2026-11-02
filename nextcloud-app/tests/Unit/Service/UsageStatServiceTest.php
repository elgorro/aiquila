<?php

namespace OCA\AIquila\Tests\Unit\Service;

use OCA\AIquila\Db\UsageStat;
use OCA\AIquila\Db\UsageStatMapper;
use OCA\AIquila\Service\Provider\LLMProviderInterface;
use OCA\AIquila\Service\UsageStatService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The row a model call leaves in aiquila_usage_stats, which the usage widget
 * and the OpenMetrics token counter read.
 */
class UsageStatServiceTest extends TestCase {
    private const NOW = 1793489400;

    /** @var list<UsageStat> */
    private array $inserted = [];

    private function service(?UsageStatMapper $mapper = null): UsageStatService {
        if ($mapper === null) {
            $mapper = $this->createStub(UsageStatMapper::class);
            $mapper->method('insert')->willReturnCallback(function (UsageStat $row): UsageStat {
                $this->inserted[] = $row;
                return $row;
            });
        }
        $time = $this->createStub(ITimeFactory::class);
        $time->method('getTime')->willReturn(self::NOW);
        return new UsageStatService($mapper, $time, $this->createStub(LoggerInterface::class));
    }

    private function provider(string $model = 'configured-model'): LLMProviderInterface {
        $provider = $this->createStub(LLMProviderInterface::class);
        $provider->method('getModel')->willReturn($model);
        return $provider;
    }

    public function testRecordsEveryTokenColumn(): void {
        $this->service()->record('alice', $this->provider(), [
            'response' => 'hi',
            'model' => 'claude-opus-5',
            'usage' => ['input_tokens' => 120, 'output_tokens' => 30, 'cache_creation_tokens' => 400, 'cache_read_tokens' => 900],
        ], UsageStatService::TYPE_CHAT, 7);

        $this->assertCount(1, $this->inserted);
        $row = $this->inserted[0];
        $this->assertSame('alice', $row->getUserId());
        $this->assertSame('claude-opus-5', $row->getModel());
        $this->assertSame(120, $row->getInputTokens());
        $this->assertSame(30, $row->getOutputTokens());
        $this->assertSame(400, $row->getCacheCreationTokens());
        $this->assertSame(900, $row->getCacheReadTokens());
        $this->assertSame('chat', $row->getRequestType());
        $this->assertSame(7, $row->getConversationId());
        $this->assertSame(self::NOW, $row->getCreatedAt());
    }

    public function testFallsBackToTheProvidersModelAndLeavesUnreportedCacheNull(): void {
        $this->service()->record('alice', $this->provider('mistral-large-latest'), [
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ], UsageStatService::TYPE_TASK);

        $row = $this->inserted[0];
        $this->assertSame('mistral-large-latest', $row->getModel());
        $this->assertNull($row->getCacheCreationTokens());
        $this->assertNull($row->getCacheReadTokens());
        $this->assertNull($row->getConversationId());
    }

    public function testAGuestIsStoredWithoutAUserId(): void {
        $this->service()->record(null, $this->provider(), ['usage' => ['input_tokens' => 1, 'output_tokens' => 1]], UsageStatService::TYPE_TALK);

        $this->assertSame('', $this->inserted[0]->getUserId());
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function noTokens(): array {
        return [
            'no usage block' => [['response' => 'x']],
            'an error' => [['error' => 'rate limited']],
            'all zero' => [['usage' => ['input_tokens' => 0, 'output_tokens' => 0]]],
        ];
    }

    /** @dataProvider noTokens */
    #[\PHPUnit\Framework\Attributes\DataProvider('noTokens')]
    public function testWritesNothingWithoutTokens(array $result): void {
        $mapper = $this->createMock(UsageStatMapper::class);
        $mapper->expects($this->never())->method('insert');

        $this->service($mapper)->record('alice', $this->provider(), $result, UsageStatService::TYPE_API);
    }

    public function testADatabaseFailureNeverReachesTheCaller(): void {
        $mapper = $this->createMock(UsageStatMapper::class);
        $mapper->expects($this->once())->method('insert')->willThrowException(new \RuntimeException('db down'));

        $this->service($mapper)->record('alice', $this->provider(), ['usage' => ['input_tokens' => 1]], UsageStatService::TYPE_API);
    }
}
