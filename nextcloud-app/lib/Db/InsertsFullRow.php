<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Db;

/**
 * Makes insert() write every field of a new entity, defaults included.
 *
 * Entity::setter() returns early when the value equals the current one, so a
 * field set to its property default is never marked updated and insert()
 * leaves its column out. A NOT NULL column without a database default then
 * fails the insert (a 'file' project path), and one with a database default
 * silently takes that instead of the entity's value.
 *
 * Call markFieldsForInsert() at the end of the constructor. fromRow() resets
 * the marks after hydrating, so updates still write only what changed.
 */
trait InsertsFullRow {
    protected function markFieldsForInsert(): void {
        foreach (array_keys($this->getFieldTypes()) as $field) {
            if ($field !== 'id' && $this->$field !== null) {
                $this->markFieldUpdated($field);
            }
        }
    }
}
