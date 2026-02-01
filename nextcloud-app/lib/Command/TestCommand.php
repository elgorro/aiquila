<?php

namespace OCA\AIquila\Command;

use OC\Core\Command\Base;
use OCA\AIquila\Service\ClaudeService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class TestCommand extends Base {
    private ClaudeService $claudeService;

    public function __construct(ClaudeService $claudeService) {
        parent::__construct();
        $this->claudeService = $claudeService;
    }

    protected function configure(): void {
        $this
            ->setName('aiquila:test')
            ->setDescription('Test AIquila Claude integration')
            ->addOption(
                'prompt',
                'p',
                InputOption::VALUE_REQUIRED,
                'Test prompt to send to Claude',
                'Hello, Claude! Please respond with a brief greeting.'
            )
            ->addOption(
                'file',
                'f',
                InputOption::VALUE_REQUIRED,
                'Path to file to include in the prompt (relative to user files)'
            )
            ->addOption(
                'user',
                'u',
                InputOption::VALUE_REQUIRED,
                'User ID to test with',
                'admin'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $prompt = $input->getOption('prompt');
        $filePath = $input->getOption('file');
        $userId = $input->getOption('user');

        $output->writeln('');
        $output->writeln('<info>Testing AIquila Claude Integration</info>');
        $output->writeln('<comment>═══════════════════════════════════</comment>');
        $output->writeln('');

        // Test 1: Configuration check
        $output->writeln('<comment>1. Checking Configuration...</comment>');
        $config = $this->claudeService->getConfiguration();

        if (empty($config['api_key'])) {
            $output->writeln('<error>✗ API Key not configured!</error>');
            $output->writeln('  Run: php occ aiquila:configure --api-key "sk-ant-..."');
            $output->writeln('');
            return 1;
        }

        $maskedKey = substr($config['api_key'], 0, 10) . '...' . substr($config['api_key'], -4);
        $output->writeln('<info>✓ API Key: ' . $maskedKey . '</info>');
        $output->writeln('<info>✓ Model: ' . $config['model'] . '</info>');
        $output->writeln('<info>✓ Max Tokens: ' . $config['max_tokens'] . '</info>');
        $output->writeln('<info>✓ Timeout: ' . $config['timeout'] . 's</info>');
        $output->writeln('');

        // Test 2: Simple prompt test
        $output->writeln('<comment>2. Testing Claude API Connection...</comment>');
        $output->writeln('   Prompt: <info>' . $prompt . '</info>');
        $output->writeln('');

        try {
            $startTime = microtime(true);

            $response = $this->claudeService->sendMessage($prompt, $userId, $filePath);

            $duration = round(microtime(true) - $startTime, 2);

            $output->writeln('<info>✓ Response received in ' . $duration . 's</info>');
            $output->writeln('');
            $output->writeln('<comment>Claude\'s Response:</comment>');
            $output->writeln('<comment>─────────────────────</comment>');
            $output->writeln($response);
            $output->writeln('<comment>─────────────────────</comment>');
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

        // Test 3: Token usage (if available)
        $output->writeln('<comment>3. Test Summary</comment>');
        $output->writeln('<info>✓ All tests passed successfully!</info>');
        $output->writeln('');

        return 0;
    }
}
