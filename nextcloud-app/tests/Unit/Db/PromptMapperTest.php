<?php

declare(strict_types=1);

namespace OCA\AIquila\Tests\Unit\Db;

use OCA\AIquila\Db\Prompt;
use OCA\AIquila\Db\PromptMapper;
use OCP\DB\QueryBuilder\IExpressionBuilder;

class PromptMapperTest extends MapperTestCase {

    private function makeMapper(bool $mock = false): PromptMapper {
        return $this->partialMapper(PromptMapper::class, ['findEntities', 'findEntity'], $mock);
    }

    public function testFindAllByUserCallsFindEntities(): void {
        $p = new Prompt();
        $p->setUserId('carol');

        $mapper = $this->makeMapper(mock: true);
        $mapper->expects($this->once())
            ->method('findEntities')
            ->willReturn([$p]);

        $result = $mapper->findAllByUser('carol');

        $this->assertCount(1, $result);
        $this->assertSame($p, $result[0]);
    }

    public function testFindAllByUserOrdersByTitleAsc(): void {
        $this->mockQueryBuilder();

        $this->qb->expects($this->once())
            ->method('orderBy')
            ->with('title', 'ASC')
            ->willReturnSelf();

        $mapper = $this->makeMapper();
        $mapper->method('findEntities')->willReturn([]);
        $mapper->findAllByUser('carol');
    }

    public function testFindAllByUserWithoutActiveOnlyFiltersOnlyScope(): void {
        $this->mockQueryBuilder();

        // One andWhere(): the user scope. No is_active filter.
        $this->qb->expects($this->once())->method('andWhere')->willReturnSelf();

        $mapper = $this->makeMapper();
        $mapper->method('findEntities')->willReturn([]);
        $mapper->findAllByUser('carol', false);
    }

    public function testFindAllByUserWithActiveOnlyAddsAndWhere(): void {
        $this->mockQueryBuilder();

        // Scope plus is_active.
        $this->qb->expects($this->exactly(2))
            ->method('andWhere')
            ->willReturnSelf();

        $mapper = $this->makeMapper();
        $mapper->method('findEntities')->willReturn([]);
        $mapper->findAllByUser('carol', true);
    }

    public function testFindAllByUserExcludesInstancePrompts(): void {
        $seen = [];
        $this->recordEq($seen);

        $mapper = $this->makeMapper();
        $mapper->method('findEntities')->willReturn([]);
        $mapper->findAllByUser('carol');

        $this->assertSame('user', $seen['scope'] ?? null);
        $this->assertSame('carol', $seen['user_id'] ?? null);
    }

    public function testFindByIdAndUserNeverMatchesInstanceScope(): void {
        $seen = [];
        $this->recordEq($seen);

        $mapper = $this->makeMapper();
        $mapper->method('findEntity')->willReturn(new Prompt());
        $mapper->findByIdAndUser(7, 'carol');

        $this->assertSame('user', $seen['scope'] ?? null);
    }

    public function testFindAllInstanceFiltersInstanceScope(): void {
        $seen = [];
        $this->recordEq($seen);

        $mapper = $this->makeMapper();
        $mapper->method('findEntities')->willReturn([]);
        $mapper->findAllInstance();

        $this->assertSame(['scope' => 'instance'], $seen);
    }

    public function testFindByIdAndUserCallsFindEntity(): void {
        $p = new Prompt();

        $mapper = $this->makeMapper(mock: true);
        $mapper->expects($this->once())
            ->method('findEntity')
            ->willReturn($p);

        $result = $mapper->findByIdAndUser(7, 'carol');
        $this->assertSame($p, $result);
    }

    public function testTableName(): void {
        $this->mockQueryBuilder();

        $this->qb->expects($this->once())
            ->method('from')
            ->with('aiquila_prompts')
            ->willReturnSelf();

        $mapper = $this->makeMapper();
        $mapper->method('findEntities')->willReturn([]);
        $mapper->findAllByUser('carol');
    }

    /**
     * Swap in an expression builder that records each eq() column and value.
     *
     * @param array<string, mixed> $seen
     */
    private function recordEq(array &$seen): void {
        $expr = $this->createStub(IExpressionBuilder::class);
        $expr->method('eq')->willReturnCallback(static function ($column, $value) use (&$seen): string {
            $seen[(string) $column] = $value;
            return '1=1';
        });
        $this->expr = $expr;
    }
}
