<?php

namespace OCA\AIquila\Command;

use OC\Core\Command\Base;
use OCA\AIquila\Service\ClaudeModels;
use OCP\IConfig;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ConfigureCommand extends Base {
    private IConfig $config;
    private const APP_NAME = 'aiquila';

    public function __construct(IConfig $config) {
        parent::__construct();
        $this->config = $config;
    }

    protected function configure(): void {
        $this
            ->setName('aiquila:configure')
            ->setDescription('Configure AIquila settings')
            ->addOption(
                'api-key',
                null,
                InputOption::VALUE_REQUIRED,
                'Set Claude API key (starts with sk-ant-)'
            )
            ->addOption(
                'model',
                null,
                InputOption::VALUE_REQUIRED,
                'Set Claude model (e.g., claude-opus-4-6, claude-sonnet-4-5-20250929)'
            )
            ->addOption(
                'max-tokens',
                null,
                InputOption::VALUE_REQUIRED,
                'Set maximum tokens (1-100000, default: 4096)'
            )
            ->addOption(
                'timeout',
                null,
                InputOption::VALUE_REQUIRED,
                'Set API timeout in seconds (10-1800, default: 30)'
            )
            ->addOption(
                'show',
                null,
                InputOption::VALUE_NONE,
                'Show current configuration (API key will be masked)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        // Show current configuration if requested
        if ($input->getOption('show')) {
            return $this->showConfiguration($output);
        }

        $updated = false;

        // Set API key
        $apiKey = $input->getOption('api-key');
        if ($apiKey !== null) {
            if (!str_starts_with($apiKey, 'sk-ant-')) {
                $output->writeln('<error>Invalid API key format. Must start with sk-ant-</error>');
                return 1;
            }
            $this->config->setAppValue(self::APP_NAME, 'api_key', $apiKey);
            $output->writeln('<info>✓ API key updated</info>');
            $updated = true;
        }

        // Set model
        $model = $input->getOption('model');
        if ($model !== null) {
            if (empty($model)) {
                $output->writeln('<error>Model cannot be empty</error>');
                return 1;
            }
            $this->config->setAppValue(self::APP_NAME, 'model', $model);
            $output->writeln('<info>✓ Model updated to: ' . $model . '</info>');
            $updated = true;
        }

        // Set max tokens
        $maxTokens = $input->getOption('max-tokens');
        if ($maxTokens !== null) {
            $maxTokensInt = (int)$maxTokens;
            if ($maxTokensInt < 1 || $maxTokensInt > 100000) {
                $output->writeln('<error>Max tokens must be between 1 and 100000</error>');
                return 1;
            }
            $this->config->setAppValue(self::APP_NAME, 'max_tokens', (string)$maxTokensInt);
            $output->writeln('<info>✓ Max tokens updated to: ' . $maxTokensInt . '</info>');
            $updated = true;
        }

        // Set timeout
        $timeout = $input->getOption('timeout');
        if ($timeout !== null) {
            $timeoutInt = (int)$timeout;
            if ($timeoutInt < 10 || $timeoutInt > 1800) {
                $output->writeln('<error>Timeout must be between 10 and 1800 seconds</error>');
                return 1;
            }
            $this->config->setAppValue(self::APP_NAME, 'api_timeout', (string)$timeoutInt);
            $output->writeln('<info>✓ API timeout updated to: ' . $timeoutInt . ' seconds</info>');
            $updated = true;
        }

        if (!$updated) {
            $output->writeln('<comment>No options provided. Use --help for usage information or --show to view current configuration.</comment>');
            return 0;
        }

        $output->writeln('');
        $output->writeln('<info>Configuration updated successfully!</info>');
        return 0;
    }

    private function showConfiguration(OutputInterface $output): int {
        $apiKey = $this->config->getAppValue(self::APP_NAME, 'api_key', '');
        $model = $this->config->getAppValue(self::APP_NAME, 'model', ClaudeModels::DEFAULT_MODEL);
        $maxTokens = $this->config->getAppValue(self::APP_NAME, 'max_tokens', '4096');
        $timeout = $this->config->getAppValue(self::APP_NAME, 'api_timeout', '30');

        $output->writeln('');
        $output->writeln('<info>AIquila Configuration:</info>');
        $output->writeln('');

        // Mask API key for security
        if (!empty($apiKey)) {
            $maskedKey = substr($apiKey, 0, 10) . '...' . substr($apiKey, -4);
            $output->writeln('  API Key:    <comment>' . $maskedKey . '</comment> (configured)');
        } else {
            $output->writeln('  API Key:    <error>Not configured</error>');
        }

        $output->writeln('  Model:      <comment>' . $model . '</comment>');
        $output->writeln('  Max Tokens: <comment>' . $maxTokens . '</comment>');
        $output->writeln('  Timeout:    <comment>' . $timeout . ' seconds</comment>');
        $output->writeln('');

        return 0;
    }
}
