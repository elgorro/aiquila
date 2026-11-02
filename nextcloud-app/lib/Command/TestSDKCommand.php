<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\AIquila\Command;

use OC\Core\Command\Base;
use OCA\AIquila\Service\ClaudeSDKService;
use OCA\AIquila\Service\InstructionsService;
use OCA\AIquila\Service\Provider\LLMProviderFactory;
use OCA\AIquila\Service\Provider\LLMProviderInterface;
use OCA\AIquila\Service\Provider\NoPermittedProviderException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class TestSDKCommand extends Base {
    private ClaudeSDKService $claudeService;
    private LLMProviderFactory $providerFactory;
    private InstructionsService $instructions;

    public function __construct(ClaudeSDKService $claudeService, LLMProviderFactory $providerFactory, InstructionsService $instructions) {
        parent::__construct();
        $this->claudeService = $claudeService;
        $this->providerFactory = $providerFactory;
        $this->instructions = $instructions;
    }

    /**
     * Enough to tell two keys apart, not enough to help guess one: a local
     * bearer token can be short, so a long prefix would give most of it away.
     */
    public static function maskKey(string $key): string {
        return strlen($key) >= 16 ? '…' . substr($key, -4) : '(set)';
    }

    protected function configure(): void {
        $this
            ->setName('aiquila:test')
            ->setDescription('Send a test prompt through the provider that serves a user (or the instance default)')
            ->addOption(
                'prompt',
                'p',
                InputOption::VALUE_REQUIRED,
                'Test prompt to send',
                'Hello! Please respond with "Test Successful" if you receive this.'
            )
            ->addOption(
                'user',
                'u',
                InputOption::VALUE_REQUIRED,
                'User ID to test with; their personal provider, key and model apply (optional)'
            )
            ->addOption(
                'provider',
                null,
                InputOption::VALUE_REQUIRED,
                'Provider to test instead of the one that would serve the user (e.g. local)'
            )
            ->addOption(
                'stream',
                's',
                InputOption::VALUE_NONE,
                'Enable streaming mode (anthropic only)'
            )
            ->addOption(
                'cache-check',
                null,
                InputOption::VALUE_NONE,
                'Send the chat system prompt twice and report whether the second request reads it from the prompt cache (anthropic only)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $prompt = $input->getOption('prompt');
        $userId = $input->getOption('user');
        $useStream = $input->getOption('stream');

        $providerOption = $input->getOption('provider');
        try {
            if ($providerOption !== null) {
                if (!$this->providerFactory->isKnownProviderId($providerOption)) {
                    $output->writeln('<error>Unknown provider "' . $providerOption . '". Available: ' . implode(', ', $this->providerFactory->getProviderIds()) . '</error>');
                    return 1;
                }
                if ($userId !== null && !$this->providerFactory->isAllowedForUser($providerOption, $userId)) {
                    $output->writeln('<error>User ' . $userId . ' may not use provider ' . $providerOption . '</error>');
                    return 1;
                }
                $provider = $this->providerFactory->getProviderById($providerOption);
            } else {
                // The same resolution a chat request makes: the user's own
                // choice, then the instance default, within their access lists.
                $provider = $this->providerFactory->getProvider($userId);
            }
        } catch (NoPermittedProviderException $e) {
            $output->writeln('<error>✗ ' . ($userId !== null ? 'User ' . $userId . ' is' : 'Everyone is') . ' denied every provider</error>');
            return 1;
        }
        $providerId = $provider->getId();
        $isAnthropic = $providerId === LLMProviderFactory::DEFAULT_PROVIDER;

        if ($useStream && !$isAnthropic) {
            $output->writeln('<error>--stream is only supported for the anthropic provider</error>');
            return 1;
        }
        $cacheCheck = (bool)$input->getOption('cache-check');
        if ($cacheCheck && !$isAnthropic) {
            $output->writeln('<error>--cache-check is only supported for the anthropic provider</error>');
            return 1;
        }

        $output->writeln('');
        $output->writeln('<info>Testing AIquila provider: ' . $provider->getLabel() . ' (' . $providerId . ')</info>');
        $output->writeln('<comment>════════════════════════════════════</comment>');
        $output->writeln('');

        // Test 1: Configuration check
        $output->writeln('<comment>1. Checking configuration...</comment>');

        if (!$provider->isConfigured($userId)) {
            $output->writeln('<error>✗ Provider ' . $providerId . ' is not configured!</error>');
            $output->writeln($isAnthropic
                ? '  Run: php occ aiquila:configure --api-key "sk-ant-..."'
                : '  Run: php occ aiquila:configure --target-provider=' . $providerId . ' --provider-key=... --provider-model=...');
            $output->writeln('');
            return 1;
        }

        $apiKey = $provider->getApiKey($userId);
        $output->writeln('<info>✓ API Key: ' . ($apiKey === '' ? '(none)' : self::maskKey($apiKey)) . '</info>');
        $output->writeln('<info>✓ Model: ' . $provider->getModel($userId) . '</info>');
        $output->writeln('<info>✓ Max Tokens: ' . $provider->getMaxTokens($userId) . '</info>');
        if ($isAnthropic) {
            $output->writeln('<info>✓ SDK Version: Anthropic PHP SDK ' . \Anthropic\VERSION . '</info>');
        }
        $output->writeln('');

        if ($cacheCheck) {
            return $this->checkCache($provider, $prompt, $userId, $output);
        }

        // Test 2: API Connection Test
        $output->writeln('<comment>2. Sending the test prompt...</comment>');
        $output->writeln('   Prompt: <info>' . $prompt . '</info>');
        $output->writeln('   Mode: <info>' . ($useStream ? 'Streaming' : 'Standard') . '</info>');
        $output->writeln('');

        try {
            $startTime = microtime(true);

            if ($useStream) {
                // Streaming mode
                $output->write('<comment>Response (streaming):</comment>');
                $output->writeln('');
                $output->writeln('<comment>─────────────────────────────────</comment>');

                foreach ($this->claudeService->askStream($prompt, '', $userId) as $chunk) {
                    $output->write($chunk);
                }

                $output->writeln('');
                $output->writeln('<comment>─────────────────────────────────</comment>');
            } else {
                // Standard mode
                $result = $provider->ask($prompt, '', $userId);

                $duration = round(microtime(true) - $startTime, 2);

                if (isset($result['error'])) {
                    $output->writeln('<error>✗ Error: ' . $result['error'] . '</error>');
                    $output->writeln('');
                    return 1;
                }

                $output->writeln('<info>✓ Response received in ' . (string)$duration . 's</info>');
                $output->writeln('');
                $output->writeln('<comment>Response:</comment>');
                $output->writeln('<comment>─────────────────────</comment>');
                $output->writeln($result['response'] ?? '');
                $output->writeln('<comment>─────────────────────</comment>');
            }

            $output->writeln('');

        } catch (\Exception $e) {
            $output->writeln('<error>✗ Error: ' . $e->getMessage() . '</error>');
            $output->writeln('');

            // Provide helpful debugging info
            $output->writeln('<comment>Debug Information:</comment>');
            $output->writeln('  Exception Class: ' . get_class($e));
            if ($e->getCode()) {
                $output->writeln('  Error Code: ' . $e->getCode());
            }
            $output->writeln('');

            return 1;
        }

        // Test 3: Summary
        $output->writeln('<comment>3. Test Summary</comment>');
        $output->writeln('<info>✓ Provider ' . $providerId . ' answered</info>');
        $output->writeln('');

        return 0;
    }

    /**
     * Send the same request twice with the chat system prompt (instance and
     * user layers) and compare the usage the API reports. The system block is
     * marked `cache_control: ephemeral`; the API skips caching silently when
     * the prefix is under the model's minimum, so all three outcomes are
     * possible and only one of them is a fault:
     *
     *  - read on the second request: the prefix caches.
     *  - nothing written or read: the prefix is below the model's minimum.
     *    Expected for short prompts, and the minimum varies by model.
     *  - written but not read back: something in the prefix changes between
     *    identical requests. That is the silent invalidator to chase.
     */
    private function checkCache(LLMProviderInterface $provider, string $prompt, ?string $userId, OutputInterface $output): int {
        $system = $userId !== null
            ? $this->instructions->forChat($userId)
            : $this->instructions->compose(null, [$this->instructions->instanceLayer(InstructionsService::SCOPE_CHAT)]);
        if ($system === null || $system === '') {
            $output->writeln('<comment>No chat system prompt is configured' . ($userId !== null ? ' for ' . $userId : '') . ', so there is nothing to cache.</comment>');
            $output->writeln('  Set instance instructions or a user default, or pass --user.');
            return 0;
        }

        $output->writeln('<comment>2. Prompt-cache check</comment>');
        $output->writeln('   System prompt: <info>' . mb_strlen($system) . ' characters</info>');
        $output->writeln('');

        $usages = [];
        foreach ([1, 2] as $attempt) {
            $result = $provider->ask($prompt, '', $userId, ['system' => $system]);
            if (isset($result['error'])) {
                $output->writeln('<error>✗ Request ' . $attempt . ' failed: ' . $result['error'] . '</error>');
                return 1;
            }
            $usage = $result['usage'] ?? [];
            $usages[$attempt] = [
                'input' => (int)($usage['input_tokens'] ?? 0),
                'creation' => (int)($usage['cache_creation_tokens'] ?? 0),
                'read' => (int)($usage['cache_read_tokens'] ?? 0),
            ];
            $output->writeln(sprintf(
                '   Request %d: input %d, cache write %d, cache read %d',
                $attempt,
                $usages[$attempt]['input'],
                $usages[$attempt]['creation'],
                $usages[$attempt]['read'],
            ));
        }
        $output->writeln('');

        $written = $usages[1]['creation'] + $usages[1]['read'];
        if ($usages[2]['read'] > 0) {
            $output->writeln('<info>✓ Cache hit: the second request read ' . $usages[2]['read'] . ' tokens from the prompt cache</info>');
            return 0;
        }
        if ($written === 0 && $usages[2]['creation'] === 0) {
            $output->writeln('<comment>– Not cached: the prefix is below the minimum cacheable length for ' . $provider->getModel($userId) . '.</comment>');
            $output->writeln('  Expected for a short system prompt. The minimum depends on the model.');
            return 0;
        }
        $output->writeln('<error>✗ Cache miss: the prefix was written but not read back. Something in it changes between identical requests.</error>');
        return 1;
    }
}
