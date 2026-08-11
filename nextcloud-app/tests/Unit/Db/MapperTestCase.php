<?php

declare(strict_types=1);

namespace OCA\AIquila\Tests\Unit\Db;

use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
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
        foreach (['select', 'selectAlias', 'from', 'where', 'andWhere', 'orderBy',
                  'groupBy', 'setMaxResults', 'setFirstResult', 'delete'] as $method) {
            $this->qb->method($method)->willReturnSelf();
        }

        $this->qb->method('expr')->willReturn($this->expr);
        $this->qb->method('func')->willReturn($this->func);
        // Return the first argument cast to string as a stand-in for the placeholder
        $this->qb->method('createNamedParameter')->willReturnCallback(fn($v) => (string) $v);

        $this->expr->method('eq')->willReturn('1=1');
        $this->expr->method('lte')->willReturn('1<=1');
        $this->expr->method('gte')->willReturn('1>=1');
        // IFunctionBuilder returns IQueryFunction objects, not strings; stand in
        // with one that stringifies back to the column it was called with.
        $this->func->method('sum')->willReturnCallback($this->queryFunction(...));
        $this->func->method('count')->willReturnCallback($this->queryFunction(...));
    }

    protected function queryFunction(mixed $expression): IQueryFunction {
        $function = $this->createMock(IQueryFunction::class);
        $function->method('__toString')->willReturn((string) $expression);

        return $function;
    }
}
