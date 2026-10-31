<?php

namespace OCA\AIquila\Tests\Unit;

use Anthropic\Client;
use Anthropic\Messages\Message;
use OCA\AIquila\Service\AppSettings;
use OCA\AIquila\Service\ClaudeModels;
use OCA\AIquila\Service\ClaudeSDKService;
use OCA\AIquila\Service\CredentialService;
use OCA\AIquila\Service\Provider\SystemMessages;
use OCA\AIquila\Service\RequestMetadataService;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/** Records what each create call was sent; the first call can be made to fail. */
class SystemRoleTestableService extends ClaudeSDKService {
    /** @var list<array> */
    public array $sent = [];
    public ?\Throwable $failFirst = null;

    protected function callCreate(\Anthropic\Core\BaseClient $client, array $params): Message {
        $this->sent[] = $params;
        if ($this->failFirst !== null) {
            $e = $this->failFirst;
            $this->failFirst = null;
            throw $e;
        }
        throw new \DomainException('sent');
    }

    protected function getClient(?string $userId = null): Client {
        return (new \ReflectionClass(Client::class))->newInstanceWithoutConstructor();
    }

    public function send(array $params): void {
        $this->createMessage($this->getClient(), $params);
    }

    public function params(array $messages, array $options = []): array {
        $m = new \ReflectionMethod(ClaudeSDKService::class, 'buildRequestParams');
        return $m->invoke($this, $messages, null, $options);
    }
}

/**
 * Mid-conversation system messages: placed natively where the API accepts
 * them, folded into the user turn everywhere else.
 */
class SystemMessagesTest extends TestCase {
    private const NOTE = 'Today is Saturday, 31 October 2026.';

    private static function reminder(string $text): array {
        return ['type' => 'text', 'text' => "<system-reminder>\n" . $text . "\n</system-reminder>"];
    }

    public function testMessagesWithoutSystemRolePassThrough(): void {
        $messages = [['role' => 'user', 'content' => 'Hi']];
        $this->assertSame($messages, SystemMessages::prepare($messages, true));
        $this->assertSame($messages, SystemMessages::prepare($messages, false));
    }

    public function testNativeKeepsAMessageThatFollowsAUserTurn(): void {
        $messages = [
            ['role' => 'user', 'content' => 'Hi'],
            ['role' => 'system', 'content' => self::NOTE],
            ['role' => 'assistant', 'content' => 'Hello'],
            ['role' => 'user', 'content' => 'And now?'],
        ];
        $this->assertSame($messages, SystemMessages::prepare($messages, true));
    }

    public function testNativeAcceptsASystemMessageLast(): void {
        $messages = [['role' => 'user', 'content' => 'Hi'], ['role' => 'system', 'content' => self::NOTE]];
        $this->assertSame($messages, SystemMessages::prepare($messages, true));
    }

    public function testFoldAppendsAReminderToThePrecedingUserTurn(): void {
        $this->assertSame(
            [
                ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Hi'], self::reminder(self::NOTE)]],
                ['role' => 'assistant', 'content' => 'Hello'],
            ],
            SystemMessages::prepare([
                ['role' => 'user', 'content' => 'Hi'],
                ['role' => 'system', 'content' => self::NOTE],
                ['role' => 'assistant', 'content' => 'Hello'],
            ], false),
        );
    }

    public function testMisplacedMessageIsFoldedEvenWhenNative(): void {
        // Followed by a user turn (two user rows in a row): the API would 400.
        $out = SystemMessages::prepare([
            ['role' => 'user', 'content' => 'one'],
            ['role' => 'system', 'content' => self::NOTE],
            ['role' => 'user', 'content' => 'two'],
        ], true);

        $this->assertCount(2, $out);
        $this->assertSame([['type' => 'text', 'text' => 'one'], self::reminder(self::NOTE)], $out[0]['content']);
    }

    public function testMessageAfterAnAssistantTurnFoldsIntoTheNextUserTurn(): void {
        $out = SystemMessages::prepare([
            ['role' => 'user', 'content' => 'one'],
            ['role' => 'assistant', 'content' => 'reply'],
            ['role' => 'system', 'content' => self::NOTE],
            ['role' => 'user', 'content' => 'two'],
        ], true);

        $this->assertSame([self::reminder(self::NOTE), ['type' => 'text', 'text' => 'two']], $out[2]['content']);
    }

    public function testConsecutiveSystemMessagesArePlacedAsOne(): void {
        $out = SystemMessages::prepare([
            ['role' => 'user', 'content' => 'Hi'],
            ['role' => 'system', 'content' => 'A'],
            ['role' => 'system', 'content' => [['type' => 'text', 'text' => 'B']]],
            ['role' => 'assistant', 'content' => 'Hello'],
        ], true);

        $this->assertSame(['role' => 'system', 'content' => "A\n\nB"], $out[1]);
        $this->assertCount(3, $out);
    }

    public function testFoldKeepsToolResultsFirst(): void {
        $toolResult = ['type' => 'tool_result', 'tool_use_id' => 't1', 'content' => 'ok'];
        $out = SystemMessages::prepare([
            ['role' => 'user', 'content' => [$toolResult]],
            ['role' => 'system', 'content' => self::NOTE],
        ], false);

        $this->assertSame([$toolResult, self::reminder(self::NOTE)], $out[0]['content']);
    }

    public function testRejectionIsRecognised(): void {
        $this->assertTrue(SystemMessages::isRejection(new \RuntimeException("role 'system' is not supported on this model")));
        $this->assertFalse(SystemMessages::isRejection(new \RuntimeException('messages.1: roles must alternate')));
    }

    public function testModelTable(): void {
        $this->assertTrue(ClaudeModels::supportsMidConversationSystem(ClaudeModels::OPUS_4_8));
        $this->assertTrue(ClaudeModels::supportsMidConversationSystem(ClaudeModels::FABLE_5_1));
        $this->assertFalse(ClaudeModels::supportsMidConversationSystem(ClaudeModels::SONNET_5));
        $this->assertFalse(ClaudeModels::supportsMidConversationSystem(ClaudeModels::SONNET_4_6));
        // Platform-prefixed ids resolve to the same entry.
        $this->assertTrue(ClaudeModels::supportsMidConversationSystem('us.anthropic.claude-opus-4-8-v1'));
    }

    private function service(string $model): SystemRoleTestableService {
        $config = $this->createStub(AppSettings::class);
        $config->method('getUserValue')->willReturn('');
        $config->method('getAppValue')->willReturnCallback(fn ($app, $key, $default) => match ($key) {
            'model' => $model,
            'max_tokens' => '4096',
            default => $default,
        });
        $credentials = $this->createStub(CredentialService::class);
        $credentials->method('getApiKey')->willReturn('test-key');
        $cache = $this->createStub(ICache::class);
        $cache->method('get')->willReturn([
            'max_tokens' => 128000,
            'context_window' => 1000000,
            'supports_thinking' => false,
            'supports_thinking_enabled' => false,
            'supports_effort' => false,
        ]);
        $cacheFactory = $this->createStub(ICacheFactory::class);
        $cacheFactory->method('createDistributed')->willReturn($cache);

        return new SystemRoleTestableService(
            $config,
            $this->createStub(LoggerInterface::class),
            $credentials,
            $cacheFactory,
            $this->createStub(RequestMetadataService::class),
        );
    }

    public function testSupportedModelGetsTheRole(): void {
        $params = $this->service(ClaudeModels::OPUS_4_8)->params([
            ['role' => 'user', 'content' => 'Hi'],
            ['role' => 'system', 'content' => self::NOTE],
        ]);
        $this->assertSame('system', $params['messages'][1]['role']);
    }

    public function testUnsupportedModelGetsAReminder(): void {
        $params = $this->service(ClaudeModels::SONNET_5)->params([
            ['role' => 'user', 'content' => 'Hi'],
            ['role' => 'system', 'content' => self::NOTE],
        ]);
        $this->assertCount(1, $params['messages']);
        $this->assertSame(self::reminder(self::NOTE), $params['messages'][0]['content'][1]);
    }

    public function testRejectionIsRetriedWithTheMessagesFolded(): void {
        $service = $this->service(ClaudeModels::OPUS_4_8);
        $service->failFirst = new \RuntimeException("role 'system' is not supported on this model");
        $params = $service->params([
            ['role' => 'user', 'content' => 'Hi'],
            ['role' => 'system', 'content' => self::NOTE],
        ]);

        try {
            $service->send($params);
            $this->fail('expected the stub to stop after the retry');
        } catch (\DomainException) {
        }

        $this->assertCount(2, $service->sent);
        $this->assertSame('system', $service->sent[0]['messages'][1]['role']);
        $this->assertCount(1, $service->sent[1]['messages']);
    }

    public function testOtherErrorsAreNotRetried(): void {
        $service = $this->service(ClaudeModels::OPUS_4_8);
        $service->failFirst = new \RuntimeException('overloaded');

        $this->expectExceptionMessage('overloaded');
        try {
            $service->send($service->params([
                ['role' => 'user', 'content' => 'Hi'],
                ['role' => 'system', 'content' => self::NOTE],
            ]));
        } finally {
            $this->assertCount(1, $service->sent);
        }
    }
}
