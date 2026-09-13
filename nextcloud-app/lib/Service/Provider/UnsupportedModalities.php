<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service\Provider;

/**
 * Honest "not supported" answers for the non-text modalities.
 *
 * Most upstream APIs behind this app are text- and vision-only: Anthropic,
 * DeepSeek and Hetzner publish no transcription, speech or image-generation
 * endpoint at all. Implementing those methods as stubs that pretend to work —
 * or that throw — would be worse than saying so, so this trait returns the same
 * {error: string} shape every other call uses, naming the provider.
 *
 * A provider that does have the endpoint simply declares the method itself; a
 * class method always wins over a trait method.
 *
 * Callers should not reach these at all: the TaskProcessing providers ask
 * ProviderResolver for a capable provider first, which fails with a message
 * naming the alternatives. This is the backstop for every other caller.
 */
trait UnsupportedModalities {

    /**
     * @return array{response: string, usage?: array}|array{error: string}
     */
    public function transcribeAudio(string $audioData, string $mimeType, string $filename = 'audio', ?string $userId = null, array $options = []): array {
        return ['error' => $this->getLabel() . ' cannot transcribe audio.'];
    }

    /**
     * @return array{audio: string, mimeType: string, usage?: array}|array{error: string}
     */
    public function synthesizeSpeech(string $text, ?string $userId = null, array $options = []): array {
        return ['error' => $this->getLabel() . ' cannot generate speech.'];
    }

    /**
     * @return array{images: list<string>, mimeType: string, usage?: array}|array{error: string}
     */
    public function generateImages(string $prompt, int $count = 1, ?string $userId = null, array $options = []): array {
        return ['error' => $this->getLabel() . ' cannot generate images.'];
    }

    /** Provided by LLMProviderInterface implementors. */
    abstract public function getLabel(): string;
}
