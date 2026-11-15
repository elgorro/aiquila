<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Tests\Unit\Migration;

use OCA\AIquila\Migration\Version0028Date20261115000000;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Schema\IColumn;
use OCP\DB\Schema\ITable;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

class Version0028Date20261115000000Test extends TestCase {
    public function testCreatesTheTableWithAUniqueSlugPerScopeAndOwner(): void {
        $columns = [];
        $table = $this->createMock(ITable::class);
        $column = $this->createStub(IColumn::class);
        $table->method('addColumn')->willReturnCallback(function (string $name, mixed $type, array $options) use (&$columns, $column): IColumn {
            $columns[$name] = $options;
            return $column;
        });
        $table->expects($this->once())->method('setPrimaryKey')->with(['id']);
        $table->expects($this->once())->method('addUniqueIndex')->with(['scope', 'user_id', 'slug'], 'aiquila_seq_slug_uniq');
        $schema = $this->createMock(ISchemaWrapper::class);
        $schema->method('hasTable')->willReturn(false);
        $schema->expects($this->once())->method('createTable')->with('aiquila_sequences')->willReturn($table);

        $result = (new Version0028Date20261115000000())->changeSchema($this->createStub(IOutput::class), static fn () => $schema, []);

        $this->assertSame($schema, $result);
        $this->assertSame(['id', 'scope', 'user_id', 'author_id', 'slug', 'title', 'description', 'definition', 'pinned', 'enabled', 'created_at', 'updated_at'], array_keys($columns));
        // Index names stay within the 30 characters Oracle allows; slugs fit the column.
        $this->assertLessThanOrEqual(30, strlen('aiquila_seq_slug_uniq'));
        $this->assertGreaterThanOrEqual(48, $columns['slug']['length']);
    }

    public function testDoesNothingWhenTheTableExists(): void {
        $schema = $this->createMock(ISchemaWrapper::class);
        $schema->method('hasTable')->willReturn(true);
        $schema->expects($this->never())->method('createTable');

        $this->assertNull((new Version0028Date20261115000000())->changeSchema($this->createStub(IOutput::class), static fn () => $schema, []));
    }
}
