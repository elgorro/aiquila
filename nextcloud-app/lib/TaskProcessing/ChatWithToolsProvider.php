<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\TaskProcessing;

use OCA\AIquila\Service\JsonShape;
use OCA\AIquila\Service\Provider\LLMProviderInterface;
use OCP\TaskProcessing\EShapeType;
use OCP\TaskProcessing\ISynchronousProvider;
use OCP\TaskProcessing\ShapeDescriptor;
use OCP\TaskProcessing\TaskTypes\TextToTextChatWithTools;

/**
 * Chat-with-tools TaskProcessing Provider (core:text2text:chatwithtools)
 *
 * The inverse of the app's own agentic loop. In chat, AIquila owns the loop and
 * executes tool calls itself; here Nextcloud owns it — the caller passes the
 * tools it is willing to run, we return the calls the model asked for, the
 * caller runs them and feeds the results back as `tool_message` on the next
 * task. So this provider must stop after exactly one model turn, which is what
 * LLMProviderInterface::chatToolsTurn() exists for.
 *
 * The task type speaks OpenAI function-calling JSON on both `tools` and
 * `tool_calls`, while the app's canonical tool shape is Anthropic's
 * ({name, description, input_schema}). The translation lives here rather than in
 * the providers, so the providers keep one internal format.
 */
class ChatWithToolsProvider implements ISynchronousProvider {

    public function __construct(
        protected ProviderResolver $providers,
    ) {
    }

    public function getId(): string {
        return 'aiquila:text2text:chatwithtools';
    }

    public function getName(): string {
        return 'AIquila';
    }

    public function getTaskTypeId(): string {
        return TextToTextChatWithTools::ID;
    }

    public function getExpectedRuntime(): int {
        return 30;
    }

    public function getOptionalInputShape(): array {
        return [
            'provider' => new ShapeDescriptor(
                'Provider',
                'Optional LLM provider id override (e.g. anthropic, mistral)',
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
        return $this->runTurn(
            $userId,
            $input,
            $reportProgress,
            fn (): LLMProviderInterface => $this->providers->resolve($userId, $this->providers->requestedId($input)),
        );
    }

    /**
     * One model turn: build the conversation, ask the resolved provider, and
     * translate the result back to the task type's output shape.
     *
     * $resolve is called only once the input is known to be usable, so a bad
     * request fails on its own merits rather than on provider selection.
     *
     * @param callable(): LLMProviderInterface $resolve
     * @param list<array<string, mixed>> $attachments Content blocks sent with the user's message
     * @return array{output: string, tool_calls: string}
     */
    protected function runTurn(?string $userId, array $input, callable $reportProgress, callable $resolve, array $attachments = []): array {
        $message = $input['input'] ?? '';
        $message = is_string($message) ? $message : '';

        $toolMessage = $input['tool_message'] ?? '';
        $toolMessage = is_string($toolMessage) ? $toolMessage : '';

        if ($message === '' && $toolMessage === '' && $attachments === []) {
            throw TaskFailure::userFacing('No chat message provided');
        }

        $system = $this->providers->systemPrompt($userId, $input);

        $messages = ChatHistory::toMessages($input['history'] ?? []);
        if ($attachments !== []) {
            $content = $attachments;
            if ($message !== '') {
                $content[] = ['type' => 'text', 'text' => $message];
            }
            $messages[] = ['role' => 'user', 'content' => $content];
        } elseif ($message !== '') {
            $messages[] = ['role' => 'user', 'content' => $message];
        }
        // The task type carries tool results as free text and keeps no record of
        // the calls that produced them, so they can only be replayed as another
        // user turn rather than as matching tool_result blocks.
        if ($toolMessage !== '') {
            $messages[] = ['role' => 'user', 'content' => "Tool results:\n" . $toolMessage];
        }

        if ($messages === []) {
            throw TaskFailure::userFacing('No chat message provided');
        }

        $reportProgress(0.1);

        $tools = $this->toInternalTools($input['tools'] ?? '');

        $provider = $resolve();
        $result = $provider->chatToolsTurn(
            $messages,
            $tools,
            $system,
            $userId,
            LLMProviderInterface::TASK_OPTIONS,
        );
        $this->providers->recordUsage($userId, $provider, $result);

        if (isset($result['error'])) {
            throw TaskFailure::fromProviderError($result['error']);
        }

        return [
            'output' => $result['response'] ?? '',
            'tool_calls' => $this->toOpenAiToolCalls($result['tool_calls'] ?? [], $tools),
        ];
    }

    /**
     * OpenAI function-calling definitions (as a JSON string) to the app's
     * canonical Anthropic tool shape.
     *
     * Entries that are already in the canonical shape are passed through, so a
     * caller that hands us Anthropic-style tools is not silently dropped.
     *
     * The JSON is decoded into PHP arrays, which loses empty objects, so each
     * schema is normalized back before a provider sends it on (see JsonShape).
     *
     * @return list<array<string, mixed>>
     */
    private function toInternalTools(mixed $tools): array {
        if (!is_string($tools) || trim($tools) === '') {
            return [];
        }

        $decoded = json_decode($tools, true);
        if (!is_array($decoded)) {
            throw TaskFailure::userFacing('The "tools" input is not valid JSON');
        }

        $out = [];
        foreach ($decoded as $tool) {
            if (!is_array($tool)) {
                continue;
            }

            if (isset($tool['name'])) {
                $out[] = [
                    'name' => (string)$tool['name'],
                    'description' => (string)($tool['description'] ?? ''),
                    'input_schema' => JsonShape::toolInputSchema($tool['input_schema'] ?? null),
                ];
                continue;
            }

            $fn = $tool['function'] ?? null;
            if (!is_array($fn) || !isset($fn['name'])) {
                continue;
            }
            $out[] = [
                'name' => (string)$fn['name'],
                'description' => (string)($fn['description'] ?? ''),
                'input_schema' => JsonShape::toolInputSchema($fn['parameters'] ?? null),
            ];
        }

        return $out;
    }

    /**
     * The provider's normalized calls back to the OpenAI JSON the task type's
     * `tool_calls` output shape is documented to carry.
     *
     * `arguments` is a JSON *string* there, not an object — that is the OpenAI
     * wire format the consumer parses. The string holds a JSON object, so an
     * argument-less call is `"{}"`, and nested objects the tool's schema
     * requires are restored (see JsonShape).
     *
     * @param list<array{id: string, name: string, arguments: array}> $calls
     * @param list<array<string, mixed>> $tools the tools the turn was offered, from toInternalTools()
     */
    private function toOpenAiToolCalls(array $calls, array $tools): string {
        if ($calls === []) {
            return '';
        }

        $schemas = [];
        foreach ($tools as $tool) {
            $schemas[(string)($tool['name'] ?? '')] = $tool['input_schema'] ?? null;
        }

        $out = [];
        foreach ($calls as $call) {
            $name = (string)($call['name'] ?? '');
            $out[] = [
                'id' => (string)($call['id'] ?? ''),
                'type' => 'function',
                'function' => [
                    'name' => $name,
                    'arguments' => json_encode(JsonShape::arguments($call['arguments'] ?? null, $schemas[$name] ?? null)),
                ],
            ];
        }

        return json_encode($out) ?: '';
    }
}
