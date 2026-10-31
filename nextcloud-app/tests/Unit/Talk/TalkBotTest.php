<?php

namespace OCA\AIquila\Tests\Unit\Talk;

use OCA\AIquila\BackgroundJob\TalkBotReplyJob;
use OCA\AIquila\Db\TalkMessage;
use OCA\AIquila\Db\TalkMessageMapper;
use OCA\AIquila\Service\AppSettings;
use OCA\AIquila\Service\CredentialService;
use OCA\AIquila\Service\Provider\LLMProviderFactory;
use OCA\AIquila\Service\Provider\LLMProviderInterface;
use OCA\AIquila\Talk\TalkBotService;
use OCA\Talk\Events\BotInstallEvent;
use OCA\Talk\Events\BotInvokeEvent;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IURLGenerator;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The AIquila bot in Talk: what it reacts to, what it stores, how it answers.
 */
class TalkBotTest extends TestCase {
    private $credentials;
    private $messages;
    private $dispatcher;
    private $jobList;
    private $clientService;
    private ?AppSettings $config = null;

    protected function setUp(): void {
        $this->credentials = $this->createStub(CredentialService::class);
        $this->messages = $this->createStub(TalkMessageMapper::class);
        $this->dispatcher = $this->createStub(IEventDispatcher::class);
        $this->jobList = $this->createStub(IJobList::class);
        $this->clientService = $this->createStub(IClientService::class);
    }

    private function defaultConfig(): AppSettings {
        $config = $this->createStub(AppSettings::class);
        $config->method('getAppValue')->willReturnArgument(2);
        return $config;
    }

    private function service(): TalkBotService {
        $apps = $this->createStub(IAppManager::class);
        $apps->method('isEnabledForAnyone')->willReturn(true);
        $random = $this->createStub(ISecureRandom::class);
        $random->method('generate')->willReturn(str_repeat('s', 64));
        $url = $this->createStub(IURLGenerator::class);
        $url->method('getAbsoluteURL')->willReturnCallback(fn (string $p) => 'https://cloud.example' . $p);
        return new TalkBotService(
            $this->credentials,
            $this->messages,
            $this->dispatcher,
            $this->jobList,
            $this->clientService,
            $url,
            $random,
            $this->createStub(ITimeFactory::class),
            $apps,
            $this->config ?? $this->defaultConfig(),
            $this->createStub(LoggerInterface::class),
        );
    }

    private static function payload(string $message, array $parameters = [], ?string $replyToActor = null): array {
        $object = [
            'type' => 'Note',
            'id' => '17',
            'name' => 'message',
            'content' => json_encode(['message' => $message, 'parameters' => $parameters]),
            'mediaType' => 'text/markdown',
        ];
        if ($replyToActor !== null) {
            $object['inReplyTo'] = ['type' => 'Note', 'actor' => ['type' => 'Person', 'id' => $replyToActor]];
        }
        return [
            'type' => 'Create',
            'actor' => ['type' => 'Person', 'id' => 'users/alice', 'name' => 'Alice'],
            'object' => $object,
            'target' => ['type' => 'Collection', 'id' => 'abc123', 'name' => 'Team'],
        ];
    }

    public function testMentionsAreRenderedByName(): void {
        $content = json_encode([
            'message' => 'Ping {mention-user1} about {file0}',
            'parameters' => [
                'mention-user1' => ['type' => 'user', 'id' => 'bob', 'name' => 'Bob'],
                'file0' => ['type' => 'file', 'id' => '5', 'name' => 'plan.pdf'],
            ],
        ]);
        $this->assertSame('Ping @Bob about plan.pdf', TalkBotService::renderMessage((string)$content));
    }

    public function testAddressedByMentionOrByReplyingToTheBot(): void {
        $this->assertTrue(TalkBotService::isAddressed('@AIquila what is due today?', []));
        $this->assertTrue(TalkBotService::isAddressed('hey @aiquila, help', []));
        $this->assertFalse(TalkBotService::isAddressed('email me at me@aiquila.example', []));
        $this->assertFalse(TalkBotService::isAddressed('lunch?', []));
        $this->assertTrue(TalkBotService::isAddressed('and tomorrow?', self::payload('x', [], 'bots/bot-abc')));
        $this->assertFalse(TalkBotService::isAddressed('and tomorrow?', self::payload('x', [], 'users/bob')));
    }

    public function testEveryMessageIsStoredButOnlyAnAddressedOneQueuesAReply(): void {
        $this->messages = $this->createMock(TalkMessageMapper::class);
        $this->messages->expects($this->exactly(2))->method('insert')->willReturnArgument(0);
        $this->jobList = $this->createMock(IJobList::class);
        $this->jobList->expects($this->once())->method('add')->with(TalkBotReplyJob::class, [
            'token' => 'abc123',
            'messageId' => 17,
            'userId' => 'alice',
            'roomName' => 'Team',
        ]);

        $service = $this->service();
        $quiet = new BotInvokeEvent(TalkBotService::URL, self::payload('lunch?'));
        $service->handleInvoke($quiet);
        $asked = new BotInvokeEvent(TalkBotService::URL, self::payload('@AIquila summarize please'));
        $service->handleInvoke($asked);

        $this->assertSame([], $quiet->getReactions());
        $this->assertSame(['👀'], $asked->getReactions());
    }

    public function testOtherBotsAndNonMessagesAreIgnored(): void {
        $this->messages = $this->createMock(TalkMessageMapper::class);
        $this->messages->expects($this->never())->method('insert');

        $this->service()->handleInvoke(new BotInvokeEvent('nextcloudapp://other', self::payload('@AIquila hi')));
        $this->service()->handleInvoke(new BotInvokeEvent(TalkBotService::URL, ['type' => 'Join'] + self::payload('x')));
    }

    public function testInstallReusesTheStoredSecret(): void {
        $this->credentials = $this->createMock(CredentialService::class);
        $this->credentials->method('getSecret')->willReturn(str_repeat('k', 64));
        $this->credentials->expects($this->never())->method('setSecret');
        $this->dispatcher = $this->createMock(IEventDispatcher::class);
        $this->dispatcher->expects($this->once())->method('dispatchTyped')->with($this->callback(
            fn ($e) => $e instanceof BotInstallEvent && $e->getUrl() === 'nextcloudapp://aiquila' && $e->getFeatures() === 6
        ));

        $this->service()->install();
    }

    public function testReplyIsSignedAsTalkExpects(): void {
        $secret = str_repeat('k', 64);
        $this->credentials->method('getSecret')->willReturn($secret);
        $response = $this->createStub(IResponse::class);
        $response->method('getStatusCode')->willReturn(201);
        $client = $this->createMock(IClient::class);
        $client->expects($this->once())->method('post')
            ->with('https://cloud.example/ocs/v2.php/apps/spreed/api/v1/bot/abc123/message', $this->callback(function (array $options) use ($secret): bool {
                $random = $options['headers']['X-Nextcloud-Talk-Bot-Random'];
                return strlen($random) >= 32
                    && $options['headers']['X-Nextcloud-Talk-Bot-Signature'] === hash_hmac('sha256', $random . 'Hello', $secret)
                    && json_decode($options['body'], true) === ['message' => 'Hello', 'replyTo' => 17]
                    && $options['nextcloud']['allow_local_address'] === true;
            }))
            ->willReturn($response);
        $this->clientService->method('newClient')->willReturn($client);

        $this->service()->postReply('abc123', 'Hello', 17);
    }

    public function testReplyUsesTheConfiguredBaseUrl(): void {
        $this->credentials->method('getSecret')->willReturn('k');
        $this->config = $this->createStub(AppSettings::class);
        $this->config->method('getAppValue')->willReturn('http://nextcloud/');
        $response = $this->createStub(IResponse::class);
        $response->method('getStatusCode')->willReturn(201);
        $client = $this->createMock(IClient::class);
        $client->expects($this->once())->method('post')
            ->with('http://nextcloud/ocs/v2.php/apps/spreed/api/v1/bot/abc123/message', $this->anything())
            ->willReturn($response);
        $this->clientService->method('newClient')->willReturn($client);

        $this->service()->postReply('abc123', 'Hello', 17);
    }

    public function testRejectedReplyThrows(): void {
        $this->credentials->method('getSecret')->willReturn('k');
        $response = $this->createStub(IResponse::class);
        $response->method('getStatusCode')->willReturn(401);
        $client = $this->createStub(IClient::class);
        $client->method('post')->willReturn($response);
        $this->clientService->method('newClient')->willReturn($client);

        $this->expectExceptionMessage('HTTP 401');
        $this->service()->postReply('abc123', 'Hello', 17);
    }

    private function row(string $name, string $text, bool $fromBot, int $messageId): TalkMessage {
        $row = new TalkMessage();
        $row->setActorName($name);
        $row->setContent($text);
        $row->setFromBot($fromBot);
        $row->setMessageId($messageId);
        return $row;
    }

    public function testReplyJobSendsTheConversationAsContextAndPostsTheAnswer(): void {
        $bot = $this->createMock(TalkBotService::class);
        $bot->method('recentMessages')->willReturn([
            $this->row(TalkBotService::NAME, 'old answer', true, 0),
            $this->row('Alice', 'The release is on Friday.', false, 15),
            $this->row('Bob', 'Who writes the notes?', false, 16),
            $this->row('Alice', '@AIquila when is the release?', false, 17),
            $this->row('Bob', 'later message', false, 18),
        ]);
        $bot->expects($this->once())->method('postReply')->with('abc123', 'Friday.', 17);
        $bot->expects($this->once())->method('remember')->with('abc123', 0, 'AIquila', 'Friday.', true);

        $llm = $this->createMock(LLMProviderInterface::class);
        $llm->expects($this->once())->method('chat')
            ->with(
                [['role' => 'user', 'content' => "Alice: The release is on Friday.\nBob: Who writes the notes?\nAlice: @AIquila when is the release?"]],
                $this->stringContains('"Team"'),
                'alice',
            )
            ->willReturn(['response' => 'Friday.']);
        $factory = $this->createStub(LLMProviderFactory::class);
        $factory->method('getProviderForUser')->willReturn($llm);

        $job = new TalkBotReplyJob($this->createStub(ITimeFactory::class), $bot, $factory, $this->createStub(LoggerInterface::class));
        (new \ReflectionMethod($job, 'run'))->invoke($job, ['token' => 'abc123', 'messageId' => 17, 'userId' => 'alice', 'roomName' => 'Team']);
    }

    public function testReplyJobApologisesWhenTheModelFails(): void {
        $bot = $this->createMock(TalkBotService::class);
        $bot->method('recentMessages')->willReturn([$this->row('Alice', '@AIquila hi', false, 17)]);
        $bot->expects($this->once())->method('postReply')->with('abc123', $this->stringStartsWith('Sorry'), 17);

        $llm = $this->createStub(LLMProviderInterface::class);
        $llm->method('chat')->willReturn(['error' => 'rate limited']);
        $factory = $this->createStub(LLMProviderFactory::class);
        $factory->method('getProviderForUser')->willReturn($llm);

        $job = new TalkBotReplyJob($this->createStub(ITimeFactory::class), $bot, $factory, $this->createStub(LoggerInterface::class));
        (new \ReflectionMethod($job, 'run'))->invoke($job, ['token' => 'abc123', 'messageId' => 17, 'userId' => null, 'roomName' => '']);
    }
}
