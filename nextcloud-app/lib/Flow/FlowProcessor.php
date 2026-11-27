<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Flow;

use OCA\AIquila\Batch\BatchDocumentRunner;
use OCA\AIquila\Service\ImageOptimizer;
use OCA\AIquila\Service\Provider\LLMProviderFactory;
use OCA\AIquila\Service\Provider\LLMProviderInterface;
use OCA\AIquila\Service\RuntimeContextService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use OCP\SystemTag\TagNotFoundException;
use Psr\Log\LoggerInterface;
use OCA\AIquila\Service\UsageStatService;

/**
 * Runs one AIquila Flow action against one file and stores the answer.
 *
 * The file is read and the answer written as its owner, on the provider that
 * user has selected, within the admin's access rules — the same as a chat. The
 * answer goes to `<name>.aiquila.md` in the same folder, replacing an earlier
 * one, so re-running a rule refreshes rather than piles up.
 *
 * Only an earlier answer is replaced. Answers carry the batch runner's hidden
 * output tag; a file of that name without it is the user's, and the rule is
 * skipped before anything is sent. Answers written before the tag existed are
 * recognised by their own heading and footer.
 */
class FlowProcessor {
    /** Text beyond this is cut off before it reaches the model. */
    private const MAX_TEXT_CHARS = 200000;

    private const TEXT_MIMES = ['application/json', 'application/xml', 'application/x-yaml', 'application/yaml'];

    public function __construct(
        private IRootFolder $rootFolder,
        private LLMProviderFactory $providers,
        private ImageOptimizer $imageOptimizer,
        private RuntimeContextService $runtimeContext,
        private ITimeFactory $time,
        private UsageStatService $usageStats,
        private LoggerInterface $logger,
        private ISystemTagManager $tagManager,
        private ISystemTagObjectMapper $tagObjectMapper,
    ) {
    }

    /**
     * @return string|null The result file's path, or null when nothing was written
     */
    public function process(int $fileId, string $userId, FlowConfig $config): ?string {
        $file = $this->rootFolder->getUserFolder($userId)->getFirstNodeById($fileId);
        if (!$file instanceof File) {
            return null; // deleted or moved out of reach since the rule matched
        }

        $mime = $file->getMimetype();
        $kind = match (true) {
            $this->imageOptimizer->isSupported($mime) => 'image',
            $mime === 'application/pdf' => 'pdf',
            str_starts_with($mime, 'text/') || in_array($mime, self::TEXT_MIMES, true) => 'text',
            default => null,
        };
        if ($kind === null) {
            $this->logger->info('AIquila Flow: file type not supported', ['fileId' => $fileId, 'mime' => $mime]);
            return null;
        }

        $parent = $file->getParent();
        if (!$parent instanceof Folder || !$parent->isCreatable()) {
            $this->logger->info('AIquila Flow: cannot write next to the file', ['fileId' => $fileId]);
            return null;
        }
        $name = $file->getName() . AIquilaOperation::RESULT_SUFFIX;
        if (!$this->isWritable($parent, $name, $file)) {
            // Checked before the model call, so the user's file costs no request.
            $this->logger->info('AIquila Flow: ' . $name . ' already exists and was not written by AIquila; skipped', ['fileId' => $fileId]);
            return null;
        }

        $provider = $this->providers->getProviderForUser($userId, null);
        // The date lets "recently" or "next week" in a file be read against
        // today. Only the model sees it; the result file quotes $config->prompt.
        $prompt = $this->prompt($config, $kind) . "\n\n" . $this->runtimeContext->note($userId, $this->time->getTime());
        $result = match ($kind) {
            'image' => $this->askImage($provider, $file, $prompt, $userId),
            'pdf' => $this->askPdf($provider, $file, $prompt, $userId),
            'text' => $provider->ask(
                $prompt,
                mb_substr($file->getContent(), 0, self::MAX_TEXT_CHARS),
                $userId,
                LLMProviderInterface::TASK_OPTIONS,
            ),
        };
        $this->usageStats->record($userId, $provider, $result, UsageStatService::TYPE_FLOW);

        if (isset($result['error'])) {
            $this->logger->warning('AIquila Flow: the model call failed', [
                'fileId' => $fileId,
                'provider' => $provider->getId(),
                'error' => $result['error'],
            ]);
            return null;
        }

        return $this->write($file, $parent, $name, (string)($result['response'] ?? ''), $config, $provider);
    }

    private function prompt(FlowConfig $config, string $kind): string {
        $action = $config->action;
        if ($action === FlowConfig::ACTION_AUTO) {
            $action = $kind === 'image' ? FlowConfig::ACTION_DESCRIBE : FlowConfig::ACTION_SUMMARIZE;
        }
        return match ($action) {
            FlowConfig::ACTION_SUMMARIZE => 'Summarize this file. Lead with a one-sentence overview, then the key points.',
            FlowConfig::ACTION_DESCRIBE => 'Describe this file in detail. For an image, say what it shows and transcribe any visible text.',
            default => $config->prompt,
        };
    }

    /** @return array{response?: string, error?: string, ...} */
    private function askImage(LLMProviderInterface $provider, File $file, string $prompt, string $userId): array {
        if (!$provider->getCapabilities()['vision']) {
            return ['error' => $provider->getLabel() . ' cannot process images'];
        }
        $image = $this->imageOptimizer->prepare($file->getContent(), $file->getMimetype());
        return $provider->askWithImage($prompt, $image['base64'], $image['mimeType'], $userId, null, LLMProviderInterface::TASK_OPTIONS);
    }

    /** @return array{response?: string, error?: string, ...} */
    private function askPdf(LLMProviderInterface $provider, File $file, string $prompt, string $userId): array {
        if (!$provider->getCapabilities()['documents']) {
            return ['error' => $provider->getLabel() . ' cannot read PDF documents'];
        }
        return $provider->askWithDocument($prompt, base64_encode($file->getContent()), 'application/pdf', $file->getName(), $userId);
    }

    private function write(File $file, Folder $parent, string $name, string $answer, FlowConfig $config, LLMProviderInterface $provider): ?string {
        $heading = $config->action === FlowConfig::ACTION_ASK ? '> ' . str_replace("\n", "\n> ", $config->prompt) . "\n\n" : '';
        $content = '# ' . $file->getName() . "\n\n" . $heading . trim($answer) . "\n\n---\n"
            . '*Written by AIquila (' . $provider->getLabel() . ') on ' . gmdate('Y-m-d H:i') . " UTC.*\n";

        // Asked again: the model call may have taken a while.
        if (!$this->isWritable($parent, $name, $file)) {
            $this->logger->info('AIquila Flow: ' . $name . ' already exists and was not written by AIquila; skipped', ['fileId' => $file->getId()]);
            return null;
        }
        if ($parent->nodeExists($name)) {
            $target = $parent->get($name);
            if (!$target instanceof File) {
                return null;
            }
            $target->putContent($content);
        } else {
            $target = $parent->newFile($name, $content);
        }
        $this->markOutput($target);
        return $target->getPath();
    }

    /**
     * Whether $name in $parent is free or holds an earlier answer: it carries
     * the output tag, or, written before the tag existed, starts with the
     * source's heading and ends with AIquila's footer.
     */
    private function isWritable(Folder $parent, string $name, File $source): bool {
        if (!$parent->nodeExists($name)) {
            return true;
        }
        $target = $parent->get($name);
        if (!$target instanceof File) {
            return false;
        }
        $tagId = $this->outputTagId(false);
        if ($tagId !== '' && $this->tagObjectMapper->haveTag([(string)$target->getId()], 'files', $tagId)) {
            return true;
        }
        return self::isLegacyAnswer($target->getContent(), $source->getName());
    }

    /** Whether content has the shape of an answer this class wrote for $sourceName. */
    public static function isLegacyAnswer(string $content, string $sourceName): bool {
        return str_starts_with($content, '# ' . $sourceName . "\n\n")
            && preg_match('/\n---\n\*Written by AIquila \(.*\) on \d{4}-\d{2}-\d{2} \d{2}:\d{2} UTC\.\*\n?$/', $content) === 1;
    }

    /** Tag an answer as an output, so a batch walk skips it and the next run may replace it. */
    private function markOutput(File $target): void {
        try {
            $tagId = $this->outputTagId(true);
            $this->tagObjectMapper->assignTags((string)$target->getId(), 'files', [$tagId]);
        } catch (\Throwable $e) {
            $this->logger->warning('AIquila Flow: could not tag the result file', ['file' => $target->getPath(), 'error' => $e->getMessage()]);
        }
    }

    /** The output tag's id; '' when it does not exist and $create is false. */
    private function outputTagId(bool $create): string {
        try {
            return $this->tagManager->getTag(BatchDocumentRunner::OUTPUT_TAG, false, false)->getId();
        } catch (TagNotFoundException) {
            return $create ? $this->tagManager->createTag(BatchDocumentRunner::OUTPUT_TAG, false, false)->getId() : '';
        }
    }
}
