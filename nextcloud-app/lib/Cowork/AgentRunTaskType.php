<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Cowork;

use OCA\AIquila\Db\Coworker;
use OCA\AIquila\Db\CoworkerRun;
use OCA\AIquila\Db\PromptMapper;
use OCA\AIquila\Service\Exception\RunCancelledException;
use OCA\AIquila\Service\Exception\ValidationException;
use OCA\AIquila\Service\InstructionsService;
use OCA\AIquila\Service\McpClientService;
use OCA\AIquila\Service\Provider\LLMProviderFactory;
use OCA\AIquila\Service\UsageStatService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use Psr\Log\LoggerInterface;

/**
 * `agent:run`: run a free-form prompt on a schedule, with a per-coworker
 * allowlist of the instance's MCP tools.
 *
 * The prompt is the coworker's own (`custom_prompt`) or one of the owner's
 * saved prompts (`prompt_id`). `options.tools` names the MCP tools the run may
 * call; empty or missing runs the prompt without tools. Only allowlisted
 * tools are offered, and the executor refuses any other name even if the
 * model asks for it. Tool errors go back to the model, not into the run.
 *
 * The answer is the run summary. With `output_type: file` it is also written
 * to `output_path` in the owner's files, replacing what was there.
 *
 * Options: `tools` (string[]), `effort`, `thinking`, and `taskBudget` (int,
 * tokens; see ClaudeSDKService task budgets).
 */
class AgentRunTaskType implements CoworkerTaskType {
    /** Tool-loop iterations per run, as in chat. */
    public const MAX_ITERATIONS = 10;

    public function __construct(
        private readonly LLMProviderFactory $providerFactory,
        private readonly McpClientService $mcpClient,
        private readonly PromptMapper $prompts,
        private readonly IRootFolder $rootFolder,
        private readonly UsageStatService $usageStats,
        private readonly LoggerInterface $logger,
        private readonly ?InstructionsService $instructions = null,
    ) {
    }

    public function getId(): string {
        return 'agent:run';
    }

    public function getLabel(): string {
        return 'Run a prompt with tools';
    }

    public function getFamily(): string {
        return 'agent';
    }

    public function validateOptions(array $options): void {
        $tools = $options['tools'] ?? [];
        if (!is_array($tools) || !array_is_list($tools)) {
            throw new ValidationException('options.tools must be a list of tool names');
        }
        foreach ($tools as $tool) {
            if (!is_string($tool) || $tool === '') {
                throw new ValidationException('options.tools must contain only tool names (strings)');
            }
        }
        $budget = $options['taskBudget'] ?? null;
        if ($budget !== null && (!is_int($budget) || $budget < 20000)) {
            throw new ValidationException('options.taskBudget must be a number of tokens, at least 20000');
        }
    }

    public function run(Coworker $coworker, CoworkerRun $run, callable $progress): array {
        $userId = $coworker->getUserId();
        $options = $this->decodeOptions($coworker);
        $prompt = $this->resolvePrompt($coworker);
        $provider = $this->providerFactory->getProviderForUser($userId, $coworker->getProvider());

        $system = $this->instructions?->withInstance(InstructionsService::SCOPE_COWORKERS, $userId, null);
        $request = CoworkerRequestOptions::build($coworker, $provider, $options, $system);
        unset($request['system']);
        if (is_int($options['taskBudget'] ?? null)) {
            $request['task_budget'] = $options['taskBudget'];
        }

        $progress(0, 1);
        $messages = [['role' => 'user', 'content' => $prompt]];
        /** @var list<string> $allowlist */
        $allowlist = array_values($options['tools'] ?? []);

        if ($allowlist === []) {
            $result = $provider->chat($messages, $system, $userId, $request);
        } else {
            [$tools, $mapping] = $this->allowedTools($allowlist, $run);
            $stop = new class {
                public bool $cancelled = false;
            };
            $executor = function (string $name, array $input) use ($allowlist, $mapping, $run, $progress, $coworker, $stop): array {
                // A stop lands here between tool calls; the model is told to
                // wrap up, and the run ends as cancelled after the loop.
                if (!$stop->cancelled) {
                    try {
                        $progress(0, 1);
                    } catch (RunCancelledException) {
                        $stop->cancelled = true;
                    }
                }
                if ($stop->cancelled) {
                    return self::toolError('The run was cancelled. Do not call more tools.');
                }
                if (!in_array($name, $allowlist, true) || !isset($mapping[$name])) {
                    $this->logger->warning('AIquila Cowork agent: refused a tool outside the allowlist', [
                        'coworker' => $coworker->getId(),
                        'tool' => $name,
                    ]);
                    $run->appendLog(CoworkerRun::LOG_WARN, 'Refused a tool outside the allowlist: ' . $name);
                    return self::toolError('Tool ' . $name . ' is not available to this coworker.');
                }
                $result = $this->mcpClient->executeTool($name, $input, $mapping);
                $run->appendLog(
                    empty($result['isError']) ? CoworkerRun::LOG_INFO : CoworkerRun::LOG_WARN,
                    (empty($result['isError']) ? 'Called ' : 'Tool failed: ') . $name,
                );
                return $result;
            };
            $result = $provider->chatWithTools($messages, $tools, $executor, $system, $userId, $request, self::MAX_ITERATIONS);
        }

        $this->usageStats->record($userId, $provider, $result, UsageStatService::TYPE_COWORKER);
        // Raises RunCancelledException if the run was stopped meanwhile.
        $progress(1, 1);
        if (isset($result['error'])) {
            throw new \RuntimeException(($result['error_type'] ?? null) === 'refused'
                ? 'Refused: ' . $result['error']
                : (string)$result['error']);
        }

        $answer = (string)($result['response'] ?? '');
        if ($coworker->getOutputType() === 'file') {
            $path = $this->writeOutput($coworker, $answer);
            $run->appendLog(CoworkerRun::LOG_INFO, 'Wrote the answer', $path);
        }

        return ['itemsTotal' => 1, 'itemsProcessed' => 1, 'summary' => $answer];
    }

    /**
     * The coworker's own prompt, or the owner's saved one.
     *
     * @throws \RuntimeException when there is neither
     */
    private function resolvePrompt(Coworker $coworker): string {
        $custom = $coworker->getCustomPrompt();
        if ($custom !== null && trim($custom) !== '') {
            return $custom;
        }
        $promptId = $coworker->getPromptId();
        if ($promptId !== null) {
            try {
                return $this->prompts->findByIdAndUser($promptId, $coworker->getUserId())->getContent();
            } catch (DoesNotExistException) {
                throw new \RuntimeException('The saved prompt ' . $promptId . ' no longer exists');
            }
        }
        throw new \RuntimeException('This coworker has no prompt to run');
    }

    /**
     * The instance's MCP tools narrowed to the allowlist. A listed name that
     * no server offers right now is logged, not fatal.
     *
     * @param list<string> $allowlist
     * @return array{0: list<array<string, mixed>>, 1: array<string, mixed>}
     */
    private function allowedTools(array $allowlist, CoworkerRun $run): array {
        $all = $this->mcpClient->getAllTools();
        $tools = array_values(array_filter(
            $all['tools'],
            static fn (array $t): bool => in_array($t['name'] ?? null, $allowlist, true),
        ));
        $mapping = array_intersect_key($all['mapping'], array_flip($allowlist));
        $missing = array_diff($allowlist, array_column($tools, 'name'));
        if ($missing !== []) {
            $run->appendLog(CoworkerRun::LOG_WARN, 'Not offered by any MCP server now: ' . implode(', ', $missing));
        }
        return [$tools, $mapping];
    }

    /**
     * @return string the written file's path, as the owner sees it
     */
    private function writeOutput(Coworker $coworker, string $answer): string {
        $path = $coworker->getOutputPath();
        if ($path === null || trim($path) === '') {
            throw new \RuntimeException('output_type is "file" but no output_path is set');
        }
        $userFolder = $this->rootFolder->getUserFolder($coworker->getUserId());
        if ($userFolder->nodeExists($path)) {
            $node = $userFolder->get($path);
            if (!$node instanceof File) {
                throw new \RuntimeException($path . ' is a folder, not a file');
            }
            $node->putContent($answer);
            return UserPath::of($node);
        }
        $parent = dirname($path);
        $folder = $parent === '/' || $parent === '.' ? $userFolder : $userFolder->get($parent);
        if (!$folder instanceof Folder) {
            throw new \RuntimeException($parent . ' is not a folder');
        }
        return UserPath::of($folder->newFile(basename($path), $answer));
    }

    /** @return array<string, mixed> */
    private function decodeOptions(Coworker $coworker): array {
        $raw = $coworker->getOptions();
        $decoded = $raw !== null && $raw !== '' ? json_decode($raw, true) : null;
        return is_array($decoded) ? $decoded : [];
    }

    /** @return array{content: list<array{type: string, text: string}>, isError: true} */
    private static function toolError(string $message): array {
        return ['content' => [['type' => 'text', 'text' => $message]], 'isError' => true];
    }
}
