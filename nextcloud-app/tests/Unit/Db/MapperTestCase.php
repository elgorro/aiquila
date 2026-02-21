<?php

declare(strict_types=1);

namespace OCA\AIquila\Tests\Unit\Db;

use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * Shared setup for mapper unit tests.
 *
 * Each test gets a fresh IDBConnection + fully-stubbed IQueryBuilder
 * (all fluent methods return self). Individual tests may override specific
 * expectations on top of these stubs.
 */
abstract class MapperTestCase extends TestCase {
    protected $db;
    protected $qb;
    protected $expr;
    protected $func;
    protected $result;

    protected function setUp(): void {
        $this->db     = $this->createMock(IDBConnection::class);
        $this->qb     = $this->createMock(IQueryBuilder::class);
        $this->expr   = $this->createMock(IExpressionBuilder::class);
        $this->func   = $this->createMock(IFunctionBuilder::class);
        $this->result = $this->createMock(IResult::class);

        $this->db->method('getQueryBuilder')->willReturn($this->qb);

        // Stub the entire fluent IQueryBuilder chain
        foreach (['select', 'from', 'where', 'andWhere', 'orderBy',
                  'setMaxResults', 'setFirstResult', 'delete'] as $method) {
            $this->qb->method($method)->willReturnSelf();
        }

        $this->qb->method('expr')->willReturn($this->expr);
        $this->qb->method('func')->willReturn($this->func);
        // Return the first argument as a stand-in for the placeholder string
        $this->qb->method('createNamedParameter')->willReturnArgument(0);

        $this->expr->method('eq')->willReturn('1=1');
        $this->expr->method('lte')->willReturn('1<=1');
        $this->func->method('sum')->willReturnArgument(1); // returns alias
    }
}
