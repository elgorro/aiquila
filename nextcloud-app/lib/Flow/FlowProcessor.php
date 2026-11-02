<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Flow;

use OCA\AIquila\Service\ImageOptimizer;
use OCA\AIquila\Service\Provider\LLMProviderFactory;
use OCA\AIquila\Service\Provider\LLMProviderInterface;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use Psr\Log\LoggerInterface;
use OCA\AIquila\Service\UsageStatService;

/**
 * Runs one AIquila Flow action against one file and stores the answer.
 *
 * The file is read and the answer written as its owner, on the provider that
 * user has selected, within the admin's access rules — the same as a chat. The
 * answer goes to `<name>.aiquila.md` in the same folder, replacing an earlier
 * one, so re-running a rule refreshes rather than piles up.
 */
class FlowProcessor {
    /** Text beyond this is cut off before it reaches the model. */
    private const MAX_TEXT_CHARS = 200000;

    private const TEXT_MIMES = ['application/json', 'application/xml', 'application/x-yaml', 'application/yaml'];

    public function __construct(
        private IRootFolder $rootFolder,
        private LLMProviderFactory $providers,
        private ImageOptimizer $imageOptimizer,
        private UsageStatService $usageStats,
        private LoggerInterface $logger,
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

        $provider = $this->providers->getProviderForUser($userId, null);
        $prompt = $this->prompt($config, $kind);
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

        return $this->write($file, (string)($result['response'] ?? ''), $config, $provider);
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

    private function write(File $file, string $answer, FlowConfig $config, LLMProviderInterface $provider): ?string {
        $parent = $file->getParent();
        if (!$parent instanceof Folder || !$parent->isCreatable()) {
            $this->logger->info('AIquila Flow: cannot write next to the file', ['fileId' => $file->getId()]);
            return null;
        }

        $name = $file->getName() . AIquilaOperation::RESULT_SUFFIX;
        $heading = $config->action === FlowConfig::ACTION_ASK ? '> ' . str_replace("\n", "\n> ", $config->prompt) . "\n\n" : '';
        $content = '# ' . $file->getName() . "\n\n" . $heading . trim($answer) . "\n\n---\n"
            . '*Written by AIquila (' . $provider->getLabel() . ') on ' . gmdate('Y-m-d H:i') . " UTC.*\n";

        if ($parent->nodeExists($name)) {
            $target = $parent->get($name);
            if (!$target instanceof File) {
                return null;
            }
            $target->putContent($content);
        } else {
            $target = $parent->newFile($name, $content);
        }
        return $target->getPath();
    }
}
