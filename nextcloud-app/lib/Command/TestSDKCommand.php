<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\AIquila\Command;

use OC\Core\Command\Base;
use OCA\AIquila\Service\ClaudeSDKService;
use OCA\AIquila\Service\Provider\LLMProviderFactory;
use OCA\AIquila\Service\Provider\NoPermittedProviderException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class TestSDKCommand extends Base {
    private ClaudeSDKService $claudeService;
    private LLMProviderFactory $providerFactory;

    public function __construct(ClaudeSDKService $claudeService, LLMProviderFactory $providerFactory) {
        parent::__construct();
        $this->claudeService = $claudeService;
        $this->providerFactory = $providerFactory;
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
}
