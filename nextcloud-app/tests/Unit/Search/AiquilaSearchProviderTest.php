<?php

declare(strict_types=1);

namespace OCA\AIquila\Tests\Unit\Search;

use OCA\AIquila\Db\Message;
use OCA\AIquila\Db\MessageMapper;
use OCA\AIquila\Search\AiquilaSearchProvider;
use OCA\AIquila\Service\SearchSettings;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\ISearchQuery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AiquilaSearchProviderTest extends TestCase {
    private MessageMapper&MockObject $mapper;
    private IUser $user;
    private ISearchQuery $query;

    protected function setUp(): void {
        $this->mapper = $this->createMock(MessageMapper::class);

        $user = $this->createStub(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->user = $user;

        $query = $this->createStub(ISearchQuery::class);
        $query->method('getTerm')->willReturn('hello');
        $query->method('getLimit')->willReturn(5);
        $query->method('getCursor')->willReturn(null);
        $this->query = $query;
    }

    private function provider(?string $stored): AiquilaSearchProvider {
        $config = $this->createStub(IConfig::class);
        $config->method('getAppValue')->willReturnCallback(
            fn (string $app, string $key, string $default): string => $stored ?? $default
        );
        $l10n = $this->createStub(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);

        return new AiquilaSearchProvider(
            $this->mapper,
            $this->createStub(IURLGenerator::class),
            $l10n,
            new SearchSettings($config),
        );
    }

    public function testDisabledReturnsNothingAndSkipsTheMapper(): void {
        $this->mapper->expects($this->never())->method('search');

        $result = $this->provider('0')->search($this->user, $this->query)->jsonSerialize();

        $this->assertSame([], $result['entries']);
        $this->assertFalse($result['isPaginated']);
    }

    /** @return array<string, array{?string}> */
    public static function enabledValues(): array {
        return ['unset' => [null], 'on' => ['1']];
    }

    #[DataProvider('enabledValues')]
    public function testEnabledSearchesConversations(?string $stored): void {
        $message = new Message();
        $message->setId(7);
        $message->setConversationId(3);
        $message->setRole('assistant');
        $message->setContent('hello world');
        $message->setCreatedAt(0);

        $this->mapper->expects($this->once())
            ->method('search')
            ->with('alice', 'hello', 5, 0)
            ->willReturn([$message]);

        $result = $this->provider($stored)->search($this->user, $this->query)->jsonSerialize();

        $this->assertCount(1, $result['entries']);
    }
}
