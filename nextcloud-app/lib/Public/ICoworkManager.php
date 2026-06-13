<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\AIquila\Public;

use OCA\AIquila\Cowork\CoworkerTaskType;

/**
 * Public Cowork management API.
 *
 * Lets other Nextcloud apps register, steer, deregister and verify their own
 * cowork jobs ("coworkers") — persistent, scheduled AI tasks run by the AIquila
 * Cowork engine. Every method takes the caller's app id and is scoped to it: an
 * app can only see and steer coworkers it registered. App-owned coworkers do not
 * appear in the AIquila UI / MCP list, but are executed on schedule by the same
 * background job as user-owned ones.
 *
 * Example usage from another app's bootstrap / service:
 *
 * $cowork = \OC::$server->get(\OCA\AIquila\Public\ICoworkManager::class);
 *
 * // Contribute an app-specific task type (do this in your app's boot()).
 * $cowork->registerTaskType($myTaskType);
 *
 * // Register a scheduled job owned by this app.
 * $job = $cowork->register('myapp', 'alice', [
 *     'title' => 'Nightly classify',
 *     'task_type' => 'vision:classify',
 *     'cron_schedule' => '0 3 * * *',
 *     'input_path' => '/Photos',
 * ]);
 *
 * // Verify it is healthy before relying on it.
 * $status = $cowork->verify('myapp', $job['id']);
 * if (!$status['valid']) { /* inspect $status['issues'] *\/ }
 */
interface ICoworkManager {
    /**
     * Contribute a custom task type to the Cowork engine.
     *
     * Must be called during your app's boot()/register() so the type is present
     * both when registering coworkers and when the background worker executes
     * them. Re-registering the same id overwrites the previous one.
     */
    public function registerTaskType(CoworkerTaskType $type): void;

    /**
     * Register (create) a coworker owned by the calling app.
     *
     * @param string $appId Calling app id; recorded as the owner.
     * @param string $userId User the job runs as (file access, API key).
     * @param array<string, mixed> $config Coworker fields: title, description,
     *   model, task_type, cron_schedule, input_type, input_path, output_type,
     *   output_path, is_active, paused, options.
     * @return array<string, mixed> The created coworker (includes 'id').
     * @throws \InvalidArgumentException on invalid task type / schedule / options.
     */
    public function register(string $appId, string $userId, array $config): array;

    /**
     * Apply a partial update to a coworker owned by the calling app.
     *
     * @param array<string, mixed> $changes
     * @return array<string, mixed> The updated coworker.
     * @throws \OCP\AppFramework\Db\DoesNotExistException if not owned by the app.
     * @throws \InvalidArgumentException on invalid values.
     */
    public function update(string $appId, int $id, array $changes): array;

    /**
     * Deregister (delete) a coworker owned by the calling app, with its history.
     *
     * @throws \OCP\AppFramework\Db\DoesNotExistException if not owned by the app.
     */
    public function deregister(string $appId, int $id): void;

    /**
     * Pause (true) or resume (false) a coworker owned by the calling app.
     *
     * @return array<string, mixed> The updated coworker.
     * @throws \OCP\AppFramework\Db\DoesNotExistException if not owned by the app.
     */
    public function setPaused(string $appId, int $id, bool $paused): array;

    /**
     * Enable (true) or disable (false) a coworker owned by the calling app.
     *
     * @return array<string, mixed> The updated coworker.
     * @throws \OCP\AppFramework\Db\DoesNotExistException if not owned by the app.
     */
    public function setActive(string $appId, int $id, bool $active): array;

    /**
     * Run a coworker owned by the calling app now (synchronous). Returns the run.
     *
     * @return array<string, mixed> The completed run record.
     * @throws \OCP\AppFramework\Db\DoesNotExistException if not owned by the app.
     */
    public function runNow(string $appId, int $id): array;

    /**
     * Get a single coworker owned by the calling app.
     *
     * @return array<string, mixed>
     * @throws \OCP\AppFramework\Db\DoesNotExistException if not owned by the app.
     */
    public function get(string $appId, int $id): array;

    /**
     * List coworkers owned by the calling app, optionally filtered to a user.
     *
     * @return list<array<string, mixed>>
     */
    public function list(string $appId, ?string $userId = null): array;

    /**
     * Recent run history for a coworker owned by the calling app, newest first.
     *
     * @return list<array<string, mixed>>
     * @throws \OCP\AppFramework\Db\DoesNotExistException if not owned by the app.
     */
    public function getRuns(string $appId, int $id, int $limit = 20): array;

    /**
     * Verify a coworker owned by the calling app: confirm ownership and last
     * status, and validate (without executing) that it will not break the engine
     * — task type registered, cron schedule valid, options valid, input path
     * resolvable.
     *
     * @return array{owned: bool, valid: bool, issues: list<string>, lastStatus: ?string}
     *   When the coworker is not owned by the app: owned=false, valid=false.
     */
    public function verify(string $appId, int $id): array;
}
