<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Cowork;

use OCA\AIquila\Db\Coworker;
use OCA\AIquila\Db\CoworkerRun;

/**
 * A cowork task type — the unit of work a coworker performs on each run.
 *
 * Task types are grouped into families (e.g. "vision" for image/audio/video
 * media tasks). They are registered in {@see CoworkerTaskRegistry} and resolved
 * by their stable id (e.g. "vision:classify").
 */
interface CoworkerTaskType {
    /** Stable id, e.g. "vision:classify". */
    public function getId(): string;

    /** Human-readable label for UIs. */
    public function getLabel(): string;

    /** Family this task type belongs to, e.g. "vision". */
    public function getFamily(): string;

    /**
     * Validate task-specific options (decoded from Coworker::getOptions()).
     * Throw \InvalidArgumentException on invalid input.
     *
     * @param array<string, mixed> $options
     */
    public function validateOptions(array $options): void;

    /**
     * Execute the task for one coworker run.
     *
     * Implementations should call $progress(int $processed, int $total) as they
     * make progress so the run row reflects live status.
     *
     * @param callable(int, int): void $progress
     * @return array{itemsTotal: int, itemsProcessed: int, summary: string}
     */
    public function run(Coworker $coworker, CoworkerRun $run, callable $progress): array;
}
