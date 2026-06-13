<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Cowork;

use OCA\AIquila\Service\ImageOptimizer;
use OCA\AIquila\Service\Provider\LLMProviderFactory;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use OCP\SystemTag\TagNotFoundException;
use Psr\Log\LoggerInterface;

/**
 * Vision task: classify images into a short set of labels and persist them as
 * Nextcloud system (collaborative) tags so they are searchable in the Files UI.
 */
class VisionClassifyImagesTaskType extends AbstractVisionTaskType {

    private const DEFAULT_MAX_TAGS = 8;

    public function __construct(
        IRootFolder $rootFolder,
        ImageOptimizer $imageOptimizer,
        LLMProviderFactory $providerFactory,
        LoggerInterface $logger,
        private readonly ISystemTagManager $tagManager,
        private readonly ISystemTagObjectMapper $tagObjectMapper,
    ) {
        parent::__construct($rootFolder, $imageOptimizer, $providerFactory, $logger);
    }

    public function getId(): string {
        return 'vision:classify';
    }

    public function getLabel(): string {
        return 'Classify images (system tags)';
    }

    public function validateOptions(array $options): void {
        if (isset($options['maxTags']) && (!is_int($options['maxTags']) || $options['maxTags'] < 1 || $options['maxTags'] > 30)) {
            throw new \InvalidArgumentException('maxTags must be an integer between 1 and 30');
        }
    }

    protected function mimePrefix(): string {
        return 'image/';
    }

    protected function buildPrompt(array $options): string {
        $maxTags = (int)($options['maxTags'] ?? self::DEFAULT_MAX_TAGS);
        return 'Classify this image for photo library tagging. '
            . "Respond with at most $maxTags short, lowercase labels describing the main objects, "
            . 'scene, and setting, as a single comma-separated list. '
            . 'No sentences, no explanations, no numbering — only the comma-separated labels.';
    }

    protected function handleResult(File $file, string $userId, string $response, array $options): string {
        $maxTags = (int)($options['maxTags'] ?? self::DEFAULT_MAX_TAGS);
        $labels = $this->parseLabels($response, $maxTags);
        if ($labels === []) {
            return '';
        }

        $tagIds = [];
        foreach ($labels as $label) {
            $tagIds[] = $this->resolveTag($label)->getId();
        }

        $this->tagObjectMapper->assignTags((string)$file->getId(), 'files', $tagIds);
        return implode(', ', $labels);
    }

    /**
     * @return list<string>
     */
    private function parseLabels(string $response, int $maxTags): array {
        $parts = preg_split('/[,\n]+/', strtolower($response)) ?: [];
        $labels = [];
        foreach ($parts as $part) {
            $label = trim(preg_replace('/[^\p{L}\p{N} \-]/u', '', $part) ?? '');
            $label = trim(preg_replace('/\s+/', ' ', $label) ?? '');
            if ($label === '' || mb_strlen($label) > 64) {
                continue;
            }
            if (!in_array($label, $labels, true)) {
                $labels[] = $label;
            }
            if (count($labels) >= $maxTags) {
                break;
            }
        }
        return $labels;
    }

    private function resolveTag(string $name): ISystemTag {
        try {
            return $this->tagManager->getTag($name, true, true);
        } catch (TagNotFoundException) {
            return $this->tagManager->createTag($name, true, true);
        }
    }
}
