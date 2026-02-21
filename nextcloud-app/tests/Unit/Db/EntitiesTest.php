<?php

declare(strict_types=1);

namespace OCA\AIquila\Tests\Unit\Db;

use OCA\AIquila\Db\Conversation;
use OCA\AIquila\Db\Coworker;
use OCA\AIquila\Db\Message;
use OCA\AIquila\Db\MessageFile;
use OCA\AIquila\Db\Prompt;
use OCA\AIquila\Db\UsageStat;
use PHPUnit\Framework\TestCase;

/**
 * Tests for all Db Entity classes.
 * No mocks needed — pure instantiation + getter/setter/default assertions.
 */
class EntitiesTest extends TestCase {

    // ── Conversation ──────────────────────────────────────────────────────

    public function testConversationDefaults(): void {
        $c = new Conversation();
        $this->assertNull($c->getId());
        $this->assertEquals('', $c->getUserId());
        $this->assertNull($c->getTitle());
        $this->assertEquals('', $c->getModel());
        $this->assertEquals(0, $c->getCreatedAt());
        $this->assertEquals(0, $c->getUpdatedAt());
    }

    public function testConversationSettersAndGetters(): void {
        $c = new Conversation();
        $c->setUserId('alice');
        $c->setTitle('My Chat');
        $c->setModel('claude-sonnet-4-5-20250929');
        $c->setCreatedAt(1000);
        $c->setUpdatedAt(2000);

        $this->assertEquals('alice', $c->getUserId());
        $this->assertEquals('My Chat', $c->getTitle());
        $this->assertEquals('claude-sonnet-4-5-20250929', $c->getModel());
        $this->assertEquals(1000, $c->getCreatedAt());
        $this->assertEquals(2000, $c->getUpdatedAt());
    }

    public function testConversationTitleCanBeNull(): void {
        $c = new Conversation();
        $c->setTitle(null);
        $this->assertNull($c->getTitle());
    }

    public function testConversationRegistersTypes(): void {
        $c     = new Conversation();
        $types = $c->getFieldTypes();
        $this->assertEquals('string',  $types['userId']);
        $this->assertEquals('string',  $types['title']);
        $this->assertEquals('string',  $types['model']);
        $this->assertEquals('integer', $types['createdAt']);
        $this->assertEquals('integer', $types['updatedAt']);
    }

    // ── Message ───────────────────────────────────────────────────────────

    public function testMessageDefaults(): void {
        $m = new Message();
        $this->assertNull($m->getId());
        $this->assertEquals(0, $m->getConversationId());
        $this->assertEquals('', $m->getRole());
        $this->assertEquals('', $m->getContent());
        $this->assertNull($m->getInputTokens());
        $this->assertNull($m->getOutputTokens());
        $this->assertEquals(0, $m->getCreatedAt());
    }

    public function testMessageSettersAndGetters(): void {
        $m = new Message();
        $m->setConversationId(42);
        $m->setRole('user');
        $m->setContent('Hello');
        $m->setInputTokens(10);
        $m->setOutputTokens(20);
        $m->setCreatedAt(1234567890);

        $this->assertEquals(42, $m->getConversationId());
        $this->assertEquals('user', $m->getRole());
        $this->assertEquals('Hello', $m->getContent());
        $this->assertEquals(10, $m->getInputTokens());
        $this->assertEquals(20, $m->getOutputTokens());
        $this->assertEquals(1234567890, $m->getCreatedAt());
    }

    public function testMessageRegistersTypes(): void {
        $types = (new Message())->getFieldTypes();
        $this->assertEquals('integer', $types['conversationId']);
        $this->assertEquals('string',  $types['role']);
        $this->assertEquals('string',  $types['content']);
        $this->assertEquals('integer', $types['inputTokens']);
        $this->assertEquals('integer', $types['outputTokens']);
        $this->assertEquals('integer', $types['createdAt']);
    }

    // ── MessageFile ───────────────────────────────────────────────────────

    public function testMessageFileDefaults(): void {
        $f = new MessageFile();
        $this->assertNull($f->getId());
        $this->assertEquals(0, $f->getMessageId());
        $this->assertEquals('', $f->getFilePath());
        $this->assertEquals('', $f->getFileName());
        $this->assertNull($f->getMimeType());
        $this->assertEquals(0, $f->getCreatedAt());
    }

    public function testMessageFileSettersAndGetters(): void {
        $f = new MessageFile();
        $f->setMessageId(7);
        $f->setFilePath('/files/doc.pdf');
        $f->setFileName('doc.pdf');
        $f->setMimeType('application/pdf');
        $f->setCreatedAt(999);

        $this->assertEquals(7, $f->getMessageId());
        $this->assertEquals('/files/doc.pdf', $f->getFilePath());
        $this->assertEquals('doc.pdf', $f->getFileName());
        $this->assertEquals('application/pdf', $f->getMimeType());
        $this->assertEquals(999, $f->getCreatedAt());
    }

    // ── UsageStat ─────────────────────────────────────────────────────────

    public function testUsageStatDefaults(): void {
        $u = new UsageStat();
        $this->assertEquals('', $u->getUserId());
        $this->assertEquals('', $u->getModel());
        $this->assertEquals(0, $u->getInputTokens());
        $this->assertEquals(0, $u->getOutputTokens());
        $this->assertEquals('', $u->getRequestType());
        $this->assertNull($u->getConversationId());
        $this->assertEquals(0, $u->getCreatedAt());
    }

    public function testUsageStatSettersAndGetters(): void {
        $u = new UsageStat();
        $u->setUserId('bob');
        $u->setModel('claude-opus-4-6');
        $u->setInputTokens(100);
        $u->setOutputTokens(200);
        $u->setRequestType('chat');
        $u->setConversationId(5);
        $u->setCreatedAt(1000);

        $this->assertEquals('bob', $u->getUserId());
        $this->assertEquals('claude-opus-4-6', $u->getModel());
        $this->assertEquals(100, $u->getInputTokens());
        $this->assertEquals(200, $u->getOutputTokens());
        $this->assertEquals('chat', $u->getRequestType());
        $this->assertEquals(5, $u->getConversationId());
        $this->assertEquals(1000, $u->getCreatedAt());
    }

    public function testUsageStatRegistersTypes(): void {
        $types = (new UsageStat())->getFieldTypes();
        $this->assertEquals('string',  $types['userId']);
        $this->assertEquals('string',  $types['model']);
        $this->assertEquals('integer', $types['inputTokens']);
        $this->assertEquals('integer', $types['outputTokens']);
        $this->assertEquals('string',  $types['requestType']);
        $this->assertEquals('integer', $types['conversationId']);
        $this->assertEquals('integer', $types['createdAt']);
    }

    // ── Prompt ────────────────────────────────────────────────────────────

    public function testPromptDefaults(): void {
        $p = new Prompt();
        $this->assertEquals('', $p->getUserId());
        $this->assertEquals('', $p->getTitle());
        $this->assertNull($p->getDescription());
        $this->assertEquals('', $p->getContent());
        $this->assertTrue($p->getIsActive());
        $this->assertEquals(0, $p->getCreatedAt());
        $this->assertEquals(0, $p->getUpdatedAt());
    }

    public function testPromptSettersAndGetters(): void {
        $p = new Prompt();
        $p->setUserId('charlie');
        $p->setTitle('Summarizer');
        $p->setDescription('Summarizes text');
        $p->setContent('Summarize: {input}');
        $p->setIsActive(false);

        $this->assertEquals('charlie', $p->getUserId());
        $this->assertEquals('Summarizer', $p->getTitle());
        $this->assertEquals('Summarizes text', $p->getDescription());
        $this->assertEquals('Summarize: {input}', $p->getContent());
        $this->assertFalse($p->getIsActive());
    }

    public function testPromptRegistersTypes(): void {
        $types = (new Prompt())->getFieldTypes();
        $this->assertEquals('string',  $types['userId']);
        $this->assertEquals('string',  $types['title']);
        $this->assertEquals('string',  $types['description']);
        $this->assertEquals('string',  $types['content']);
        $this->assertEquals('boolean', $types['isActive']);
        $this->assertEquals('integer', $types['createdAt']);
        $this->assertEquals('integer', $types['updatedAt']);
    }

    // ── Coworker ──────────────────────────────────────────────────────────

    public function testCoworkerDefaults(): void {
        $cw = new Coworker();
        $this->assertEquals('', $cw->getUserId());
        $this->assertEquals('', $cw->getTitle());
        $this->assertNull($cw->getDescription());
        $this->assertNull($cw->getPromptId());
        $this->assertNull($cw->getCustomPrompt());
        $this->assertNull($cw->getModel());
        $this->assertEquals('', $cw->getCronSchedule());
        $this->assertEquals('', $cw->getInputType());
        $this->assertNull($cw->getInputPath());
        $this->assertEquals('', $cw->getOutputType());
        $this->assertNull($cw->getOutputPath());
        $this->assertTrue($cw->getIsActive());
        $this->assertNull($cw->getLastRunAt());
        $this->assertNull($cw->getNextRunAt());
        $this->assertNull($cw->getLastStatus());
        $this->assertNull($cw->getLastError());
    }

    public function testCoworkerSettersAndGetters(): void {
        $cw = new Coworker();
        $cw->setUserId('dave');
        $cw->setTitle('Nightly report');
        $cw->setCronSchedule('0 2 * * *');
        $cw->setInputType('file');
        $cw->setInputPath('/reports/input.txt');
        $cw->setOutputType('email');
        $cw->setIsActive(false);
        $cw->setNextRunAt(9999);
        $cw->setLastStatus('ok');

        $this->assertEquals('dave', $cw->getUserId());
        $this->assertEquals('Nightly report', $cw->getTitle());
        $this->assertEquals('0 2 * * *', $cw->getCronSchedule());
        $this->assertEquals('file', $cw->getInputType());
        $this->assertEquals('/reports/input.txt', $cw->getInputPath());
        $this->assertEquals('email', $cw->getOutputType());
        $this->assertFalse($cw->getIsActive());
        $this->assertEquals(9999, $cw->getNextRunAt());
        $this->assertEquals('ok', $cw->getLastStatus());
    }

    public function testCoworkerRegistersTypes(): void {
        $types = (new Coworker())->getFieldTypes();
        $this->assertEquals('boolean', $types['isActive']);
        $this->assertEquals('integer', $types['promptId']);
        $this->assertEquals('integer', $types['lastRunAt']);
        $this->assertEquals('integer', $types['nextRunAt']);
        $this->assertEquals('integer', $types['createdAt']);
        $this->assertEquals('integer', $types['updatedAt']);
    }
}
