<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Tests\Unit\Db;

use OCA\AIquila\Db\Sequence;
use OCA\AIquila\Db\SequenceMapper;

class SequenceMapperTest extends MapperTestCase {
    private function makeMapper(bool $mock = false): SequenceMapper {
        return $this->partialMapper(SequenceMapper::class, ['findEntities', 'findEntity'], $mock);
    }

    public function testFindAllByUserFiltersOwnerAndUserScope(): void {
        $this->mockQueryBuilder();
        $params = [];
        $this->qb->method('createNamedParameter')->willReturnCallback(static function ($v) use (&$params): string {
            $params[] = $v;
            return (string)$v;
        });
        $this->qb->expects($this->once())->method('orderBy')->with('slug', 'ASC')->willReturnSelf();

        $mapper = $this->makeMapper();
        $mapper->method('findEntities')->willReturn([]);
        $mapper->findAllByUser('carol');

        $this->assertSame(['carol', Sequence::SCOPE_USER], $params);
    }

    public function testFindAllInstanceFiltersInstanceScope(): void {
        $this->mockQueryBuilder();
        $params = [];
        $this->qb->method('createNamedParameter')->willReturnCallback(static function ($v) use (&$params): string {
            $params[] = $v;
            return (string)$v;
        });
        $this->qb->expects($this->once())->method('orderBy')->with('slug', 'ASC')->willReturnSelf();

        $mapper = $this->makeMapper();
        $mapper->method('findEntities')->willReturn([]);
        $mapper->findAllInstance();

        $this->assertSame([Sequence::SCOPE_INSTANCE], $params);
    }

    public function testEntityRoundTripsItsDefinition(): void {
        $s = new Sequence();
        $s->setDefinitionArray(['inputs' => [], 'steps' => [['id' => 'a', 'tool' => 't', 'arguments' => [], 'onFailure' => 'stop']]]);
        $this->assertStringContainsString('"arguments":{}', $s->getDefinition());
        $this->assertSame('t', $s->getDefinitionArray()['steps'][0]['tool']);

        $s->setDefinition('not json');
        $this->assertSame(['inputs' => [], 'steps' => []], $s->getDefinitionArray());
    }
}
