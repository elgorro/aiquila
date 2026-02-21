<?php

declare(strict_types=1);

namespace OCA\AIquila\Tests\Unit\Db;

use OCA\AIquila\Db\Coworker;
use OCA\AIquila\Db\CoworkerMapper;

class CoworkerMapperTest extends MapperTestCase {

    private function makeMapper(): CoworkerMapper {
        return $this->getMockBuilder(CoworkerMapper::class)
            ->setConstructorArgs([$this->db])
            ->onlyMethods(['findEntities', 'findEntity'])
            ->getMock();
    }

    public function testFindAllByUserCallsFindEntities(): void {
        $cw = new Coworker();
        $cw->setUserId('frank');

        $mapper = $this->makeMapper();
        $mapper->expects($this->once())
            ->method('findEntities')
            ->willReturn([$cw]);

        $result = $mapper->findAllByUser('frank');

        $this->assertCount(1, $result);
        $this->assertSame($cw, $result[0]);
    }

    public function testFindAllByUserOrdersByTitleAsc(): void {
        $this->qb->expects($this->once())
            ->method('orderBy')
            ->with('title', 'ASC')
            ->willReturnSelf();

        $mapper = $this->makeMapper();
        $mapper->method('findEntities')->willReturn([]);
        $mapper->findAllByUser('frank');
    }

    public function testFindByIdAndUserCallsFindEntity(): void {
        $cw = new Coworker();

        $mapper = $this->makeMapper();
        $mapper->expects($this->once())
            ->method('findEntity')
            ->willReturn($cw);

        $result = $mapper->findByIdAndUser(9, 'frank');
        $this->assertSame($cw, $result);
    }

    public function testFindDueForRunCallsFindEntities(): void {
        $cw = new Coworker();
        $cw->setIsActive(true);
        $cw->setNextRunAt(1000);

        $mapper = $this->makeMapper();
        $mapper->expects($this->once())
            ->method('findEntities')
            ->willReturn([$cw]);

        $result = $mapper->findDueForRun(2000);

        $this->assertCount(1, $result);
        $this->assertSame($cw, $result[0]);
    }

    public function testFindDueForRunAppliesIsActiveFilter(): void {
        // is_active filter via where(), next_run_at filter via andWhere()
        $this->qb->expects($this->once())->method('where')->willReturnSelf();
        $this->qb->expects($this->once())->method('andWhere')->willReturnSelf();

        $mapper = $this->makeMapper();
        $mapper->method('findEntities')->willReturn([]);
        $mapper->findDueForRun(1000);
    }

    public function testTableName(): void {
        $this->qb->expects($this->once())
            ->method('from')
            ->with('aiquila_coworkers')
            ->willReturnSelf();

        $mapper = $this->makeMapper();
        $mapper->method('findEntities')->willReturn([]);
        $mapper->findAllByUser('frank');
    }
}
