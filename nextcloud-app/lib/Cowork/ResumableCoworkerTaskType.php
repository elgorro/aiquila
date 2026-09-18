<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Cowork;

use OCA\AIquila\Db\Coworker;
use OCA\AIquila\Db\CoworkerRun;

/**
 * A task type whose work can outlive the tick that started it.
 *
 * The Anthropic Batch API is the reason this exists: a batch is allowed to
 * take up to 24 hours, and Nextcloud's cron worker runs background jobs one
 * after another in a single process, so a task that polled until the batch
 * ended would starve every other job on the instance.
 *
 * Such a task instead submits its work, returns `pending: true` together with
 * whatever it needs to pick the work back up, and is called again later
 * through {@see resume()} until it returns a terminal result. The state is
 * persisted on the run row and is opaque to everything but the task type that
 * wrote it.
 */
interface ResumableCoworkerTaskType extends CoworkerTaskType {
    /**
     * Continue a run that reported `pending`.
     *
     * Returns the same shape as {@see CoworkerTaskType::run()}: `pending` set
     * again (with fresh state) when the work still has not finished, or
     * absent when the run is done. Throwing marks the run as failed.
     *
     * @param array<string, mixed> $state the state the previous call returned
     * @param callable(int, int): void $progress
     * @return array{itemsTotal: int, itemsProcessed: int, summary: string, pending?: bool, state?: array<string, mixed>}
     */
    public function resume(Coworker $coworker, CoworkerRun $run, array $state, callable $progress): array;
}
