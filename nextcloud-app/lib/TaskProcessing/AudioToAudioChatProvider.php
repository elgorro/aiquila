<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\TaskProcessing;

use OCA\AIquila\Service\AudioLimits;
use OCP\Files\File;
use OCP\TaskProcessing\EShapeType;
use OCP\TaskProcessing\ISynchronousProvider;
use OCP\TaskProcessing\ShapeDescriptor;
use OCP\TaskProcessing\TaskTypes\AudioToAudioChat;
use Psr\Log\LoggerInterface;

/**
 * Voice-chat TaskProcessing Provider
 *
 * Registers AIquila as a core:audio2audio:chat provider — the voice mode of the
 * Assistant, where a spoken message is answered with spoken audio.
 *
 * No provider here answers audio with audio in one call, so the run is a chain:
 * transcribe → chat → speak. That is why one provider has to hold both audio
 * capabilities rather than the two halves being resolved separately: splitting
 * them would let a spoken message leave the instance for transcription and the
 * reply leave again for a second vendor, which is not what picking a provider
 * is supposed to mean.
 *
 * Input:  system_prompt, input (audio), history (flat list of turns)
 * Output: input_transcript, output (raw audio bytes), output_transcript
 */
class AudioToAudioChatProvider implements ISynchronousProvider {

    public function __construct(
        private ProviderResolver $providers,
        private LoggerInterface $logger,
    ) {
    }

    public function getId(): string {
        return 'aiquila:audio2audio:chat';
    }

    public function getName(): string {
        return 'AIquila Audio';
    }

    public function getTaskTypeId(): string {
        return AudioToAudioChat::ID;
    }

    public function getExpectedRuntime(): int {
        return 180;
    }

    public function getOptionalInputShape(): array {
        return [
            'provider' => new ShapeDescriptor(
                'Provider',
                'Optional LLM provider id override (e.g. mistral, local)',
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
        $file = $input['input'] ?? null;
        if (!$file instanceof File) {
            throw new \RuntimeException('No audio file provided');
        }

        AudioLimits::assertAcceptable((int)$file->getSize(), $file->getMimetype());

        $provider = $this->providers->resolveVoiceChatCapable($userId, $this->providers->requestedId($input));
        $reportProgress(0.1);

        $transcribed = $provider->transcribeAudio(
            $file->getContent(),
            $file->getMimetype(),
            AudioLimits::filenameFor($file->getName(), $file->getMimetype()),
            $userId,
        );
        if (isset($transcribed['error'])) {
            $this->logger->error('AIquila AudioToAudioChat: transcription failed', ['error' => $transcribed['error'], 'provider' => $provider->getId()]);
            throw new \RuntimeException($transcribed['error']);
        }
        $question = $transcribed['response'] ?? '';
        if (trim($question) === '') {
            throw new \RuntimeException('Nothing could be transcribed from this recording.');
        }
        $reportProgress(0.4);

        $system = $input['system_prompt'] ?? '';
        $messages = ChatHistory::toMessages($input['history'] ?? []);
        $messages[] = ['role' => 'user', 'content' => $question];

        $answered = $provider->chat(
            $messages,
            is_string($system) && $system !== '' ? $system : null,
            $userId,
        );
        if (isset($answered['error'])) {
            throw new \RuntimeException($answered['error']);
        }
        $answer = $answered['response'] ?? '';
        if (trim($answer) === '') {
            throw new \RuntimeException('The provider returned an empty reply.');
        }
        $reportProgress(0.7);

        $spoken = $provider->synthesizeSpeech($answer, $userId);
        if (isset($spoken['error'])) {
            $this->logger->error('AIquila AudioToAudioChat: speech synthesis failed', ['error' => $spoken['error'], 'provider' => $provider->getId()]);
            throw new \RuntimeException($spoken['error']);
        }

        $reportProgress(1.0);
        return [
            'input_transcript' => $question,
            'output' => $spoken['audio'] ?? '',
            'output_transcript' => $answer,
        ];
    }
}
