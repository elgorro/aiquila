<?php

declare(strict_types=1);

namespace OCA\AIquila\Tests\Unit\Db;

use OCA\AIquila\Db\Prompt;
use OCA\AIquila\Db\PromptMapper;

class PromptMapperTest extends MapperTestCase {

    private function makeMapper(): PromptMapper {
        return $this->getMockBuilder(PromptMapper::class)
            ->setConstructorArgs([$this->db])
            ->onlyMethods(['findEntities', 'findEntity'])
            ->getMock();
    }

    public function testFindAllByUserCallsFindEntities(): void {
        $p = new Prompt();
        $p->setUserId('carol');

        $mapper = $this->makeMapper();
        $mapper->expects($this->once())
            ->method('findEntities')
            ->willReturn([$p]);

        $result = $mapper->findAllByUser('carol');

        $this->assertCount(1, $result);
        $this->assertSame($p, $result[0]);
    }

    public function testFindAllByUserOrdersByTitleAsc(): void {
        $this->qb->expects($this->once())
            ->method('orderBy')
            ->with('title', 'ASC')
            ->willReturnSelf();

        $mapper = $this->makeMapper();
        $mapper->method('findEntities')->willReturn([]);
        $mapper->findAllByUser('carol');
    }

    public function testFindAllByUserWithoutActiveOnlyDoesNotAddAndWhere(): void {
        // When $activeOnly = false, no andWhere() call for is_active
        $this->qb->expects($this->never())->method('andWhere');

        $mapper = $this->makeMapper();
        $mapper->method('findEntities')->willReturn([]);
        $mapper->findAllByUser('carol', false);
    }

    public function testFindAllByUserWithActiveOnlyAddsAndWhere(): void {
        // When $activeOnly = true, andWhere() must be called once
        $this->qb->expects($this->once())
            ->method('andWhere')
            ->willReturnSelf();

        $mapper = $this->makeMapper();
        $mapper->method('findEntities')->willReturn([]);
        $mapper->findAllByUser('carol', true);
    }

    public function testFindByIdAndUserCallsFindEntity(): void {
        $p = new Prompt();

        $mapper = $this->makeMapper();
        $mapper->expects($this->once())
            ->method('findEntity')
            ->willReturn($p);

        $result = $mapper->findByIdAndUser(7, 'carol');
        $this->assertSame($p, $result);
    }

    public function testTableName(): void {
        $this->qb->expects($this->once())
            ->method('from')
            ->with('aiquila_prompts')
            ->willReturnSelf();

        $mapper = $this->makeMapper();
        $mapper->method('findEntities')->willReturn([]);
        $mapper->findAllByUser('carol');
    }
}
