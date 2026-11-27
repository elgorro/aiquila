<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Cowork;

use OCA\AIquila\Batch\BatchContext;
use OCA\AIquila\Batch\BatchDocumentRunner;
use OCA\AIquila\Batch\BatchOperation;
use OCA\AIquila\Db\Coworker;
use OCA\AIquila\Db\CoworkerRun;
use OCA\AIquila\Service\InstructionsService;
use OCA\AIquila\Service\Provider\LLMProviderFactory;
use OCA\AIquila\Service\Provider\LLMProviderInterface;
use OCA\AIquila\Service\UsageStatService;
use OCP\Files\Conversion\IConversionManager;
use OCP\FilesMetadata\IFilesMetadataManager;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use Psr\Log\LoggerInterface;

/**
 * Base class for the "docs" task family — coworkers that walk a folder of text
 * documents and ask a model one question about each, writing the answer to a
 * file beside the source.
 *
 * A thin adapter: the pipeline itself (walk, batch or synchronous submission,
 * resumption, output files) is {@see BatchDocumentRunner}, shared with manual
 * runs started from Files. This class turns a coworker row into a
 * {@see BatchContext} and names the {@see BatchOperation}.
 */
abstract class AbstractBatchTextTaskType implements ResumableCoworkerTaskType {

    private readonly BatchDocumentRunner $runner;

    public function __construct(
        protected readonly IRootFolder $rootFolder,
        LLMProviderFactory $providerFactory,
        ISystemTagManager $tagManager,
        ISystemTagObjectMapper $tagObjectMapper,
        LoggerInterface $logger,
        UsageStatService $usageStats,
        protected readonly ?InstructionsService $instructions = null,
        ?IConversionManager $conversions = null,
        ?IFilesMetadataManager $metadata = null,
    ) {
        $this->runner = new BatchDocumentRunner($rootFolder, $providerFactory, $tagManager, $tagObjectMapper, $logger, $usageStats, $conversions, $metadata);
    }

    /** The question this task type asks about each document. */
    abstract protected function operation(): BatchOperation;

    public function getFamily(): string {
        return 'docs';
    }

    public function validateOptions(array $options): void {
        BatchDocumentRunner::validateOptions($options);
        $this->operation()->validateOptions($options);
    }

    public function run(Coworker $coworker, CoworkerRun $run, callable $progress): array {
        $ctx = $this->context($coworker, $this->decodeOptions($coworker));
        $root = $this->rootFolder->getUserFolder($coworker->getUserId())->get($coworker->getInputPath() ?: '/');

        $skipped = [];
        $files = $this->runner->collect($ctx, $root, $skipped);
        foreach ($skipped as $skip) {
            $run->appendLog(CoworkerRun::LOG_WARN, 'Skipped: ' . $skip['reason'], $skip['item']);
        }
        $progress(0, count($files));

        if ($files === []) {
            return [
                'itemsTotal' => 0,
                'itemsProcessed' => 0,
                'itemsSkipped' => count($skipped),
                'summary' => 'Nothing to do — no new documents under ' . ($coworker->getInputPath() ?: '/') . '.'
                    . BatchDocumentRunner::describeSkips($skipped),
            ];
        }

        return $this->runner->process($ctx, $run, $files, $progress, $skipped);
    }

    public function resume(Coworker $coworker, CoworkerRun $run, array $state, callable $progress): array {
        // The options the batch was submitted with, not the coworker's
        // current ones: outputs go where the run said they would.
        /** @var array<string, mixed> $options */
        $options = is_array($state['options'] ?? null) ? $state['options'] : [];
        return $this->runner->resume($this->context($coworker, $options), $run, $state, $progress);
    }

    /** @param array<string, mixed> $options */
    private function context(Coworker $coworker, array $options): BatchContext {
        return new BatchContext(
            userId: $coworker->getUserId(),
            operation: $this->operation(),
            options: $options,
            requestOptions: fn (LLMProviderInterface $provider): array => $this->requestOptions($coworker, $provider, $options),
            providerId: $coworker->getProvider(),
            outputFolder: (string)($options['outputFolder'] ?? $coworker->getOutputPath() ?? ''),
            logContext: ['coworker' => $coworker->getId()],
        );
    }

    /**
     * Request options for one call of this run, with the admin's instance
     * instructions as the system prompt when they are enabled for coworkers.
     *
     * @param array<string, mixed> $options the coworker's decoded options
     * @return array<string, mixed>
     */
    protected function requestOptions(Coworker $coworker, LLMProviderInterface $provider, array $options): array {
        $system = $this->instructions?->withInstance(InstructionsService::SCOPE_COWORKERS, $coworker->getUserId(), null);
        return CoworkerRequestOptions::build($coworker, $provider, $options, $system);
    }

    /**
     * The question asked about one document.
     *
     * @param array<string, mixed> $options
     */
    protected function buildPrompt(string $content, array $options): string {
        return $this->operation()->buildPrompt($content, $options);
    }

    /** @param array<string, mixed> $options */
    protected function outputName(File $source, array $options): string {
        return BatchDocumentRunner::outputName($this->operation(), $source, $options);
    }

    /** @return array<string, mixed> */
    protected function decodeOptions(Coworker $coworker): array {
        $raw = $coworker->getOptions();
        if ($raw === null || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
