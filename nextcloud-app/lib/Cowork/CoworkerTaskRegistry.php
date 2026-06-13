<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Cowork;

/**
 * Registry of available cowork task types, keyed by id.
 *
 * Task types are injected by the DI container (see Application::register) so the
 * set is extensible without touching the service or the executor.
 */
class CoworkerTaskRegistry {
    /** @var array<string, CoworkerTaskType> */
    private array $types = [];

    /**
     * @param iterable<CoworkerTaskType> $taskTypes
     */
    public function __construct(iterable $taskTypes = []) {
        foreach ($taskTypes as $type) {
            $this->register($type);
        }
    }

    public function register(CoworkerTaskType $type): void {
        $this->types[$type->getId()] = $type;
    }

    public function has(string $id): bool {
        return isset($this->types[$id]);
    }

    public function get(string $id): CoworkerTaskType {
        if (!isset($this->types[$id])) {
            throw new \InvalidArgumentException("Unknown cowork task type: $id");
        }
        return $this->types[$id];
    }

    /**
     * @return array<string, CoworkerTaskType>
     */
    public function all(): array {
        return $this->types;
    }

    /**
     * Lightweight descriptors for UIs / API.
     *
     * @return list<array{id: string, label: string, family: string}>
     */
    public function describe(): array {
        $out = [];
        foreach ($this->types as $type) {
            $out[] = [
                'id' => $type->getId(),
                'label' => $type->getLabel(),
                'family' => $type->getFamily(),
            ];
        }
        return $out;
    }
}
