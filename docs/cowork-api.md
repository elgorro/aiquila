# AIquila Cowork Management API

AIquila's **Cowork engine** runs persistent, scheduled AI jobs ("coworkers") —
for example, nightly image classification that writes labels as Nextcloud system
tags. The `ICoworkManager` public service lets **other Nextcloud apps register,
steer, deregister and verify their own cowork jobs**, and contribute custom task
types so jobs do app-specific work.

This is an in-process PHP API. It is separate from the user/MCP REST surface
(`CoworkerController`): jobs created through `ICoworkManager` are owned by the
calling app, are scoped to it, and do **not** appear in the AIquila `/cowork` UI
or the MCP `list_coworkers` output — but they are executed on schedule by the same
60-second background job as user-owned coworkers.

For the simpler one-shot AI API (`ask`, `summarize`, `analyzeFile`), see the
[Internal API Guide](internal-api.md).

## Overview

- **Ownership** — every method takes the caller's `appId` as its first argument
  and is scoped to it. An app can only see and steer coworkers it registered.
- **Custom task types** — apps may contribute their own `CoworkerTaskType` via
  `registerTaskType()`, so a job can do anything, not just the built-in
  `vision:classify`.
- **Verification** — `verify()` confirms ownership and last-run status, and
  validates (without executing) that a job won't break the engine.

## Getting Started

```php
<?php
$cowork = \OC::$server->get(\OCA\AIquila\Public\ICoworkManager::class);

// Register a nightly image-classification job owned by "myapp", running as alice.
$job = $cowork->register('myapp', 'alice', [
    'title' => 'Nightly classify',
    'task_type' => 'vision:classify',
    'cron_schedule' => '0 3 * * *',
    'input_path' => '/Photos',
    'model' => 'anthropic',           // or 'mistral' (Pixtral)
    'options' => ['maxTags' => 8, 'recursive' => true],
]);

// Verify it is healthy before relying on it.
$status = $cowork->verify('myapp', $job['id']);
if (!$status['valid']) {
    // Inspect $status['issues'] — e.g. ["input path not found: /Photos"]
}
```

## Coworker Configuration Fields

Passed in the `$config` / `$changes` array of `register()` and `update()`:

| Field | Type | Notes |
|-------|------|-------|
| `title` | string | Human-readable name. |
| `description` | string\|null | Optional. |
| `task_type` | string | Registered task-type id, e.g. `vision:classify`. |
| `model` | string\|null | Provider override: `anthropic` or `mistral`. |
| `cron_schedule` | string | 5-field cron (`min hour dom month dow`), e.g. `0 3 * * *`. |
| `input_type` | string | Default `folder`. |
| `input_path` | string\|null | Nextcloud path resolved against the run user's folder. |
| `output_type` | string | Default `system_tags`. |
| `output_path` | string\|null | Task-type dependent. |
| `is_active` | bool | Disabled jobs are not scheduled. |
| `paused` | bool | Paused jobs are not scheduled but stay configured. |
| `options` | array | Task-type-specific options (validated by the task type). |

Returned coworker arrays additionally include `id`, `userId`, `ownerApp`,
`lastRunAt`, `nextRunAt`, `lastStatus`, `lastError`, `createdAt`, `updatedAt`.

## API Reference

All methods throw `OCP\AppFramework\Db\DoesNotExistException` if the coworker is
not owned by the calling `appId`, and `\InvalidArgumentException` on invalid
task type / cron schedule / options.

### `registerTaskType(CoworkerTaskType $type): void`

Contribute a custom task type to the Cowork engine. **Call this during your app's
`boot()`/`register()`** so the type is present both when registering coworkers and
when the background worker executes them. Re-registering the same id overwrites the
previous one.

```php
// In your app's Application::boot()
$cowork = \OC::$server->get(\OCA\AIquila\Public\ICoworkManager::class);
$cowork->registerTaskType($this->getContainer()->get(MyTaskType::class));
```

Your task type implements `OCA\AIquila\Cowork\CoworkerTaskType`:

```php
interface CoworkerTaskType {
    public function getId(): string;        // stable id, e.g. "myapp:digest"
    public function getLabel(): string;     // human label
    public function getFamily(): string;    // grouping, e.g. "text"
    public function validateOptions(array $options): void;   // throw on invalid
    public function run(Coworker $coworker, CoworkerRun $run, callable $progress): array;
    // run() returns ['itemsTotal' => int, 'itemsProcessed' => int, 'summary' => string]
    // and should call $progress(int $processed, int $total) as it goes.
}
```

### `register(string $appId, string $userId, array $config): array`

Create a coworker owned by the calling app. `$userId` is the user the job runs as
(file access, API key). Returns the created coworker (including `id`).

### `update(string $appId, int $id, array $changes): array`

Apply a partial update. Returns the updated coworker.

### `deregister(string $appId, int $id): void`

Delete a coworker and its run history.

### `setPaused(string $appId, int $id, bool $paused): array`

Pause (`true`) or resume (`false`). Returns the updated coworker.

### `setActive(string $appId, int $id, bool $active): array`

Enable (`true`) or disable (`false`). Returns the updated coworker.

### `runNow(string $appId, int $id): array`

Run the coworker immediately (synchronous). Returns the completed run record
(`status`, `itemsTotal`, `itemsProcessed`, `summary`, `error`, `startedAt`,
`finishedAt`).

### `get(string $appId, int $id): array`

Get a single owned coworker.

### `list(string $appId, ?string $userId = null): array`

List coworkers owned by the app, optionally filtered to a single user.

### `getRuns(string $appId, int $id, int $limit = 20): array`

Recent run history for an owned coworker, newest first.

### `verify(string $appId, int $id): array`

Confirm ownership and last status, and validate — **without executing** — that the
job will not break the engine: task type registered, cron schedule valid, options
valid, input path resolvable.

**Returns:**
```php
[
    'owned' => bool,        // false (with valid=false) if not owned by $appId
    'valid' => bool,        // true when issues is empty
    'issues' => string[],   // e.g. ["unknown task type: bogus:type", "input path not found: /Photos"]
    'lastStatus' => ?string // 'success' | 'error' | null
]
```

```php
$status = $cowork->verify('myapp', $job['id']);
if (!$status['valid']) {
    foreach ($status['issues'] as $issue) {
        \OCP\Util::writeLog('myapp', "coworker unhealthy: $issue", \OCP\Util::WARN);
    }
}
```

## Full Example

```php
<?php
$cowork = \OC::$server->get(\OCA\AIquila\Public\ICoworkManager::class);

$cw = $cowork->register('myapp', 'admin', [
    'title' => 't',
    'task_type' => 'vision:classify',
    'input_path' => '/Photos',
]);

$cowork->verify('myapp', $cw['id']);     // ['owned' => true, 'valid' => true, ...]
$cowork->verify('otherapp', $cw['id']);  // ['owned' => false, 'valid' => false, ...]

$cowork->setPaused('myapp', $cw['id'], true);   // pause scheduling
$cowork->setPaused('myapp', $cw['id'], false);  // resume

$run = $cowork->runNow('myapp', $cw['id']);     // execute now
$history = $cowork->getRuns('myapp', $cw['id']); // recent runs

$cowork->deregister('myapp', $cw['id']);        // delete + history
```

## Notes

- **App-owned jobs are hidden from the user UI/MCP list** but run on schedule like
  any other coworker. Use `list()` / `get()` to inspect them programmatically.
- **Custom task types live in memory**, registered per request. The cron worker
  boots all apps, so a type registered in your app's `boot()` is available when the
  job runs. If a task type is missing at run time, `verify()` reports
  `unknown task type: …` and the scheduled run fails gracefully (captured on the
  run record, never throwing at the job level).
- Provider keys stay encrypted server-side; the `model` field only selects which
  configured provider (`anthropic` / `mistral`) a vision job uses.

## Support

- GitHub: https://github.com/elgorro/aiquila
- Documentation: https://github.com/elgorro/aiquila/tree/main/docs
