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
 * @psalm-type BatchRunResult = array{itemsTotal: int, itemsProcessed: int, itemsFailed?: int, summary: string, pending?: bool, state?: array<string, mixed>}
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

    /** @param list<File> $matches */
    private function gather(BatchContext $ctx, Folder $folder, ?int $excludeFolderId, array &$matches): void {
        foreach ($folder->getDirectoryListing() as $child) {
            if (count($matches) >= self::MAX_ITEMS_PER_RUN) {
                return;
            }
            if ($child instanceof File) {
                if ($this->isCandidate($ctx, $child)) {
                    $matches[] = $child;
                }
            } elseif ($ctx->recursive() && $child instanceof Folder) {
                if ($excludeFolderId !== null && $child->getId() === $excludeFolderId) {
                    continue;
                }
                $this->gather($ctx, $child, $excludeFolderId, $matches);
            }
        }
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
     * @return BatchRunResult
     */
    public function process(BatchContext $ctx, CoworkerRun $run, array $files, callable $progress): array {
        $provider = $this->providerFactory->getProviderForUser($ctx->userId, $ctx->providerId);

        if (!$provider instanceof ClaudeSDKService || !$provider->supportsBatch()) {
            return $this->runSynchronously($ctx, $run, $provider, $files, $progress);
        }

        // One batch for the whole set. custom_id is the file id, which is
        // what results come back keyed by — the API returns them in any order.
        $requests = [];
        $fileIds = [];
        $skipped = [];
        $total = count($files);
        foreach ($files as $file) {
            $content = $this->readContent($ctx, $file);
            if ($content === null) {
                $skipped[] = $file->getName();
                $run->appendLog(CoworkerRun::LOG_WARN, 'Skipped: unreadable or too large', UserPath::of($file));
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
                'summary' => sprintf('Read none of the %d document(s): %s.', $total, implode(', ', array_slice($skipped, 0, 20))),
            ];
        }

        $batchId = $provider->submitBatch($requests, $ctx->userId);
        $run->appendLog(CoworkerRun::LOG_INFO, sprintf('Submitted %d document(s) to batch %s', count($requests), $batchId));

        return [
            'itemsTotal' => count($requests),
            'itemsProcessed' => 0,
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

                $this->writeOutput($ctx, $file, (string)($result['response'] ?? ''));
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

        $skipped = is_array($state['skipped'] ?? null) ? $state['skipped'] : [];

        return [
            'itemsTotal' => $total,
            'itemsProcessed' => $written,
            'itemsFailed' => array_sum(array_map('count', $failures)),
            'summary' => $this->buildSummary($verb, $written, $total, 'anthropic (batch, 50% discount)', $failures, $skipped),
        ];
    }

    /**
     * Fallback for providers with no batch API: one synchronous request per
     * file, at full price. Slower, but the operation stays usable.
     *
     * @param list<File> $files
     * @param callable(int, int): void $progress
     * @return BatchRunResult
     */
    private function runSynchronously(
        BatchContext $ctx,
        CoworkerRun $run,
        LLMProviderInterface $provider,
        array $files,
        callable $progress,
    ): array {
        $userId = $ctx->userId;
        $verb = $ctx->operation->summaryVerb();
        $total = count($files);
        $written = 0;
        $failures = self::emptyFailures();
        $skipped = [];

        $progress(0, $total);

        foreach ($files as $file) {
            try {
                $content = $this->readContent($ctx, $file);
                if ($content === null) {
                    $skipped[] = $file->getName();
                    $run->appendLog(CoworkerRun::LOG_WARN, 'Skipped: unreadable or too large', UserPath::of($file));
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

                $this->writeOutput($ctx, $file, (string)($result['response'] ?? ''));
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

        return [
            'itemsTotal' => $total,
            'itemsProcessed' => $written,
            'itemsFailed' => array_sum(array_map('count', $failures)),
            'summary' => $this->buildSummary($verb, $written, $total, $label . ' (no batch API — full price)', $failures, $skipped),
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
     * @param list<string> $skipped
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
            $counts[] = count($skipped) . ' unreadable';
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
        foreach ($skipped as $name) {
            $lines[] = 'Unreadable: ' . $name;
        }
        if ($lines !== []) {
            $summary .= "\n" . implode("\n", array_slice($lines, 0, 50));
        }

        return $summary;
    }

    // ── Files ─────────────────────────────────────────────────────────────

    /**
     * Read a document's text, or null when it is too large or not text at all.
     *
     * Oversized files are skipped rather than truncated: half a contract
     * summarised as if it were the whole thing is worse than no summary.
     */
    private function readContent(BatchContext $ctx, File $file): ?string {
        if ($file->getSize() > $ctx->maxBytes()) {
            return null;
        }
        try {
            $content = $file->getContent();
        } catch (\Throwable) {
            return null;
        }
        // A batch is submitted as one request for the whole set, outside any
        // per-file guard, so one binary file reaching the payload would take
        // the entire run down rather than just itself.
        if (!mb_check_encoding($content, 'UTF-8')) {
            return null;
        }
        return trim($content) === '' ? null : $content;
    }

    /**
     * Write the model's answer beside the source (or into the configured
     * output folder), creating the folder if it does not exist.
     */
    private function writeOutput(BatchContext $ctx, File $source, string $response): void {
        $folder = $this->outputFolder($ctx, $source);
        $name = self::outputName($ctx->operation, $source, $ctx->options);

        if ($folder->nodeExists($name)) {
            $existing = $folder->get($name);
            if ($existing instanceof File) {
                $existing->putContent($response);
                return;
            }
        }
        $folder->newFile($name, $response);
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
