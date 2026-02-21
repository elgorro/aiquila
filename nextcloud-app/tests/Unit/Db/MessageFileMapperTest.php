<?php

declare(strict_types=1);

namespace OCA\AIquila\Tests\Unit\Db;

use OCA\AIquila\Db\MessageFile;
use OCA\AIquila\Db\MessageFileMapper;

class MessageFileMapperTest extends MapperTestCase {

    private function makeMapper(): MessageFileMapper {
        return $this->getMockBuilder(MessageFileMapper::class)
            ->setConstructorArgs([$this->db])
            ->onlyMethods(['findEntities'])
            ->getMock();
    }

    public function testFindByMessageCallsFindEntities(): void {
        $file = new MessageFile();
        $file->setMessageId(3);

        $mapper = $this->makeMapper();
        $mapper->expects($this->once())
            ->method('findEntities')
            ->willReturn([$file]);

        $result = $mapper->findByMessage(3);

        $this->assertCount(1, $result);
        $this->assertSame($file, $result[0]);
    }

    public function testFindByMessageOrdersByCreatedAtAsc(): void {
        $this->qb->expects($this->once())
            ->method('orderBy')
            ->with('created_at', 'ASC')
            ->willReturnSelf();

        $mapper = $this->makeMapper();
        $mapper->method('findEntities')->willReturn([]);
        $mapper->findByMessage(3);
    }

    public function testDeleteByMessageCallsExecuteStatement(): void {
        $this->qb->expects($this->once())->method('executeStatement');

        $mapper = new MessageFileMapper($this->db);
        $mapper->deleteByMessage(3);
    }

    public function testTableName(): void {
        $this->qb->expects($this->once())
            ->method('from')
            ->with('aiquila_message_files')
            ->willReturnSelf();

        $mapper = $this->makeMapper();
        $mapper->method('findEntities')->willReturn([]);
        $mapper->findByMessage(1);
    }
}
