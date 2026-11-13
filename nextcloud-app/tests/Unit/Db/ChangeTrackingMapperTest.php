<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Tests\Unit\Db;

use OCA\AIquila\Db\ChangeTrackingMapper;
use OCA\AIquila\Db\Conversation;
use OCA\AIquila\Db\ConversationMapper;
use OCP\DB\Exception;

/**
 * Writes leave the entity clean, so a later update() of the same instance
 * writes only what changed since, not columns another request changed.
 */
class ChangeTrackingMapperTest extends MapperTestCase {
    /** @var list<string> columns the last statement set */
    private array $written = [];

    protected function setUp(): void {
        parent::setUp();
        $this->qb->method('insert')->willReturnSelf();
        $this->qb->method('update')->willReturnSelf();
        $this->qb->method('setValue')->willReturnCallback(function (string $column) {
            $this->written[] = $column;
            return $this->qb;
        });
        $this->qb->method('set')->willReturnCallback(function (string $column) {
            $this->written[] = $column;
            return $this->qb;
        });
        $this->qb->method('getLastInsertId')->willReturn(3);
    }

    private function conversation(): Conversation {
        $conversation = new Conversation();
        $conversation->setUserId('alice');
        $conversation->setModel('m');
        $conversation->setTitle('Draft');
        return $conversation;
    }

    public function testEveryMapperTracksChanges(): void {
        foreach (glob(__DIR__ . '/../../../lib/Db/*Mapper.php') as $file) {
            $class = 'OCA\\AIquila\\Db\\' . basename($file, '.php');
            if ($class === ChangeTrackingMapper::class) {
                continue;
            }
            $this->assertTrue(is_subclass_of($class, ChangeTrackingMapper::class), $class . ' extends ChangeTrackingMapper');
        }
    }

    public function testAnUpdateAfterAnInsertWritesOnlyWhatChanged(): void {
        $this->qb->method('executeStatement')->willReturn(1);
        $mapper = new ConversationMapper($this->db);

        $conversation = $mapper->insert($this->conversation());
        $this->assertSame([], $conversation->getUpdatedFields());

        $this->written = [];
        $conversation->setUpdatedAt(1_800_000_000);
        $mapper->update($conversation);

        $this->assertSame(['updated_at'], $this->written, 'the title set before the insert is not written again');
    }

    public function testASecondUpdateDoesNotRepeatTheFirst(): void {
        $this->qb->method('executeStatement')->willReturn(1);
        $mapper = new ConversationMapper($this->db);
        $conversation = $mapper->insert($this->conversation());

        $conversation->setToolState('{"a":1}');
        $mapper->update($conversation);
        $this->written = [];
        $conversation->setUpdatedAt(1_800_000_000);
        $mapper->update($conversation);

        $this->assertSame(['updated_at'], $this->written);
    }

    public function testAFailedWriteKeepsTheMarks(): void {
        $this->qb->method('executeStatement')->willThrowException(new Exception('db'));
        $mapper = new ConversationMapper($this->db);
        $conversation = $this->conversation();

        try {
            $mapper->insert($conversation);
            $this->fail('the insert should have failed');
        } catch (Exception) {
        }

        $this->assertArrayHasKey('title', $conversation->getUpdatedFields());
    }
}
