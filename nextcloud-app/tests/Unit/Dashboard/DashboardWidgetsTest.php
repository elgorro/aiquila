<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\AIquila\Tests\Unit\Dashboard;

use OCA\AIquila\Dashboard\ConversationsWidget;
use OCA\AIquila\Dashboard\CoworkerOutputWidget;
use OCA\AIquila\Dashboard\CoworkersWidget;
use OCA\AIquila\Dashboard\UsageWidget;
use OCA\AIquila\Db\Conversation;
use OCA\AIquila\Db\ConversationMapper;
use OCA\AIquila\Db\Coworker;
use OCA\AIquila\Db\UsageStatMapper;
use OCA\AIquila\Service\CoworkerService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Dashboard\Model\WidgetButton;
use OCP\IDateTimeFormatter;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

class DashboardWidgetsTest extends TestCase {
    private IL10N $l10n;
    private IURLGenerator $urlGenerator;
    private IUserSession $userSession;
    private IDateTimeFormatter $dateTimeFormatter;

    protected function setUp(): void {
        $this->l10n = $this->createMock(IL10N::class);
        $this->l10n->method('t')->willReturnCallback(
            fn (string $text, array $p = []) => $p === [] ? $text : vsprintf(str_replace('%s', '%s', $text), $p)
        );
        $this->l10n->method('n')->willReturnCallback(
            fn (string $s, string $pl, int $count) => str_replace('%n', (string)$count, $count === 1 ? $s : $pl)
        );

        $this->urlGenerator = $this->createMock(IURLGenerator::class);
        $this->urlGenerator->method('linkToRouteAbsolute')->willReturn('https://nc.test/apps/aiquila/');
        $this->urlGenerator->method('imagePath')->willReturn('/apps/aiquila/img/app-dark.svg');
        $this->urlGenerator->method('getAbsoluteURL')->willReturnArgument(0);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->userSession = $this->createMock(IUserSession::class);
        $this->userSession->method('getUser')->willReturn($user);

        $this->dateTimeFormatter = $this->createMock(IDateTimeFormatter::class);
        $this->dateTimeFormatter->method('formatTimeSpan')->willReturn('5 minutes ago');
    }

    public function testConversationsWidgetItems(): void {
        $mapper = $this->createMock(ConversationMapper::class);
        $mapper->method('findAllByUser')->willReturn([
            $this->conversation(1, 'First chat', 1000),
            $this->conversation(2, 'Second chat', 900),
        ]);

        $widget = new ConversationsWidget(
            $this->l10n, $this->urlGenerator, $this->userSession, $mapper, $this->dateTimeFormatter
        );

        $this->assertSame('aiquila_conversations', $widget->getId());
        $this->assertSame(10, $widget->getOrder());

        $items = $widget->getItemsV2('alice')->getItems();
        $this->assertCount(2, $items);
        $this->assertSame('First chat', $items[0]->getTitle());
        $this->assertStringContainsString('#/chat/1', $items[0]->getLink());
    }

    public function testConversationsWidgetCapsAtFive(): void {
        $mapper = $this->createMock(ConversationMapper::class);
        $mapper->method('findAllByUser')->willReturn(
            array_map(fn (int $i) => $this->conversation($i, "Chat $i", 1000 - $i), range(1, 10))
        );

        $widget = new ConversationsWidget(
            $this->l10n, $this->urlGenerator, $this->userSession, $mapper, $this->dateTimeFormatter
        );

        $this->assertCount(5, $widget->getItemsV2('alice')->getItems());
    }

    public function testConversationsWidgetEmpty(): void {
        $mapper = $this->createMock(ConversationMapper::class);
        $mapper->method('findAllByUser')->willReturn([]);

        $widget = new ConversationsWidget(
            $this->l10n, $this->urlGenerator, $this->userSession, $mapper, $this->dateTimeFormatter
        );

        $result = $widget->getItemsV2('alice');
        $this->assertSame([], $result->getItems());
        $this->assertSame('No conversations yet', $result->getEmptyContentMessage());
    }

    public function testCoworkersWidgetStatusSubtitles(): void {
        $service = $this->createMock(CoworkerService::class);
        $service->method('listForUser')->willReturn([
            $this->coworker('Paused one', paused: 1),
            $this->coworker('Failed one', lastStatus: 'error', lastError: 'boom'),
            $this->coworker('Scheduled one', nextRunAt: 5000),
        ]);

        $widget = new CoworkersWidget(
            $this->l10n, $this->urlGenerator, $this->userSession, $service, $this->dateTimeFormatter
        );

        $this->assertSame('aiquila_coworkers', $widget->getId());
        $items = $widget->getItemsV2('alice')->getItems();
        $this->assertSame('Paused', $items[0]->getSubtitle());
        $this->assertStringContainsString('boom', $items[1]->getSubtitle());
        $this->assertStringContainsString('5 minutes ago', $items[2]->getSubtitle());

        $buttons = $widget->getWidgetButtons('alice');
        $this->assertSame(WidgetButton::TYPE_MORE, $buttons[0]->getType());
        $this->assertStringContainsString('#/cowork', $buttons[0]->getLink());
    }

    public function testUsageWidgetItems(): void {
        $mapper = $this->createMock(UsageStatMapper::class);
        $mapper->method('sumTokensByUserSince')->willReturn(['input_tokens' => 10, 'output_tokens' => 5]);
        $mapper->method('sumTokensByUser')->willReturn([
            'input_tokens' => 100, 'output_tokens' => 50,
            'cache_creation_tokens' => 0, 'cache_read_tokens' => 0,
        ]);
        $timeFactory = $this->createMock(ITimeFactory::class);
        $timeFactory->method('getTime')->willReturn(1_000_000);

        $widget = new UsageWidget(
            $this->l10n, $this->urlGenerator, $this->userSession, $mapper, $timeFactory
        );

        $this->assertSame('aiquila_usage', $widget->getId());
        $items = $widget->getItemsV2('alice')->getItems();
        $this->assertCount(3, $items);
        $this->assertSame('Today', $items[0]->getTitle());
        $this->assertStringContainsString('15', $items[0]->getSubtitle());
        $this->assertStringContainsString('150', $items[2]->getSubtitle());
    }

    public function testCoworkerOutputWidgetMetadata(): void {
        $widget = new CoworkerOutputWidget($this->l10n, $this->urlGenerator, $this->userSession);

        $this->assertSame('aiquila_coworker_output', $widget->getId());
        $this->assertSame(40, $widget->getOrder());
        $this->assertSame('icon-aiquila', $widget->getIconClass());
        $this->assertStringContainsString('#/cowork', $widget->getUrl());
    }

    private function conversation(int $id, string $title, int $updatedAt): Conversation {
        $c = new Conversation();
        $c->setId($id);
        $c->setTitle($title);
        $c->setUpdatedAt($updatedAt);
        return $c;
    }

    private function coworker(
        string $title,
        int $paused = 0,
        ?string $lastStatus = null,
        ?string $lastError = null,
        ?int $nextRunAt = null,
    ): Coworker {
        $c = new Coworker();
        $c->setTitle($title);
        $c->setPaused($paused);
        $c->setLastStatus($lastStatus);
        $c->setLastError($lastError);
        $c->setNextRunAt($nextRunAt);
        $c->setUpdatedAt(0);
        return $c;
    }
}
