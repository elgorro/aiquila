<?php

declare(strict_types=1);

namespace OCA\AIquila\Tests\Unit\Db;

use OCA\AIquila\Db\UsageStat;
use OCA\AIquila\Db\UsageStatMapper;

class UsageStatMapperTest extends MapperTestCase {

    private function makeMapper(): UsageStatMapper {
        return $this->getMockBuilder(UsageStatMapper::class)
            ->setConstructorArgs([$this->db])
            ->onlyMethods(['findEntities'])
            ->getMock();
    }

    public function testFindByUserCallsFindEntities(): void {
        $stat = new UsageStat();
        $stat->setUserId('eve');

        $mapper = $this->makeMapper();
        $mapper->expects($this->once())
            ->method('findEntities')
            ->willReturn([$stat]);

        $result = $mapper->findByUser('eve');

        $this->assertCount(1, $result);
        $this->assertSame($stat, $result[0]);
    }

    public function testFindByUserAppliesDefaultLimit(): void {
        $this->qb->expects($this->once())
            ->method('setMaxResults')
            ->with(100)
            ->willReturnSelf();

        $this->qb->expects($this->once())
            ->method('setFirstResult')
            ->with(0)
            ->willReturnSelf();

        $mapper = $this->makeMapper();
        $mapper->method('findEntities')->willReturn([]);
        $mapper->findByUser('eve');
    }

    public function testFindByUserRespectsCustomLimitAndOffset(): void {
        $this->qb->expects($this->once())
            ->method('setMaxResults')
            ->with(25)
            ->willReturnSelf();

        $this->qb->expects($this->once())
            ->method('setFirstResult')
            ->with(50)
            ->willReturnSelf();

        $mapper = $this->makeMapper();
        $mapper->method('findEntities')->willReturn([]);
        $mapper->findByUser('eve', 25, 50);
    }

    public function testFindByUserOrdersByCreatedAtDesc(): void {
        $this->qb->expects($this->once())
            ->method('orderBy')
            ->with('created_at', 'DESC')
            ->willReturnSelf();

        $mapper = $this->makeMapper();
        $mapper->method('findEntities')->willReturn([]);
        $mapper->findByUser('eve');
    }

    public function testSumTokensByUserReturnsCorrectTotals(): void {
        $this->qb->method('executeQuery')->willReturn($this->result);
        $this->result->method('fetch')
            ->willReturn(['total_input' => '1500', 'total_output' => '750']);
        $this->result->method('closeCursor')->willReturn(true);

        $mapper = new UsageStatMapper($this->db);
        $totals = $mapper->sumTokensByUser('eve');

        $this->assertEquals(['input_tokens' => 1500, 'output_tokens' => 750], $totals);
    }

    public function testSumTokensByUserReturnsZeroWhenNoRows(): void {
        $this->qb->method('executeQuery')->willReturn($this->result);
        $this->result->method('fetch')->willReturn(false);
        $this->result->method('closeCursor')->willReturn(true);

        $mapper = new UsageStatMapper($this->db);
        $totals = $mapper->sumTokensByUser('eve');

        $this->assertEquals(['input_tokens' => 0, 'output_tokens' => 0], $totals);
    }

    public function testTableName(): void {
        $this->qb->expects($this->once())
            ->method('from')
            ->with('aiquila_usage_stats')
            ->willReturnSelf();

        $mapper = $this->makeMapper();
        $mapper->method('findEntities')->willReturn([]);
        $mapper->findByUser('eve');
    }
}
