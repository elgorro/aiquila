<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\TaskProcessing;

use OCP\TaskProcessing\EShapeType;
use OCP\TaskProcessing\ISynchronousProvider;
use OCP\TaskProcessing\ShapeDescriptor;
use OCP\TaskProcessing\TaskTypes\TextToSpeech;
use Psr\Log\LoggerInterface;

/**
 * Speech-generation TaskProcessing Provider
 *
 * Registers AIquila as a core:text2speech provider, which is what the
 * Assistant's "Generate speech" action calls.
 *
 * Input:  input (transcript to speak)
 * Output: speech (audio) — note the key is `speech`, not `output`, and the
 *         value is the raw audio bytes: the framework writes them to a file
 *         itself, so nothing here touches storage.
 */
class TextToSpeechProvider implements ISynchronousProvider {

    public function __construct(
        private ProviderResolver $providers,
        private LoggerInterface $logger,
    ) {
    }

    public function getId(): string {
        return 'aiquila:text2speech';
    }

    public function getName(): string {
        return 'AIquila Audio';
    }

    public function getTaskTypeId(): string {
        return TextToSpeech::ID;
    }

    public function getExpectedRuntime(): int {
        return 60;
    }

    public function getOptionalInputShape(): array {
        return [
            'provider' => new ShapeDescriptor(
                'Provider',
                'Optional LLM provider id override (e.g. mistral, local)',
                EShapeType::Text
            ),
            'voice' => new ShapeDescriptor(
                'Voice',
                'Optional voice id, overriding the one configured for the provider.',
                EShapeType::Text
            ),
        ];
    }

    public function getOptionalOutputShape(): array {
        return [];
    }

    public function getInputShapeEnumValues(): array {
        return [];
    }

    public function getInputShapeDefaults(): array {
        return [];
    }

    public function getOptionalInputShapeEnumValues(): array {
        return [];
    }

    public function getOptionalInputShapeDefaults(): array {
        return [];
    }

    public function getOutputShapeEnumValues(): array {
        return [];
    }

    public function getOptionalOutputShapeEnumValues(): array {
        return [];
    }

    public function process(?string $userId, array $input, callable $reportProgress): array {
        $text = $input['input'] ?? '';
        if (!is_string($text) || trim($text) === '') {
            throw new \RuntimeException('No input text provided');
        }

        $provider = $this->providers->resolveSpeechCapable($userId, $this->providers->requestedId($input));
        $reportProgress(0.1);

        $voice = $input['voice'] ?? '';
        $result = $provider->synthesizeSpeech(
            $text,
            $userId,
            is_string($voice) && $voice !== '' ? ['voice' => $voice] : [],
        );

        if (isset($result['error'])) {
            $this->logger->error('AIquila TextToSpeech: Error', ['error' => $result['error'], 'provider' => $provider->getId()]);
            throw new \RuntimeException($result['error']);
        }

        $reportProgress(1.0);
        return ['speech' => $result['audio'] ?? ''];
    }
}
