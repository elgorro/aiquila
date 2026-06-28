<?php

declare(strict_types=1);

namespace OCA\AIquila\Tests\Unit\Service;

use OCA\AIquila\Db\Conversation;
use OCA\AIquila\Db\ConversationMapper;
use OCA\AIquila\Db\Message;
use OCA\AIquila\Db\MessageMapper;
use OCA\AIquila\Service\ContextChatService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\ContextChat\ContentItem;
use OCP\ContextChat\IContentManager;
use OCP\IServerContainer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ContextChatServiceTest extends TestCase {
    private $conversationMapper;
    private $messageMapper;
    private $container;
    private $manager;
    private ContextChatService $service;

    protected function setUp(): void {
        $this->conversationMapper = $this->createMock(ConversationMapper::class);
        $this->messageMapper = $this->createMock(MessageMapper::class);
        $this->container = $this->createMock(IServerContainer::class);
        $this->manager = $this->createMock(IContentManager::class);
        $logger = $this->createMock(LoggerInterface::class);

        $this->container->method('get')->with(IContentManager::class)->willReturn($this->manager);

        $this->service = new ContextChatService(
            $this->conversationMapper,
            $this->messageMapper,
            $this->container,
            $logger,
        );
    }

    private function makeConversation(int $id, string $userId, ?string $title): Conversation {
        $conversation = new Conversation();
        $conversation->setUserId($userId);
        $conversation->setTitle($title);
        $conversation->setUpdatedAt(1700000000);
        // Entity id is set via reflection-free setter on the stub Entity.
        $conversation->setId($id);
        return $conversation;
    }

    private function makeMessage(string $role, string $content): Message {
        $message = new Message();
        $message->setRole($role);
        $message->setContent($content);
        return $message;
    }

    public function testIndexConversationSubmitsContentItem(): void {
        $this->manager->expects($this->once())->method('isContextChatAvailable')->willReturn(true);

        $this->conversationMapper->method('findById')->with(42)
            ->willReturn($this->makeConversation(42, 'alice', 'Trip planning'));
        $this->messageMapper->method('findByConversation')->with(42)->willReturn([
            $this->makeMessage('user', 'Where should I go?'),
            $this->makeMessage('assistant', 'Consider Lisbon.'),
        ]);

        $captured = null;
        $this->manager->expects($this->once())->method('submitContent')
            ->with('aiquila', $this->callback(function (array $items) use (&$captured): bool {
                $captured = $items[0];
                return count($items) === 1 && $items[0] instanceof ContentItem;
            }));

        $this->service->indexConversation(42);

        $this->assertSame('42', $captured->itemId);
        $this->assertSame(ContextChatService::PROVIDER_ID, $captured->providerId);
        $this->assertSame('Trip planning', $captured->title);
        $this->assertSame(['alice'], $captured->users);
        $this->assertStringContainsString('User: Where should I go?', $captured->content);
        $this->assertStringContainsString('Assistant: Consider Lisbon.', $captured->content);
    }

    public function testIndexConversationNoOpWhenUnavailable(): void {
        $this->manager->method('isContextChatAvailable')->willReturn(false);

        $this->conversationMapper->expects($this->never())->method('findById');
        $this->manager->expects($this->never())->method('submitContent');

        $this->service->indexConversation(42);
    }

    public function testIndexConversationSkipsEmptyConversation(): void {
        $this->manager->method('isContextChatAvailable')->willReturn(true);
        $this->conversationMapper->method('findById')
            ->willReturn($this->makeConversation(7, 'bob', 'Empty'));
        $this->messageMapper->method('findByConversation')->willReturn([]);

        $this->manager->expects($this->never())->method('submitContent');

        $this->service->indexConversation(7);
    }

    public function testIndexConversationRemovesWhenDeleted(): void {
        $this->manager->method('isContextChatAvailable')->willReturn(true);
        $this->conversationMapper->method('findById')
            ->willThrowException(new DoesNotExistException('gone'));

        $this->manager->expects($this->never())->method('submitContent');
        $this->manager->expects($this->once())->method('deleteContent')
            ->with('aiquila', ContextChatService::PROVIDER_ID, ['99']);

        $this->service->indexConversation(99);
    }

    public function testRemoveConversationDeletesContent(): void {
        $this->manager->method('isContextChatAvailable')->willReturn(true);

        $this->manager->expects($this->once())->method('deleteContent')
            ->with('aiquila', ContextChatService::PROVIDER_ID, ['5']);

        $this->service->removeConversation(5);
    }
}
