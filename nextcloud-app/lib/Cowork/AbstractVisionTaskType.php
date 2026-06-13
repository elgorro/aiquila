<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Cowork;

use OCA\AIquila\Db\Coworker;
use OCA\AIquila\Db\CoworkerRun;
use OCA\AIquila\Service\ImageOptimizer;
use OCA\AIquila\Service\Provider\LLMProviderFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use Psr\Log\LoggerInterface;

/**
 * Base class for the "vision" task family — coworkers that walk a set of media
 * files under the configured input path and run a provider vision call on each.
 *
 * Subclasses define the media mime prefix, the per-item prompt, and what to do
 * with the model's response (e.g. assign system tags).
 */
abstract class AbstractVisionTaskType implements CoworkerTaskType {

    /** Hard cap on files processed in a single run to bound cost/runtime. */
    protected const MAX_ITEMS_PER_RUN = 200;

    public function __construct(
        protected readonly IRootFolder $rootFolder,
        protected readonly ImageOptimizer $imageOptimizer,
        protected readonly LLMProviderFactory $providerFactory,
        protected readonly LoggerInterface $logger,
    ) {
    }

    public function getFamily(): string {
        return 'vision';
    }

    /** Mime prefix of files this task operates on, e.g. "image/". */
    abstract protected function mimePrefix(): string;

    /** Per-item instruction sent to the vision model. */
    abstract protected function buildPrompt(array $options): string;

    /**
     * Handle a single model response for a file. Returns a short human-readable
     * fragment for the run summary (e.g. the labels applied).
     *
     * @param array<string, mixed> $options
     */
    abstract protected function handleResult(File $file, string $userId, string $response, array $options): string;

    public function validateOptions(array $options): void {
        // Subclasses may override; default accepts anything.
    }

    public function run(Coworker $coworker, CoworkerRun $run, callable $progress): array {
        $userId = $coworker->getUserId();
        $options = $this->decodeOptions($coworker);
        $providerId = $coworker->getModel() ?: $this->providerFactory->getActiveProviderId($userId);
        $provider = $this->providerFactory->getProviderById($providerId);
        $prompt = $this->buildPrompt($options);

        $files = $this->collectFiles($coworker, $options);
        $total = count($files);
        $processed = 0;
        $errors = 0;
        $fragments = [];

        $progress(0, $total);

        foreach ($files as $file) {
            try {
                $raw = $file->getContent();
                $mime = $file->getMimetype();
                if ($this->imageOptimizer->isSupported($mime)) {
                    $optimized = $this->imageOptimizer->optimize($raw, $mime);
                    $base64 = $optimized['data'];
                    $mime = $optimized['mimeType'];
                } else {
                    $base64 = base64_encode($raw);
                }

                $result = $provider->askWithImage($prompt, $base64, $mime, $userId, (string)$file->getId());
                if (isset($result['error'])) {
                    throw new \RuntimeException($result['error']);
                }

                $fragment = $this->handleResult($file, $userId, (string)($result['response'] ?? ''), $options);
                if ($fragment !== '') {
                    $fragments[] = $file->getName() . ': ' . $fragment;
                }
            } catch (\Throwable $e) {
                $errors++;
                $this->logger->warning('AIquila Cowork vision: item failed', [
                    'coworker' => $coworker->getId(),
                    'file' => $file->getPath(),
                    'error' => $e->getMessage(),
                ]);
            }

            $processed++;
            $progress($processed, $total);
        }

        $summary = sprintf(
            'Processed %d/%d %s file(s) via %s%s.',
            $processed - $errors,
            $total,
            rtrim($this->mimePrefix(), '/'),
            $providerId,
            $errors > 0 ? " ($errors error(s))" : ''
        );
        if ($fragments !== []) {
            $summary .= "\n" . implode("\n", array_slice($fragments, 0, 50));
        }

        return [
            'itemsTotal' => $total,
            'itemsProcessed' => $processed,
            'summary' => $summary,
        ];
    }

    /**
     * Resolve the coworker's input path to a bounded list of matching media files.
     *
     * @param array<string, mixed> $options
     * @return list<File>
     */
    protected function collectFiles(Coworker $coworker, array $options): array {
        $userFolder = $this->rootFolder->getUserFolder($coworker->getUserId());
        $path = $coworker->getInputPath() ?: '/';
        $node = $userFolder->get($path);

        $recursive = (bool)($options['recursive'] ?? true);
        $prefix = $this->mimePrefix();
        $matches = [];

        if ($node instanceof File) {
            if (str_starts_with($node->getMimetype(), $prefix)) {
                $matches[] = $node;
            }
            return $matches;
        }

        if (!($node instanceof Folder)) {
            return $matches;
        }

        $this->gather($node, $prefix, $recursive, $matches);
        return array_slice($matches, 0, self::MAX_ITEMS_PER_RUN);
    }

    /**
     * @param list<File> $matches
     */
    private function gather(Folder $folder, string $prefix, bool $recursive, array &$matches): void {
        foreach ($folder->getDirectoryListing() as $child) {
            if (count($matches) >= self::MAX_ITEMS_PER_RUN) {
                return;
            }
            if ($child instanceof File) {
                if (str_starts_with($child->getMimetype(), $prefix)) {
                    $matches[] = $child;
                }
            } elseif ($recursive && $child instanceof Folder) {
                $this->gather($child, $prefix, $recursive, $matches);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function decodeOptions(Coworker $coworker): array {
        $raw = $coworker->getOptions();
        if ($raw === null || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
