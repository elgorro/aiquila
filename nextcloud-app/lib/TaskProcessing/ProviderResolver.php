<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\TaskProcessing;

use OCA\AIquila\Service\InstructionsService;
use OCA\AIquila\Service\Provider\LLMProviderFactory;
use OCA\AIquila\Service\Provider\LLMProviderInterface;
use OCA\AIquila\Service\Provider\NoPermittedProviderException;
use OCA\AIquila\Service\UsageStatService;
use OCP\TaskProcessing\EShapeType;
use OCP\TaskProcessing\ShapeDescriptor;

/**
 * Picks the LLM provider that should serve a TaskProcessing run.
 *
 * A task carries no conversation, so there is no per-conversation pin to read:
 * resolution is the user's own provider choice, then the instance default, as
 * LLMProviderFactory defines it. Going through the factory is also what applies
 * the admin's per-user and per-group access rules — a provider reached directly
 * would bypass them.
 *
 * The two things that can go wrong here — no permitted provider at all, and a
 * provider missing the modality the task needs — fail as a user-facing
 * TaskFailure, with a message that tells the user what to switch to.
 */
class ProviderResolver {

    /**
     * @param InstructionsService|null $instructions null only where a test
     *        does not care about instance instructions; the container always
     *        injects it
     */
    public function __construct(
        private LLMProviderFactory $providerFactory,
        private UsageStatService $usage,
        private ?InstructionsService $instructions = null,
    ) {
    }

    /** Longest single memory, and all of them together, in characters. */
    public const MEMORY_MAX_CHARS = 4000;
    public const MEMORIES_MAX_CHARS = 16000;

    /**
     * The optional `memories` input of the chat task types.
     *
     * The Assistant hands a chat provider what the user asked it to remember
     * from earlier chats (summaries of the sessions marked "remember"), but
     * only to a provider that declares this input: it checks the provider's
     * optional input shape before adding it (assistant ChatService). Without
     * it, memories never reach a chat AIquila serves.
     */
    public static function memoriesShape(): ShapeDescriptor {
        return new ShapeDescriptor(
            'Memories',
            'What the user asked the assistant to remember from earlier conversations',
            EShapeType::ListOfTexts
        );
    }

    /**
     * The memories as a section for the system prompt, or null when there are
     * none. Each is capped, and so is their total, so a long-remembered chat
     * cannot crowd out the conversation itself.
     */
    public static function memorySection(mixed $memories): ?string {
        if (!is_array($memories)) {
            return null;
        }
        $kept = [];
        $total = 0;
        foreach ($memories as $memory) {
            if (!is_string($memory) || trim($memory) === '') {
                continue;
            }
            $memory = trim($memory);
            if (mb_strlen($memory) > self::MEMORY_MAX_CHARS) {
                $memory = mb_substr($memory, 0, self::MEMORY_MAX_CHARS) . '…';
            }
            if ($total + mb_strlen($memory) > self::MEMORIES_MAX_CHARS) {
                break;
            }
            $total += mb_strlen($memory);
            $kept[] = '- ' . str_replace("\n", "\n  ", $memory);
        }
        if ($kept === []) {
            return null;
        }
        return "The user asked you to remember these things from earlier conversations. "
            . "Use them where they help; don't bring them up otherwise.\n\n"
            . implode("\n", $kept);
    }

    /**
     * The system prompt for a chat-shaped task: the task's own `system_prompt`
     * (from the Assistant or the Context Agent) and the user's `memories`,
     * with the admin's instance instructions in front when they are enabled
     * for TaskProcessing.
     *
     * @param array<string, mixed> $input
     */
    public function systemPrompt(?string $userId, array $input): ?string {
        $system = $input['system_prompt'] ?? '';
        $system = is_string($system) && $system !== '' ? $system : null;
        $memories = self::memorySection($input['memories'] ?? null);
        if ($memories !== null) {
            $system = $system === null ? $memories : $system . "\n\n" . $memories;
        }
        if ($this->instructions === null) {
            return $system;
        }
        return $this->instructions->withInstance(InstructionsService::SCOPE_TASKS, $userId, $system);
    }

    /**
     * Records the tokens a task's model call spent. Every provider here
     * resolves through this class, so this is where task usage is counted.
     *
     * @param array<array-key, mixed> $result The provider's result array
     */
    public function recordUsage(?string $userId, LLMProviderInterface $provider, array $result): void {
        $this->usage->record($userId, $provider, $result, UsageStatService::TYPE_TASK);
    }

    /**
     * An optional `provider` task input, if one was supplied.
     *
     * The id is not trusted: resolve() hands it to the factory, which honours it
     * only when the user is actually allowed to use that provider.
     *
     * @param array<string, mixed> $input
     */
    public function requestedId(array $input): ?string {
        $requested = $input['provider'] ?? null;
        return is_string($requested) && $requested !== '' ? $requested : null;
    }

    /**
     * @throws \RuntimeException when no provider is available to this user
     */
    public function resolve(?string $userId, ?string $requestedId = null): LLMProviderInterface {
        try {
            return $this->providerFactory->getProviderForUser($userId, $requestedId);
        } catch (NoPermittedProviderException $e) {
            throw TaskFailure::userFacing(NoPermittedProviderException::USER_MESSAGE, $e);
        }
    }

    /**
     * Same as resolve(), but for tasks that send images.
     *
     * Vision is not a static property of a provider — Hetzner derives it from the
     * selected model and the local provider from an admin flag — so this has to be
     * asked per run rather than at registration time. A provider without it is an
     * error rather than a silent hand-off to some other provider: the point of
     * picking a local model is that the image does not leave the server.
     *
     * @throws \RuntimeException when the resolved provider cannot process images
     */
    public function resolveVisionCapable(?string $userId, ?string $requestedId = null): LLMProviderInterface {
        $provider = $this->resolve($userId, $requestedId);
        return $this->requireCapability($provider, $userId, 'vision', 'process images', 'vision-capable');
    }

    /**
     * Same as resolve(), but for tasks that send audio to be transcribed.
     *
     * @throws \RuntimeException when the resolved provider cannot transcribe audio
     */
    public function resolveAudioCapable(?string $userId, ?string $requestedId = null): LLMProviderInterface {
        $provider = $this->resolve($userId, $requestedId);
        return $this->requireCapability($provider, $userId, 'audio_in', 'transcribe audio', 'transcription-capable');
    }

    /**
     * Same as resolve(), but for tasks that ask for generated speech.
     *
     * @throws \RuntimeException when the resolved provider cannot generate speech
     */
    public function resolveSpeechCapable(?string $userId, ?string $requestedId = null): LLMProviderInterface {
        $provider = $this->resolve($userId, $requestedId);
        return $this->requireCapability($provider, $userId, 'audio_out', 'generate speech', 'speech-capable');
    }

    /**
     * Same as resolve(), but for tasks that ask for generated images.
     *
     * @throws \RuntimeException when the resolved provider cannot generate images
     */
    public function resolveImageGenCapable(?string $userId, ?string $requestedId = null): LLMProviderInterface {
        $provider = $this->resolve($userId, $requestedId);
        return $this->requireCapability($provider, $userId, 'image_out', 'generate images', 'image-generating');
    }

    /**
     * Same as resolve(), but for the voice-chat task, which both consumes and
     * produces audio. Checked in the order the run would fail anyway, so the
     * message names the first thing that is missing rather than both.
     *
     * @throws \RuntimeException when the resolved provider cannot do both
     */
    public function resolveVoiceChatCapable(?string $userId, ?string $requestedId = null): LLMProviderInterface {
        $provider = $this->resolve($userId, $requestedId);
        $this->requireCapability($provider, $userId, 'audio_in', 'transcribe audio', 'transcription-capable');
        return $this->requireCapability($provider, $userId, 'audio_out', 'generate speech', 'speech-capable');
    }

    /**
     * Same as resolve(), but for a chat turn carrying attachments: images need
     * `vision`, PDFs need `documents`. Text attachments need nothing beyond
     * plain chat.
     *
     * @throws \RuntimeException when the resolved provider cannot read what is attached
     */
    public function resolveAttachmentCapable(?string $userId, ?string $requestedId, bool $images, bool $documents): LLMProviderInterface {
        $provider = $this->resolve($userId, $requestedId);
        if ($images) {
            $this->requireCapability($provider, $userId, 'vision', 'process images', 'vision-capable');
        }
        if ($documents) {
            $this->requireCapability($provider, $userId, 'documents', 'read PDF documents', 'document-capable');
        }
        return $provider;
    }

    /**
     * Hand back $provider when it declares $capability, or fail with a message
     * that names what to switch to.
     *
     * $cannot completes "<provider> cannot …" and $needs qualifies "… provider",
     * so the two halves of both sentences stay in one place.
     *
     * @throws \RuntimeException
     */
    private function requireCapability(
        LLMProviderInterface $provider,
        ?string $userId,
        string $capability,
        string $cannot,
        string $needs,
    ): LLMProviderInterface {
        if ($provider->getCapabilities()[$capability]) {
            return $provider;
        }

        $alternatives = $this->capableLabels($userId, $capability);
        throw TaskFailure::userFacing(
            $alternatives === []
                ? $provider->getLabel() . ' cannot ' . $cannot . ', and no ' . $needs . ' AI provider is available for your account. Ask your administrator.'
                : $provider->getLabel() . ' cannot ' . $cannot . '. Pick a ' . $needs . ' provider in your AIquila settings — available: ' . implode(', ', $alternatives) . '.'
        );
    }

    /**
     * Labels of the configured providers this user may use that declare $capability.
     *
     * @return list<string>
     */
    private function capableLabels(?string $userId, string $capability): array {
        $labels = [];
        foreach ($this->providerFactory->getProviderIdsForUser($userId) as $id) {
            $candidate = $this->providerFactory->getProviderById($id);
            if ($candidate->getCapabilities()[$capability] && $candidate->isConfigured($userId)) {
                $labels[] = $candidate->getLabel();
            }
        }
        return $labels;
    }
}
