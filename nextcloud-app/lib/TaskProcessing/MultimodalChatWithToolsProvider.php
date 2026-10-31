<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\TaskProcessing;

use OCA\AIquila\Service\ImageOptimizer;
use OCA\AIquila\Service\Provider\LLMProviderInterface;
use OCP\Files\File;

/**
 * Chat with tools and attachments (core:text2text:multimodal-chatwithtools).
 *
 * The chatwithtools turn, plus `input_attachments` sent alongside the user's
 * message. Images go as image blocks and need a vision-capable provider; PDFs go
 * as document blocks and need one that reads documents; text files are inlined
 * as text under their name.
 * Anything else is refused rather than silently dropped, so the model never
 * answers about a file it did not see.
 *
 * `output_attachments` is always empty: every provider's chat turn returns
 * text and tool calls, never files. Nextcloud still requires the key, as it is
 * part of the task type's output shape.
 *
 * Nextcloud 35+. The task-type id is spelled out because the OCP class is absent
 * on 33/34; where the task type is not registered the provider is never offered.
 */
class MultimodalChatWithToolsProvider extends ChatWithToolsProvider {
    /** Text files larger than this are refused rather than truncated. */
    private const MAX_TEXT_BYTES = 1048576;

    /** Mime types read as text in addition to text/*. */
    private const TEXT_MIMES = [
        'application/json',
        'application/xml',
        'application/x-yaml',
        'application/yaml',
        'application/javascript',
        'application/x-httpd-php',
    ];

    public function __construct(
        ProviderResolver $providers,
        private ImageOptimizer $imageOptimizer,
    ) {
        parent::__construct($providers);
    }

    public function getId(): string {
        return 'aiquila:text2text:multimodal-chatwithtools';
    }

    public function getTaskTypeId(): string {
        return 'core:text2text:multimodal-chatwithtools';
    }

    public function getExpectedRuntime(): int {
        return 45;
    }

    public function process(?string $userId, array $input, callable $reportProgress): array {
        $files = $input['input_attachments'] ?? [];
        $files = is_array($files) ? array_values($files) : [];

        $blocks = [];
        $images = 0;
        $documents = false;
        foreach ($files as $i => $file) {
            if (!$file instanceof File) {
                throw TaskFailure::userFacing('Attachment ' . ($i + 1) . ' is not a file');
            }
            $mime = $file->getMimetype();
            $name = $file->getName();

            if ($this->imageOptimizer->isSupported($mime)) {
                $image = $this->imageOptimizer->prepare($file->getContent(), $mime);
                $blocks[] = [
                    'type' => 'image',
                    'source' => ['type' => 'base64', 'media_type' => $image['mimeType'], 'data' => $image['base64']],
                ];
                $images++;
            } elseif ($mime === 'application/pdf') {
                $blocks[] = [
                    'type' => 'document',
                    'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => base64_encode($file->getContent())],
                    'title' => $name,
                ];
                $documents = true;
            } elseif (str_starts_with($mime, 'text/') || in_array($mime, self::TEXT_MIMES, true)) {
                if ($file->getSize() > self::MAX_TEXT_BYTES) {
                    throw TaskFailure::userFacing('Attachment "' . $name . '" is too large to read as text');
                }
                // A text block rather than a text document: the OpenAI-style
                // converters drop a document's title, and the name matters.
                $blocks[] = ['type' => 'text', 'text' => 'Attached file "' . $name . "\":\n\n" . $file->getContent()];
            } else {
                throw TaskFailure::userFacing('Attachment "' . $name . '" has a type AIquila cannot send to a model (' . $mime . ')');
            }
        }

        if ($images > ImageOptimizer::MAX_IMAGES) {
            throw TaskFailure::userFacing('Too many images. Maximum is ' . ImageOptimizer::MAX_IMAGES);
        }

        $result = $this->runTurn(
            $userId,
            $input,
            $reportProgress,
            fn (): LLMProviderInterface => $this->providers->resolveAttachmentCapable(
                $userId,
                $this->providers->requestedId($input),
                $images > 0,
                $documents,
            ),
            $blocks,
        );

        return $result + ['output_attachments' => []];
    }
}
