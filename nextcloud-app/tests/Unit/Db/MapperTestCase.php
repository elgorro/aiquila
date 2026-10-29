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
 * (all fluent methods return self). A test that sets expectations on the
 * query or expression builder first swaps in a mock with mockQueryBuilder()
 * or mockExpressionBuilder(); the stubbed behaviour carries over.
 */
abstract class MapperTestCase extends TestCase {
    protected $db;
    protected $qb;
    protected $expr;
    protected $func;
    protected $result;

    protected function setUp(): void {
        $this->db     = $this->createStub(IDBConnection::class);
        $this->qb     = $this->fluent($this->createStub(IQueryBuilder::class));
        $this->expr   = $this->comparisons($this->createStub(IExpressionBuilder::class));
        $this->func   = $this->createStub(IFunctionBuilder::class);
        $this->result = $this->createStub(IResult::class);

        $this->db->method('getQueryBuilder')->willReturnCallback(fn () => $this->qb);

        // IFunctionBuilder returns IQueryFunction objects, not strings; stand in
        // with one that stringifies back to the column it was called with.
        $this->func->method('sum')->willReturnCallback($this->queryFunction(...));
        $this->func->method('count')->willReturnCallback($this->queryFunction(...));
    }

    protected function mockQueryBuilder(): void {
        $this->qb = $this->fluent($this->createMock(IQueryBuilder::class));
    }

    protected function mockExpressionBuilder(): void {
        $this->expr = $this->comparisons($this->createMock(IExpressionBuilder::class));
    }

    /**
     * A double of $class with only $methods replaced, built on the stubbed connection.
     *
     * @template T of object
     * @param class-string<T> $class
     * @param list<string> $methods
     * @return T
     */
    protected function partialMapper(string $class, array $methods, bool $mock): object {
        return $mock
            ? $this->getMockBuilder($class)->setConstructorArgs([$this->db])->onlyMethods($methods)->getMock()
            : $this->getStubBuilder($class)->setConstructorArgs([$this->db])->onlyMethods($methods)->getStub();
    }

    /** Stub the entire fluent IQueryBuilder chain. */
    private function fluent(IQueryBuilder $qb): IQueryBuilder {
        foreach (['select', 'selectAlias', 'from', 'where', 'andWhere', 'orderBy',
                  'groupBy', 'setMaxResults', 'setFirstResult', 'delete'] as $method) {
            $qb->method($method)->willReturnSelf();
        }

        $qb->method('expr')->willReturnCallback(fn () => $this->expr);
        $qb->method('func')->willReturnCallback(fn () => $this->func);
        // Return the first argument cast to string as a stand-in for the placeholder
        $qb->method('createNamedParameter')->willReturnCallback(fn($v) => (string) $v);

        return $qb;
    }

    private function comparisons(IExpressionBuilder $expr): IExpressionBuilder {
        $expr->method('eq')->willReturn('1=1');
        $expr->method('lte')->willReturn('1<=1');
        $expr->method('gte')->willReturn('1>=1');

        return $expr;
    }

    protected function queryFunction(mixed $expression): IQueryFunction {
        $function = $this->createStub(IQueryFunction::class);
        $function->method('__toString')->willReturn((string) $expression);

        return $function;
    }
}
