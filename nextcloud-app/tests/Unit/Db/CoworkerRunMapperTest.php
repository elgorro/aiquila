<?php

declare(strict_types=1);

namespace OCA\AIquila\Tests\Unit\Db;

use OCA\AIquila\Db\CoworkerRun;
use OCA\AIquila\Db\CoworkerRunMapper;

class CoworkerRunMapperTest extends MapperTestCase {

    public function testTableName(): void {
        $this->mockQueryBuilder();

        $this->qb->expects($this->once())
            ->method('from')
            ->with('aiquila_coworker_runs')
            ->willReturnSelf();

        $this->qb->method('executeQuery')->willReturn($this->result);
        $this->result->method('fetch')->willReturn(false);
        $this->result->method('closeCursor')->willReturn(true);

        $mapper = new CoworkerRunMapper($this->db);
        $mapper->countByStatus();
    }

    public function testCountByStatusGroupsRows(): void {
        $this->qb->method('executeQuery')->willReturn($this->result);
        $this->result->method('fetch')->willReturnOnConsecutiveCalls(
            ['status' => 'success', 'run_count' => '7'],
            ['status' => 'error', 'run_count' => '2'],
            false,
        );
        $this->result->method('closeCursor')->willReturn(true);

        $mapper = new CoworkerRunMapper($this->db);
        $this->assertSame(['success' => 7, 'error' => 2], $mapper->countByStatus());
    }

    public function testCountByStatusEmpty(): void {
        $this->qb->method('executeQuery')->willReturn($this->result);
        $this->result->method('fetch')->willReturn(false);
        $this->result->method('closeCursor')->willReturn(true);

        $mapper = new CoworkerRunMapper($this->db);
        $this->assertSame([], $mapper->countByStatus());
    }

    /** The run list names its columns; a new column must not be left out of it. */
    public function testRunListSelectsEveryColumnButTheLog(): void {
        $this->mockQueryBuilder();
        $selected = [];
        $this->qb->expects($this->once())->method('select')
            ->willReturnCallback(function (...$columns) use (&$selected) {
                $selected = $columns;
                return $this->qb;
            });
        $this->qb->method('executeQuery')->willReturn($this->result);
        $this->result->method('fetch')->willReturn(false);

        (new CoworkerRunMapper($this->db))->findByCoworker(7, null);

        $entity = new CoworkerRun();
        $expected = array_map(
            $entity->propertyToColumn(...),
            array_keys(array_diff_key($entity->getFieldTypes(), ['log' => true]))
        );
        sort($expected);
        sort($selected);
        $this->assertSame($expected, $selected);
    }
}
