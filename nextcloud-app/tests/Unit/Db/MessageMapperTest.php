<?php

declare(strict_types=1);

namespace OCA\AIquila\Tests\Unit\Db;

use OCA\AIquila\Db\Message;
use OCA\AIquila\Db\MessageMapper;

class MessageMapperTest extends MapperTestCase {

    private function makeMapper(): MessageMapper {
        return $this->getMockBuilder(MessageMapper::class)
            ->setConstructorArgs([$this->db])
            ->onlyMethods(['findEntities'])
            ->getMock();
    }

    public function testFindByConversationCallsFindEntities(): void {
        $msg = new Message();
        $msg->setConversationId(5);

        $mapper = $this->makeMapper();
        $mapper->expects($this->once())
            ->method('findEntities')
            ->willReturn([$msg]);

        $result = $mapper->findByConversation(5);

        $this->assertCount(1, $result);
        $this->assertSame($msg, $result[0]);
    }

    public function testFindByConversationOrdersByCreatedAtAsc(): void {
        $this->qb->expects($this->once())
            ->method('orderBy')
            ->with('created_at', 'ASC')
            ->willReturnSelf();

        $mapper = $this->makeMapper();
        $mapper->method('findEntities')->willReturn([]);
        $mapper->findByConversation(5);
    }

    public function testDeleteByConversationCallsExecuteStatement(): void {
        $this->qb->expects($this->once())->method('executeStatement');

        $mapper = new MessageMapper($this->db);
        $mapper->deleteByConversation(5);
    }

    public function testTableName(): void {
        $this->qb->expects($this->once())
            ->method('from')
            ->with('aiquila_messages')
            ->willReturnSelf();

        $mapper = $this->makeMapper();
        $mapper->method('findEntities')->willReturn([]);
        $mapper->findByConversation(1);
    }
}
