<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Batch;

use OCA\AIquila\Cowork\UserPath;
use OCA\AIquila\Db\CoworkerRun;
use OCA\AIquila\Service\ClaudeSDKService;
use OCA\AIquila\Service\Provider\LLMProviderFactory;
use OCA\AIquila\Service\Provider\LLMProviderInterface;
use OCA\AIquila\Service\UsageStatService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use OCP\SystemTag\TagNotFoundException;
use Psr\Log\LoggerInterface;

/**
 * The batch document pipeline: walk files, ask one question about each, write
 * each answer to a file.
 *
 * On the Anthropic provider the whole set goes out as a single message batch:
 * half the token price, and one request instead of N. A batch may take up to
 * 24 hours, so {@see process()} does not wait for it — it submits and returns
 * `pending` with the state {@see resume()} needs to collect the results once
 * the batch has ended. Every other provider falls back to a synchronous loop,
 * slower and at full price.
 *
 * It knows nothing about coworkers or schedules. The caller owns the run row,
 * passes a {@see BatchContext}, and persists whatever comes back.
 *
 * @psalm-type BatchSkip = array{item: string, reason: string}
 * @psalm-type BatchRunResult = array{itemsTotal: int, itemsProcessed: int, itemsFailed?: int, itemsSkipped?: int, summary: string, pending?: bool, state?: array<string, mixed>, outputs?: list<string>}
 * @psalm-type BatchSelection = array{files: list<File>, skipped: list<BatchSkip>, capped: bool}
 */
class BatchDocumentRunner {

    /** Hard cap on files per run, to bound cost and runtime. */
    public const MAX_ITEMS_PER_RUN = 200;

    /** Files larger than this are skipped rather than truncated. */
    public const DEFAULT_MAX_BYTES = 1048576;

    /**
     * Anthropic expires a batch at 24 hours. This is the point at which we
     * stop believing a batch record that has neither ended nor expired, so a
     * run cannot stay pending forever if its batch is lost.
     */
    public const BATCH_DEADLINE_SECONDS = 26 * 3600;

    /**
     * Mime prefixes and exact types read by default.
     *
     * Text only. A PDF's bytes are not text, and inlining them into a prompt
     * would send the model binary rather than a document — the Messages API
     * wants a base64 `document` block for that, which a batch request here
     * does not build. `application/pdf` is therefore deliberately absent.
     *
     * @var list<string>
     */
    public const DEFAULT_MIME_TYPES = ['text/', 'application/json'];

    /**
     * Hidden system tag on every output file. A folder walk skips files that
     * carry it, so one operation's output is never another's input: without
     * it, translating a folder that holds `a.summary.md` writes
     * `a.summary.german.md`, and the next summary run summarises both.
     * The own-suffix check still covers outputs written before this tag.
     */
    public const OUTPUT_TAG = 'aiquila:output';

    /** The output tag's id once looked up; '' when it does not exist yet. */
    private ?string $outputTagId = null;

    public function __construct(
        private readonly IRootFolder $rootFolder,
        private readonly LLMProviderFactory $providerFactory,
        private readonly ISystemTagManager $tagManager,
        private readonly ISystemTagObjectMapper $tagObjectMapper,
        private readonly LoggerInterface $logger,
        private readonly UsageStatService $usageStats,
    ) {
    }

    /**
     * Validate the options every operation shares.
     *
     * @param array<string, mixed> $options
     * @throws \InvalidArgumentException
     */
    public static function validateOptions(array $options): void {
        if (isset($options['recursive']) && !is_bool($options['recursive'])) {
            throw new \InvalidArgumentException('recursive must be a boolean');
        }
        if (isset($options['force']) && !is_bool($options['force'])) {
            throw new \InvalidArgumentException('force must be a boolean');
        }
        if (isset($options['maxBytesPerFile'])) {
            $bytes = $options['maxBytesPerFile'];
            if (!is_int($bytes) || $bytes < 1024 || $bytes > 20 * 1048576) {
                throw new \InvalidArgumentException('maxBytesPerFile must be between 1024 and 20971520');
            }
        }
        if (isset($options['outputFolder'])) {
            $folder = $options['outputFolder'];
            if (!is_string($folder) || $folder === '' || str_contains($folder, '..')) {
                throw new \InvalidArgumentException('outputFolder must be a path without ".."');
            }
        }
        if (isset($options['mimeTypes'])) {
            if (!is_array($options['mimeTypes']) || $options['mimeTypes'] === []) {
                throw new \InvalidArgumentException('mimeTypes must be a non-empty list');
            }
            foreach ($options['mimeTypes'] as $mime) {
                if (!is_string($mime) || $mime === '') {
                    throw new \InvalidArgumentException('mimeTypes entries must be non-empty strings');
                }
            }
        }
    }

    /** The output file's name for a source, e.g. `report.summary.md`. */
    public static function outputName(BatchOperation $operation, File $source, array $options): string {
        $base = pathinfo($source->getName(), PATHINFO_FILENAME);
        return $base . $operation->outputSuffix($options);
    }

    // ── Collection ────────────────────────────────────────────────────────

    /**
     * The documents under $root that still need work, at most
     * MAX_ITEMS_PER_RUN of them.
     *
     * A source whose output is at least as new as itself is skipped, as is
     * anything the operation itself wrote: without either check a nightly run
     * would re-bill the whole folder and then start summarising its own
     * summaries.
     *
     * @return list<File>
     */
    public function collect(BatchContext $ctx, Node $root): array {
        $matches = [];
        if ($root instanceof File) {
            if ($this->isCandidate($ctx, $root)) {
                $matches[] = $root;
            }
        } elseif ($root instanceof Folder) {
            $this->gather($ctx, $root, $this->configuredOutputFolderId($ctx), $matches);
        }

        return $matches;
    }

    /**
     * The work for a manual run over files and folders picked by the user,
     * at most MAX_ITEMS_PER_RUN files.
     *
     * Files picked one by one are what the user asked for, so they skip only
     * the mime filter: they are processed even when their output is fresh or
     * they look like an earlier output. Folders are walked with the same
     * rules as a coworker's input folder. A file reached twice counts once.
     *
     * Unlike a coworker's nightly walk, every file the walk passes over for
     * its type is reported in `skipped`: the user picked that folder and
     * should hear that its images were left alone. Finished files and earlier
     * outputs are not: leaving those is the walk doing its job.
     *
     * @param list<File> $files
     * @param list<Folder> $folders
     * @return BatchSelection
     */
    public function collectSelection(BatchContext $ctx, array $files, array $folders): array {
        $matches = [];
        $skipped = [];
        $seen = [];

        foreach ($files as $file) {
            if (isset($seen[$file->getId()])) {
                continue;
            }
            $seen[$file->getId()] = true;
            if (!$this->matchesMime($file, $ctx->mimeTypes())) {
                $skipped[] = ['item' => UserPath::of($file), 'reason' => self::unsupportedType($file)];
                continue;
            }
            $matches[] = $file;
        }

        $exclude = $this->configuredOutputFolderId($ctx);
        foreach ($folders as $folder) {
            if (count($matches) >= self::MAX_ITEMS_PER_RUN) {
                break;
            }
            $found = [];
            $this->gather($ctx, $folder, $exclude, $found, self::MAX_ITEMS_PER_RUN - count($matches), $seen, true, $skipped);
            array_push($matches, ...$found);
        }

        $capped = count($matches) >= self::MAX_ITEMS_PER_RUN;
        $matches = array_slice($matches, 0, self::MAX_ITEMS_PER_RUN);

        if ($ctx->requireWritableOutput) {
            $writable = [];
            foreach ($matches as $file) {
                if ($this->canWriteOutput($ctx, $file)) {
                    $writable[] = $file;
                } else {
                    $skipped[] = ['item' => UserPath::of($file), 'reason' => 'Its folder is read-only; choose an output folder'];
                }
            }
            $matches = $writable;
        }

        return ['files' => $matches, 'skipped' => $skipped, 'capped' => $capped];
    }

    /**
     * Whether the output of a source can be created where it would go. Only
     * the folder beside the source is checked here; a configured output
     * folder is checked once, when the run is started.
     */
    private function canWriteOutput(BatchContext $ctx, File $file): bool {
        if ($ctx->outputFolder !== '') {
            return true;
        }
        try {
            $parent = $file->getParent();
        } catch (\Throwable) {
            return false;
        }
        return $parent instanceof Folder && $parent->isCreatable();
    }

    /**
     * Whether one file found by the walk is work this run should do.
     *
     * Three separate reasons to say no, and they have to be asked here rather
     * than after the walk, because the walk stops at MAX_ITEMS_PER_RUN: a
     * filter applied afterwards would let the first 200 already-finished files
     * fill the quota and permanently starve everything after them.
     */
    private function isCandidate(BatchContext $ctx, File $file): bool {
        if (!$this->matchesMime($file, $ctx->mimeTypes())) {
            return false;
        }
        // Our own output is text too, and it lands in the tree we are walking.
        // Left in, each run would feed the previous run's output back through
        // and grow another generation of a.summary.summary.md every night.
        $suffix = $ctx->suffix();
        if ($suffix !== '' && str_ends_with($file->getName(), $suffix)) {
            return false;
        }
        if ($ctx->force()) {
            return true;
        }
        return !$this->alreadyDone($ctx, $file);
    }

    /** @param list<string> $mimeTypes */
    private function matchesMime(File $file, array $mimeTypes): bool {
        $mime = $file->getMimetype();
        foreach ($mimeTypes as $candidate) {
            // A trailing slash means a family ("text/"); anything else is exact.
            if (str_ends_with($candidate, '/') ? str_starts_with($mime, $candidate) : $mime === $candidate) {
                return true;
            }
        }
        return false;
    }

    /**
     * The configured output folder's id, so the walk can skip it.
     *
     * The shipped summarize template writes to /Documents/Summaries while
     * reading /Documents recursively, so without this the output folder is
     * part of its own input.
     */
    private function configuredOutputFolderId(BatchContext $ctx): ?int {
        if ($ctx->outputFolder === '') {
            return null;
        }
        try {
            $node = $this->rootFolder->getUserFolder($ctx->userId)->get($ctx->outputFolder);
        } catch (\Throwable) {
            return null;
        }
        return $node instanceof Folder ? $node->getId() : null;
    }

    /**
     * @param list<File> $matches
     * @param array<int, true> $seen file ids already taken, by id
     * @param list<BatchSkip> $skipped collects the files passed over for their type, when $report
     * @param-out list<BatchSkip> $skipped
     */
    private function gather(BatchContext $ctx, Folder $folder, ?int $excludeFolderId, array &$matches, int $limit = self::MAX_ITEMS_PER_RUN, array &$seen = [], bool $report = false, array &$skipped = []): void {
        $listing = $folder->getDirectoryListing();
        $outputs = $this->outputIds($listing);
        foreach ($listing as $child) {
            if (count($matches) >= $limit) {
                return;
            }
            if ($child instanceof File) {
                if (isset($seen[$child->getId()]) || isset($outputs[$child->getId()])) {
                    continue;
                }
                if ($report && !$this->matchesMime($child, $ctx->mimeTypes())) {
                    $seen[$child->getId()] = true;
                    $skipped[] = ['item' => UserPath::of($child), 'reason' => self::unsupportedType($child)];
                    continue;
                }
                if ($this->isCandidate($ctx, $child)) {
                    $seen[$child->getId()] = true;
                    $matches[] = $child;
                }
            } elseif ($ctx->recursive() && $child instanceof Folder) {
                if ($excludeFolderId !== null && $child->getId() === $excludeFolderId) {
                    continue;
                }
                $this->gather($ctx, $child, $excludeFolderId, $matches, $limit, $seen, $report, $skipped);
            }
        }
    }

    private static function unsupportedType(File $file): string {
        return 'Not a text document (' . $file->getMimetype() . ')';
    }

    private function alreadyDone(BatchContext $ctx, File $file): bool {
        try {
            $folder = $this->outputFolder($ctx, $file);
            $name = self::outputName($ctx->operation, $file, $ctx->options);
            if (!$folder->nodeExists($name)) {
                return false;
            }
            $existing = $folder->get($name);
            // The source is what the output describes, so an output older than
            // its source is stale and the file is worth doing again.
            return $existing->getMTime() >= $file->getMTime();
        } catch (\Throwable) {
            return false;
        }
    }

    // ── Submission ────────────────────────────────────────────────────────

    /**
     * Process collected files: submit one batch on a provider that has a
     * batch API (the result is `pending`), or work through them one by one.
     *
     * @param list<File> $files at least one
     * @param callable(int, int): void $progress
     * @param list<BatchSkip> $skipped files the caller already passed over
     *        (and logged), so the summary and `itemsSkipped` account for them
     * @return BatchRunResult
     */
    public function process(BatchContext $ctx, CoworkerRun $run, array $files, callable $progress, array $skipped = []): array {
        $provider = $this->providerFactory->getProviderForUser($ctx->userId, $ctx->providerId);

        if (!$ctx->allowBatch || !$provider instanceof ClaudeSDKService || !$provider->supportsBatch()) {
            return $this->runSynchronously($ctx, $run, $provider, $files, $progress, $skipped);
        }

        // One batch for the whole set. custom_id is the file id, which is
        // what results come back keyed by — the API returns them in any order.
        $requests = [];
        $fileIds = [];
        $total = count($files);
        foreach ($files as $file) {
            $content = $this->readContent($ctx, $file, $reason);
            if ($content === null) {
                $skipped[] = $this->skip($run, $file, $reason);
                continue;
            }
            $customId = 'f' . $file->getId();
            $requests[] = [
                'custom_id' => $customId,
                'messages' => [['role' => 'user', 'content' => $ctx->operation->buildPrompt($content, $ctx->options)]],
                'options' => ($ctx->requestOptions)($provider),
            ];
            $fileIds[$customId] = $file->getId();
        }

        if ($requests === []) {
            return [
                'itemsTotal' => $total,
                'itemsProcessed' => 0,
                'itemsSkipped' => count($skipped),
                'summary' => sprintf('Read none of the %d document(s).', $total) . self::describeSkips($skipped),
            ];
        }

        $batchId = $provider->submitBatch($requests, $ctx->userId);
        $run->appendLog(CoworkerRun::LOG_INFO, sprintf('Submitted %d document(s) to batch %s', count($requests), $batchId));

        return [
            'itemsTotal' => count($requests),
            'itemsProcessed' => 0,
            'itemsSkipped' => count($skipped),
            'summary' => sprintf(
                'Submitted %d document(s) to batch %s. Results arrive within 24 hours.',
                count($requests),
                $batchId
            ),
            'pending' => true,
            'state' => [
                'batch_id' => $batchId,
                'file_ids' => $fileIds,
                'submitted_at' => time(),
                'skipped' => $skipped,
                'options' => $ctx->options,
            ],
        ];
    }

    /**
     * Continue a run that {@see process()} left `pending`.
     *
     * $ctx carries the options the run was submitted with (`state.options`),
     * so a coworker edited meanwhile still writes where it said it would.
     *
     * @param array<string, mixed> $state
     * @param callable(int, int): void $progress
     * @return BatchRunResult
     */
    public function resume(BatchContext $ctx, CoworkerRun $run, array $state, callable $progress): array {
        $batchId = (string)($state['batch_id'] ?? '');
        /** @var array<string, int> $fileIds */
        $fileIds = is_array($state['file_ids'] ?? null) ? $state['file_ids'] : [];
        $submittedAt = (int)($state['submitted_at'] ?? 0);
        $total = count($fileIds);

        if ($batchId === '' || $fileIds === []) {
            throw new \RuntimeException('Pending run carries no batch to resume');
        }

        $provider = $this->providerFactory->getProviderForUser($ctx->userId, $ctx->providerId);
        if (!$provider instanceof ClaudeSDKService || !$provider->supportsBatch()) {
            throw new \RuntimeException('The provider that submitted this batch is no longer available');
        }

        $status = $provider->getBatchStatus($batchId, $ctx->userId);
        if (!$status['ended']) {
            // Anthropic expires a batch at 24 h, so past the deadline the
            // record itself is gone and nothing will ever end this run.
            if ($submittedAt > 0 && (time() - $submittedAt) > self::BATCH_DEADLINE_SECONDS) {
                throw new \RuntimeException("Batch $batchId did not end within 26 hours");
            }
            return [
                'itemsTotal' => $total,
                'itemsProcessed' => 0,
                'itemsSkipped' => count(self::skipsFromState($state)),
                'summary' => sprintf('Waiting for batch %s (%s).', $batchId, $status['status']),
                'pending' => true,
                'state' => $state,
            ];
        }

        return $this->collectResults($ctx, $run, $provider, $batchId, $fileIds, $state, $progress);
    }

    /**
     * Write one output file per succeeded request and account for everything
     * that did not produce one.
     *
     * @param array<string, int> $fileIds custom_id => file id
     * @param array<string, mixed> $state
     * @param callable(int, int): void $progress
     * @return BatchRunResult
     */
    private function collectResults(
        BatchContext $ctx,
        CoworkerRun $run,
        ClaudeSDKService $provider,
        string $batchId,
        array $fileIds,
        array $state,
        callable $progress,
    ): array {
        $userId = $ctx->userId;
        $results = $provider->fetchBatchResults($batchId, $userId);
        $total = count($fileIds);
        $verb = $ctx->operation->summaryVerb();

        $written = 0;
        $outputs = [];
        $failures = self::emptyFailures();

        $progress(0, $total);

        foreach ($fileIds as $customId => $fileId) {
            $name = 'file ' . $fileId;
            $item = null;
            try {
                $file = $this->fileById($userId, (int)$fileId);
                $name = $file?->getName() ?? $name;
                $item = $file !== null ? UserPath::of($file) : null;

                if (!isset($results[$customId])) {
                    $failures['missing'][] = $name;
                    $run->appendLog(CoworkerRun::LOG_ERROR, 'No result came back from the batch', $item ?? $name);
                    continue;
                }
                $result = $results[$customId];
                if (isset($result['error'])) {
                    $bucket = (string)($result['error_type'] ?? 'errored');
                    $failures[$bucket][] = $name . ' — ' . $result['error'];
                    $run->appendLog(self::logLevelFor($bucket), ucfirst($bucket) . ': ' . $result['error'], $item ?? $name);
                    continue;
                }
                // Billed whether or not the output can still be written.
                $this->usageStats->record($userId, $provider, $result, $ctx->usageType);
                if ($file === null) {
                    $failures['unsaved'][] = $name . ' — source no longer exists';
                    $run->appendLog(CoworkerRun::LOG_ERROR, 'Source no longer exists', $name);
                    continue;
                }

                $outputs[] = $this->writeOutput($ctx, $file, (string)($result['response'] ?? ''));
                $this->markDone($ctx, $file);
                $written++;
                $run->appendLog(CoworkerRun::LOG_INFO, $verb, $item);
            } catch (\Throwable $e) {
                $failures['unsaved'][] = $name . ' — ' . $e->getMessage();
                $run->appendLog(CoworkerRun::LOG_ERROR, 'Not saved: ' . $e->getMessage(), $item ?? $name);
                $this->logger->warning('AIquila batch: could not save a result', $ctx->logContext + [
                    'custom_id' => $customId,
                    'error' => $e->getMessage(),
                ]);
            } finally {
                $progress($written, $total);
            }
        }

        $skipped = self::skipsFromState($state);

        return [
            'itemsTotal' => $total,
            'itemsProcessed' => $written,
            'itemsFailed' => array_sum(array_map('count', $failures)),
            'itemsSkipped' => count($skipped),
            'summary' => $this->buildSummary($verb, $written, $total, 'anthropic (batch, 50% discount)', $failures, $skipped),
            'outputs' => $outputs,
        ];
    }

    /**
     * Fallback for providers with no batch API: one synchronous request per
     * file, at full price. Slower, but the operation stays usable.
     *
     * @param list<File> $files
     * @param callable(int, int): void $progress
     * @param list<BatchSkip> $skipped
     * @return BatchRunResult
     */
    private function runSynchronously(
        BatchContext $ctx,
        CoworkerRun $run,
        LLMProviderInterface $provider,
        array $files,
        callable $progress,
        array $skipped,
    ): array {
        $userId = $ctx->userId;
        $verb = $ctx->operation->summaryVerb();
        $total = count($files);
        $written = 0;
        $outputs = [];
        $failures = self::emptyFailures();
        $skippedBefore = count($skipped);

        $progress(0, $total);

        foreach ($files as $file) {
            try {
                $content = $this->readContent($ctx, $file, $reason);
                if ($content === null) {
                    $skipped[] = $this->skip($run, $file, $reason);
                    continue;
                }

                $result = $provider->ask(
                    $ctx->operation->buildPrompt($content, $ctx->options),
                    '',
                    $userId,
                    ($ctx->requestOptions)($provider),
                );
                $this->usageStats->record($userId, $provider, $result, $ctx->usageType);
                if (isset($result['error'])) {
                    // A refusal is reported as such, as on the batch path.
                    $bucket = ($result['error_type'] ?? null) === 'refused' ? 'refused' : 'errored';
                    $failures[$bucket][] = $file->getName() . ' — ' . $result['error'];
                    $run->appendLog(self::logLevelFor($bucket), ucfirst($bucket) . ': ' . $result['error'], UserPath::of($file));
                    continue;
                }

                $outputs[] = $this->writeOutput($ctx, $file, (string)($result['response'] ?? ''));
                $this->markDone($ctx, $file);
                $written++;
                $run->appendLog(CoworkerRun::LOG_INFO, $verb, UserPath::of($file));
            } catch (\Throwable $e) {
                $failures['unsaved'][] = $file->getName() . ' — ' . $e->getMessage();
                $run->appendLog(CoworkerRun::LOG_ERROR, 'Not saved: ' . $e->getMessage(), UserPath::of($file));
                $this->logger->warning('AIquila batch: item failed', $ctx->logContext + [
                    'file' => $file->getPath(),
                    'error' => $e->getMessage(),
                ]);
            } finally {
                $progress($written, $total);
            }
        }

        $label = method_exists($provider, 'getId') ? $provider->getId() : 'provider';
        // As on the batch path, the total is what was sent to the model: a
        // file skipped on reading counts in itemsSkipped, not here as well.
        $total -= count($skipped) - $skippedBefore;

        return [
            'itemsTotal' => $total,
            'itemsProcessed' => $written,
            'itemsFailed' => array_sum(array_map('count', $failures)),
            'itemsSkipped' => count($skipped),
            'summary' => $this->buildSummary($verb, $written, $total, $label . ($ctx->allowBatch ? ' (no batch API — full price)' : ' (immediate — full price)'), $failures, $skipped),
            'outputs' => $outputs,
        ];
    }

    /** @return array<string, list<string>> */
    private static function emptyFailures(): array {
        return ['refused' => [], 'errored' => [], 'expired' => [], 'canceled' => [], 'unsaved' => [], 'missing' => []];
    }

    /**
     * Log level for a failure bucket: a refusal or an expired/cancelled batch
     * item is an outcome to note, everything else went wrong.
     *
     * @return 'warn'|'error'
     */
    private static function logLevelFor(string $bucket): string {
        return in_array($bucket, ['refused', 'expired', 'canceled'], true) ? CoworkerRun::LOG_WARN : CoworkerRun::LOG_ERROR;
    }

    /**
     * @param array<string, list<string>> $failures
     * @param list<BatchSkip> $skipped
     */
    private function buildSummary(string $verb, int $written, int $total, string $via, array $failures, array $skipped): string {
        $summary = sprintf('%s %d/%d document(s) via %s.', $verb, $written, $total, $via);

        $counts = [];
        foreach ($failures as $kind => $entries) {
            if ($entries !== []) {
                $counts[] = count($entries) . ' ' . $kind;
            }
        }
        if ($skipped !== []) {
            $counts[] = count($skipped) . ' skipped';
        }
        if ($counts !== []) {
            $summary .= "\n" . implode(', ', $counts) . '.';
        }

        $lines = [];
        foreach ($failures as $kind => $entries) {
            foreach ($entries as $entry) {
                $lines[] = ucfirst($kind) . ': ' . $entry;
            }
        }
        foreach ($skipped as $skip) {
            $lines[] = self::skipLine($skip);
        }
        if ($lines !== []) {
            $summary .= "\n" . implode("\n", array_slice($lines, 0, self::SUMMARY_LINES));
            if (count($lines) > self::SUMMARY_LINES) {
                $summary .= "\n" . sprintf('… and %d more; the run log has them.', count($lines) - self::SUMMARY_LINES);
            }
        }

        return $summary;
    }

    /** Most per-file lines a summary lists; the run log keeps more. */
    private const SUMMARY_LINES = 50;

    /**
     * The skip lines a summary ends with, for a caller that has no other
     * summary to append them to. Empty when nothing was skipped.
     *
     * @param list<BatchSkip> $skipped
     */
    public static function describeSkips(array $skipped): string {
        if ($skipped === []) {
            return '';
        }
        $lines = array_map(self::skipLine(...), array_slice($skipped, 0, self::SUMMARY_LINES));
        $text = "\n" . count($skipped) . ' skipped.' . "\n" . implode("\n", $lines);
        if (count($skipped) > self::SUMMARY_LINES) {
            $text .= "\n" . sprintf('… and %d more; the run log has them.', count($skipped) - self::SUMMARY_LINES);
        }
        return $text;
    }

    /** @param BatchSkip $skip */
    private static function skipLine(array $skip): string {
        return 'Skipped: ' . basename($skip['item']) . ' — ' . $skip['reason'];
    }

    /**
     * Log one file the run passes over and return it for the summary.
     *
     * @return BatchSkip
     */
    private function skip(CoworkerRun $run, File $file, ?string $reason): array {
        $skip = ['item' => UserPath::of($file), 'reason' => $reason ?? 'Could not be read'];
        $run->appendLog(CoworkerRun::LOG_WARN, 'Skipped: ' . $skip['reason'], $skip['item']);
        return $skip;
    }

    /**
     * The skips a pending run carried over from submission. Runs submitted
     * before skips had reasons stored bare file names.
     *
     * @param array<string, mixed> $state
     * @return list<BatchSkip>
     */
    private static function skipsFromState(array $state): array {
        $skips = [];
        foreach (is_array($state['skipped'] ?? null) ? $state['skipped'] : [] as $entry) {
            if (is_string($entry)) {
                $skips[] = ['item' => $entry, 'reason' => 'Unreadable or too large'];
            } elseif (is_array($entry) && is_string($entry['item'] ?? null) && is_string($entry['reason'] ?? null)) {
                $skips[] = ['item' => $entry['item'], 'reason' => $entry['reason']];
            }
        }
        return $skips;
    }

    // ── Files ─────────────────────────────────────────────────────────────

    /**
     * Read a document's text, or null when it is too large, unreadable, not
     * UTF-8 or empty; $reason then says which, for the run log.
     *
     * Oversized files are skipped rather than truncated: half a contract
     * summarised as if it were the whole thing is worse than no summary.
     *
     * @param-out string|null $reason
     */
    private function readContent(BatchContext $ctx, File $file, ?string &$reason = null): ?string {
        $reason = null;
        $size = (int)$file->getSize();
        if ($size > $ctx->maxBytes()) {
            $reason = sprintf('Too large (%s; the limit is %s)', self::humanBytes($size), self::humanBytes($ctx->maxBytes()));
            return null;
        }
        try {
            $content = $file->getContent();
        } catch (\Throwable $e) {
            $reason = 'Could not be read: ' . $e->getMessage();
            return null;
        }
        // A batch is submitted as one request for the whole set, outside any
        // per-file guard, so one binary file reaching the payload would take
        // the entire run down rather than just itself.
        if (!mb_check_encoding($content, 'UTF-8')) {
            $reason = 'Not UTF-8 text';
            return null;
        }
        if (trim($content) === '') {
            $reason = 'Empty';
            return null;
        }
        return $content;
    }

    private static function humanBytes(int $bytes): string {
        if ($bytes >= 1048576) {
            return rtrim(rtrim(number_format($bytes / 1048576, 1, '.', ''), '0'), '.') . ' MB';
        }
        if ($bytes >= 1024) {
            return rtrim(rtrim(number_format($bytes / 1024, 1, '.', ''), '0'), '.') . ' KB';
        }
        return $bytes . ' B';
    }

    /**
     * Write the model's answer beside the source (or into the configured
     * output folder), creating the folder if it does not exist.
     *
     * @return string the output's path in the user's Files
     */
    private function writeOutput(BatchContext $ctx, File $source, string $response): string {
        $folder = $this->outputFolder($ctx, $source);
        $name = self::outputName($ctx->operation, $source, $ctx->options);

        $output = null;
        if ($folder->nodeExists($name)) {
            $existing = $folder->get($name);
            if ($existing instanceof File) {
                $existing->putContent($response);
                $output = $existing;
            }
        }
        $output ??= $folder->newFile($name, $response);
        $this->markOutput($output);
        return UserPath::of($output);
    }

    /**
     * Tag a written output so later walks leave it alone. Best effort, like
     * the done-tag: the output exists either way.
     */
    private function markOutput(File $output): void {
        try {
            $tagId = $this->outputTagId(true);
            if ($tagId !== '') {
                $this->tagObjectMapper->assignTags((string)$output->getId(), 'files', [$tagId]);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('AIquila batch: could not tag an output file', [
                'file' => $output->getPath(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The ids among a folder listing's files that are outputs, in one query
     * per folder rather than one per file.
     *
     * @param array<array-key, Node> $listing
     * @return array<int, true>
     */
    private function outputIds(array $listing): array {
        $tagId = $this->outputTagId(false);
        if ($tagId === '') {
            return [];
        }
        $ids = [];
        foreach ($listing as $node) {
            if ($node instanceof File) {
                $ids[] = (string)$node->getId();
            }
        }
        if ($ids === []) {
            return [];
        }
        try {
            $tags = $this->tagObjectMapper->getTagIdsForObjects($ids, 'files');
        } catch (\Throwable) {
            return [];
        }
        $outputs = [];
        foreach ($tags as $objectId => $tagIds) {
            if (in_array($tagId, array_map('strval', $tagIds), true)) {
                $outputs[(int)$objectId] = true;
            }
        }
        return $outputs;
    }

    /**
     * The output tag's id. Hidden and not assignable: it is bookkeeping, not
     * something to show in Files or let users set.
     *
     * @param bool $create create the tag when it does not exist yet
     * @return string '' when it does not exist (and $create is false)
     */
    private function outputTagId(bool $create): string {
        if ($this->outputTagId !== null && ($this->outputTagId !== '' || !$create)) {
            return $this->outputTagId;
        }
        try {
            $this->outputTagId = $this->tagManager->getTag(self::OUTPUT_TAG, false, false)->getId();
        } catch (TagNotFoundException) {
            $this->outputTagId = $create
                ? $this->tagManager->createTag(self::OUTPUT_TAG, false, false)->getId()
                : '';
        }
        return $this->outputTagId;
    }

    /** The folder a source's output goes to. */
    public function outputFolder(BatchContext $ctx, File $source): Folder {
        if ($ctx->outputFolder === '') {
            $parent = $source->getParent();
            if (!$parent instanceof Folder) {
                throw new \RuntimeException('Source file has no parent folder');
            }
            return $parent;
        }

        $userFolder = $this->rootFolder->getUserFolder($ctx->userId);
        try {
            $node = $userFolder->get($ctx->outputFolder);
        } catch (NotFoundException) {
            return $userFolder->newFolder($ctx->outputFolder);
        }
        if (!$node instanceof Folder) {
            throw new \RuntimeException("Output path {$ctx->outputFolder} is not a folder");
        }
        return $node;
    }

    /**
     * Tag the source so a user can filter what has already been processed.
     * A tagging failure must not lose an output file that was written.
     */
    private function markDone(BatchContext $ctx, File $file): void {
        try {
            $this->tagObjectMapper->assignTags((string)$file->getId(), 'files', [$this->resolveTag($ctx->operation->doneTag())->getId()]);
        } catch (\Throwable $e) {
            $this->logger->warning('AIquila batch: could not tag a source file', [
                'file' => $file->getPath(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function resolveTag(string $name): ISystemTag {
        try {
            return $this->tagManager->getTag($name, true, true);
        } catch (TagNotFoundException) {
            return $this->tagManager->createTag($name, true, true);
        }
    }

    /**
     * A file whose output we are about to write. Null when it was deleted
     * while the batch was in flight.
     */
    private function fileById(string $userId, int $fileId): ?File {
        $nodes = $this->rootFolder->getUserFolder($userId)->getById($fileId);
        foreach ($nodes as $node) {
            if ($node instanceof File) {
                return $node;
            }
        }
        return null;
    }
}
