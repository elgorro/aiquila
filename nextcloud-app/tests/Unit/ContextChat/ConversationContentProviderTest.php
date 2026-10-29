<?php

declare(strict_types=1);

namespace OCA\AIquila\Tests\Unit\ContextChat;

use OCA\AIquila\ContextChat\ConversationContentProvider;
use OCA\AIquila\Service\ContextChatService;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

class ConversationContentProviderTest extends TestCase {
    private $service;
    private $urlGenerator;
    private ConversationContentProvider $provider;

    protected function setUp(): void {
        $this->service = $this->createStub(ContextChatService::class);
        $this->urlGenerator = $this->createStub(IURLGenerator::class);
        $this->buildProvider();
    }

    private function buildProvider(): void {
        $this->provider = new ConversationContentProvider($this->service, $this->urlGenerator);
    }

    public function testIdentity(): void {
        $this->assertSame('conversation', $this->provider->getId());
        $this->assertSame('aiquila', $this->provider->getAppId());
    }

    public function testItemUrlDeepLinksToConversation(): void {
        $this->urlGenerator = $this->createMock(IURLGenerator::class);
        $this->buildProvider();

        $this->urlGenerator->expects($this->once())->method('linkToRoute')->with('aiquila.page.index')->willReturn('/apps/aiquila/');
        $this->urlGenerator->method('getAbsoluteURL')
            ->willReturnCallback(static fn (string $url): string => 'https://cloud.example' . $url);

        $this->assertSame(
            'https://cloud.example/apps/aiquila/#/conversations/42',
            $this->provider->getItemUrl('42'),
        );
    }

    public function testInitialImportReindexesAll(): void {
        $this->service = $this->createMock(ContextChatService::class);
        $this->buildProvider();

        $this->service->expects($this->once())->method('reindexAll');
        $this->provider->triggerInitialImport();
    }
}
