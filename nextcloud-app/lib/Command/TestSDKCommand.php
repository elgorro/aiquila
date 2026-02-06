<?php

namespace OCA\AIquila\Command;

use OC\Core\Command\Base;
use OCA\AIquila\Service\ClaudeSDKService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class TestSDKCommand extends Base {
    private ClaudeSDKService $claudeService;

    public function __construct(ClaudeSDKService $claudeService) {
        parent::__construct();
        $this->claudeService = $claudeService;
    }

    protected function configure(): void {
        $this
            ->setName('aiquila:test-sdk')
            ->setDescription('Test AIquila SDK integration (Anthropic PHP SDK)')
            ->addOption(
                'prompt',
                'p',
                InputOption::VALUE_REQUIRED,
                'Test prompt to send to Claude',
                'Hello, Claude! Please respond with "SDK Test Successful" if you receive this.'
            )
            ->addOption(
                'user',
                'u',
                InputOption::VALUE_REQUIRED,
                'User ID to test with (optional)'
            )
            ->addOption(
                'stream',
                's',
                InputOption::VALUE_NONE,
                'Enable streaming mode'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $prompt = $input->getOption('prompt');
        $userId = $input->getOption('user');
        $useStream = $input->getOption('stream');

        $output->writeln('');
        $output->writeln('<info>Testing AIquila SDK Integration</info>');
        $output->writeln('<comment>════════════════════════════════════</comment>');
        $output->writeln('');

        // Test 1: Configuration check
        $output->writeln('<comment>1. Checking SDK Configuration...</comment>');

        $apiKey = $this->claudeService->getApiKey($userId);
        if (empty($apiKey)) {
            $output->writeln('<error>✗ API Key not configured!</error>');
            $output->writeln('  Run: php occ aiquila:configure --api-key "sk-ant-..."');
            $output->writeln('');
            return 1;
        }

        $maskedKey = substr($apiKey, 0, 10) . '...' . substr($apiKey, -4);
        $output->writeln('<info>✓ API Key: ' . $maskedKey . '</info>');
        $output->writeln('<info>✓ Model: ' . $this->claudeService->getModel() . '</info>');
        $output->writeln('<info>✓ Max Tokens: ' . $this->claudeService->getMaxTokens() . '</info>');
        $output->writeln('<info>✓ SDK Version: Anthropic PHP SDK 0.4.0</info>');
        $output->writeln('');

        // Test 2: API Connection Test
        $output->writeln('<comment>2. Testing Claude API via SDK...</comment>');
        $output->writeln('   Prompt: <info>' . $prompt . '</info>');
        $output->writeln('   Mode: <info>' . ($useStream ? 'Streaming' : 'Standard') . '</info>');
        $output->writeln('');

        try {
            $startTime = microtime(true);

            if ($useStream) {
                // Streaming mode
                $output->write('<comment>Claude\'s Response (streaming):</comment>');
                $output->writeln('');
                $output->writeln('<comment>─────────────────────────────────</comment>');

                foreach ($this->claudeService->askStream($prompt, '', $userId) as $chunk) {
                    $output->write($chunk);
                }

                $output->writeln('');
                $output->writeln('<comment>─────────────────────────────────</comment>');
            } else {
                // Standard mode
                $result = $this->claudeService->ask($prompt, '', $userId);

                $duration = round(microtime(true) - $startTime, 2);

                if (isset($result['error'])) {
                    $output->writeln('<error>✗ Error: ' . $result['error'] . '</error>');
                    $output->writeln('');
                    return 1;
                }

                $output->writeln('<info>✓ Response received in ' . $duration . 's</info>');
                $output->writeln('');
                $output->writeln('<comment>Claude\'s Response:</comment>');
                $output->writeln('<comment>─────────────────────</comment>');
                $output->writeln($result['response']);
                $output->writeln('<comment>─────────────────────</comment>');
            }

            $output->writeln('');

        } catch (\Exception $e) {
            $output->writeln('<error>✗ SDK Error: ' . $e->getMessage() . '</error>');
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
        $output->writeln('<info>✓ SDK integration test passed successfully!</info>');
        $output->writeln('');
        $output->writeln('<comment>SDK Features Available:</comment>');
        $output->writeln('  • Type-safe API calls');
        $output->writeln('  • Automatic retries (rate limits, timeouts)');
        $output->writeln('  • Specific exception handling');
        $output->writeln('  • Streaming support (use --stream flag)');
        $output->writeln('  • Built-in logging');
        $output->writeln('');

        return 0;
    }
}
