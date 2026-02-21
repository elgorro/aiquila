<?php

declare(strict_types=1);

namespace OCA\AIquila\Tests\Unit\Db;

use OCA\AIquila\Db\Conversation;
use OCA\AIquila\Db\ConversationMapper;

class ConversationMapperTest extends MapperTestCase {

    private function makeMapper(): ConversationMapper {
        return $this->getMockBuilder(ConversationMapper::class)
            ->setConstructorArgs([$this->db])
            ->onlyMethods(['findEntities', 'findEntity'])
            ->getMock();
    }

    public function testTableName(): void {
        $mapper = new ConversationMapper($this->db);
        // getTableName() is protected; verify it via the QB call
        $this->qb->expects($this->once())
            ->method('from')
            ->with('aiquila_conversations')
            ->willReturnSelf();

        $mapper = $this->makeMapper();
        $mapper->method('findEntities')->willReturn([]);
        $mapper->findAllByUser('alice');
    }

    public function testFindAllByUserCallsFindEntities(): void {
        $conv = new Conversation();
        $conv->setUserId('alice');

        $mapper = $this->makeMapper();
        $mapper->expects($this->once())
            ->method('findEntities')
            ->willReturn([$conv]);

        $result = $mapper->findAllByUser('alice');

        $this->assertCount(1, $result);
        $this->assertSame($conv, $result[0]);
    }

    public function testFindByIdAndUserCallsFindEntity(): void {
        $conv = new Conversation();
        $conv->setUserId('alice');

        $mapper = $this->makeMapper();
        $mapper->expects($this->once())
            ->method('findEntity')
            ->willReturn($conv);

        $result = $mapper->findByIdAndUser(1, 'alice');
        $this->assertSame($conv, $result);
    }

    public function testDeleteAllByUserCallsExecuteStatement(): void {
        $this->qb->expects($this->once())->method('executeStatement');

        $mapper = new ConversationMapper($this->db);
        $mapper->deleteAllByUser('alice');
    }

    public function testFindAllByUserOrdersByUpdatedAtDesc(): void {
        $this->qb->expects($this->once())
            ->method('orderBy')
            ->with('updated_at', 'DESC')
            ->willReturnSelf();

        $mapper = $this->makeMapper();
        $mapper->method('findEntities')->willReturn([]);
        $mapper->findAllByUser('alice');
    }
}
