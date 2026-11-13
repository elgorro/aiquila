<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Db;

use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;

/**
 * A mapper whose writes leave the entity clean.
 *
 * QBMapper::insert() and update() never clear an entity's updated-field marks
 * (Entity::getUpdatedFields()), so each later update() of the same instance
 * writes every field ever set on it again. With InsertsFullRow that is the
 * whole row after an insert. Another request that changed a column in between
 * loses its change: a stopped coworker run went back to `running`, a renamed
 * conversation back to its old title, a rotated OAuth refresh token back to
 * the one it replaced.
 *
 * Here the marks are reset once a write succeeded, so the next update() of
 * the same instance writes only what changed since. A failed write keeps
 * them, so insertOrUpdate() still updates every field after a failed insert.
 *
 * @template T of Entity
 * @template-extends QBMapper<T>
 */
abstract class ChangeTrackingMapper extends QBMapper {
    /**
     * @psalm-param T $entity
     * @psalm-return T
     */
    public function insert(Entity $entity): Entity {
        $saved = parent::insert($entity);
        $saved->resetUpdatedFields();
        return $saved;
    }

    /**
     * @psalm-param T $entity
     * @psalm-return T
     */
    public function update(Entity $entity): Entity {
        $saved = parent::update($entity);
        $saved->resetUpdatedFields();
        return $saved;
    }
}
