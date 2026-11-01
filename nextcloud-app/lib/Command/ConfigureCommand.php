<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\AIquila\Command;

use OC\Core\Command\Base;
use OCA\AIquila\Service\ClaudeModels;
use OCA\AIquila\Service\ClaudeSDKService;
use OCA\AIquila\Service\CredentialService;
use OCA\AIquila\Service\Provider\LLMProviderFactory;
use OCA\AIquila\Service\Provider\LLMProviderInterface;
use OCA\AIquila\Service\Provider\ProviderSettingsSchema;
use OCA\AIquila\Service\Provider\ProviderSettingsService;
use OCA\AIquila\Service\ThinkingPolicy;
use OCA\AIquila\Service\AppSettings;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ConfigureCommand extends Base {
    private AppSettings $config;
    private CredentialService $credentials;
    private LLMProviderFactory $providerFactory;
    private ProviderSettingsService $providerSettings;
    private const APP_NAME = 'aiquila';

    public function __construct(
        AppSettings $config,
        CredentialService $credentials,
        LLMProviderFactory $providerFactory,
        ProviderSettingsService $providerSettings,
    ) {
        parent::__construct();
        $this->config = $config;
        $this->credentials = $credentials;
        $this->providerFactory = $providerFactory;
        $this->providerSettings = $providerSettings;
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
                'Set Claude model (e.g., claude-fable-5, claude-opus-5, claude-sonnet-5)'
            )
            ->addOption(
                'max-tokens',
                null,
                InputOption::VALUE_REQUIRED,
                'Set maximum tokens (1-128000, default: 4096)'
            )
            ->addOption(
                'effort',
                null,
                InputOption::VALUE_REQUIRED,
                'Set default effort level (low|medium|high|xhigh|max, empty string to reset to model default)'
            )
            ->addOption(
                'thinking',
                null,
                InputOption::VALUE_REQUIRED,
                'Default thinking (auto|on|off; auto follows the model)'
            )
            ->addOption(
                'task-effort',
                null,
                InputOption::VALUE_REQUIRED,
                'Effort for background tasks and coworkers (low|medium|high|xhigh|max, empty string to use the default effort)'
            )
            ->addOption(
                'task-thinking',
                null,
                InputOption::VALUE_REQUIRED,
                'Thinking for background tasks and coworkers (auto|on|off, empty string to use the default thinking)'
            )
            ->addOption(
                'thinking-budget',
                null,
                InputOption::VALUE_REQUIRED,
                'Pin the thinking budget in tokens (>= ' . ClaudeSDKService::MIN_THINKING_BUDGET . ', empty string for adaptive)'
            )
            ->addOption(
                'service-tier',
                null,
                InputOption::VALUE_REQUIRED,
                'Set the service tier (' . implode('|', ClaudeModels::ALL_SERVICE_TIERS) . ', empty string to use the account default)'
            )
            ->addOption(
                'fast',
                null,
                InputOption::VALUE_REQUIRED,
                'Enable fast mode by default — premium pricing, Opus 5 / Opus 4.8 only (on|off)'
            )
            ->addOption(
                'auto-cache',
                null,
                InputOption::VALUE_REQUIRED,
                'Let the API place a cache breakpoint on the growing end of a conversation (on|off, default: on)'
            )
            ->addOption(
                'files-api',
                null,
                InputOption::VALUE_REQUIRED,
                'Upload attachments to the Anthropic Files API instead of inlining them each turn (on|off, default: on)'
            )
            ->addOption(
                'timeout',
                null,
                InputOption::VALUE_REQUIRED,
                'Set API timeout in seconds (10-1800, default: 30)'
            )
            ->addOption(
                'provider',
                null,
                InputOption::VALUE_REQUIRED,
                'Make this LLM provider the instance default (anthropic, mistral, deepseek, hetzner, local, ...)'
            )
            ->addOption(
                'target-provider',
                null,
                InputOption::VALUE_REQUIRED,
                'Provider that --provider-key, --provider-model, --provider-setting and --show apply to, without making it the default'
            )
            ->addOption(
                'provider-key',
                null,
                InputOption::VALUE_REQUIRED,
                'Set the API key for the target provider (--target-provider, else --provider, else anthropic)'
            )
            ->addOption(
                'provider-model',
                null,
                InputOption::VALUE_REQUIRED,
                'Set the model for the target provider (--target-provider, else --provider, else anthropic)'
            )
            ->addOption(
                'provider-setting',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Set a field of the target provider as id=value (or its app config key), validated like the admin settings page (repeatable; --show --target-provider=<id> lists the ids)'
            )
            ->addOption(
                'show',
                null,
                InputOption::VALUE_NONE,
                'Show current configuration (API key will be masked)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $providerIds = $this->providerFactory->getProviderIds();
        $provider = $input->getOption('provider');
        $targetOption = $input->getOption('target-provider');
        foreach (['provider' => $provider, 'target-provider' => $targetOption] as $option => $id) {
            if ($id !== null && !in_array($id, $providerIds, true)) {
                $output->writeln('<error>Unknown provider "' . $id . '" for --' . $option . '. Available: ' . implode(', ', $providerIds) . '</error>');
                return 1;
            }
        }

        // Show current configuration if requested
        if ($input->getOption('show')) {
            return $this->showConfiguration($output, $targetOption);
        }

        $updated = false;

        // Provider-scoped key, model and settings. Used by headless
        // provisioning (aiquila-hetzner) to point a fresh install at a
        // non-Anthropic backend without touching the web UI.
        //
        // --target-provider names the provider these apply to without touching
        // the instance default, so a second provider can be set up while every
        // user stays on the current one. --provider on its own both targets and
        // activates, which is what a fresh install wants.
        $targetProvider = $targetOption ?? $provider ?? LLMProviderFactory::DEFAULT_PROVIDER;
        $providerKey = $input->getOption('provider-key');
        $providerModel = $input->getOption('provider-model');
        /** @var list<string> $settingArgs */
        $settingArgs = $input->getOption('provider-setting');

        $values = [];
        if ($providerKey !== null) {
            if ($providerKey === '') {
                $output->writeln('<error>--provider-key cannot be empty</error>');
                return 1;
            }
            if ($targetProvider === LLMProviderFactory::DEFAULT_PROVIDER && !str_starts_with($providerKey, 'sk-ant-')) {
                $output->writeln('<error>Invalid Anthropic API key format. Must start with sk-ant-</error>');
                return 1;
            }
            $values['api_key'] = $providerKey;
        }
        if ($providerModel !== null) {
            if ($providerModel === '') {
                $output->writeln('<error>--provider-model cannot be empty</error>');
                return 1;
            }
            $values['model'] = $providerModel;
        }

        $target = $this->providerFactory->getProviderById($targetProvider);
        $schema = $this->indexSchema($target);
        foreach ($settingArgs as $arg) {
            $eq = strpos($arg, '=');
            if ($eq === false || $eq === 0) {
                $output->writeln('<error>--provider-setting expects id=value, got "' . $arg . '"</error>');
                return 1;
            }
            $fieldId = substr($arg, 0, $eq);
            $field = $schema[$fieldId] ?? null;
            if ($field === null) {
                // The app config key works too (local_timeout for timeout), since
                // that is the name `occ config:app:get` shows.
                foreach ($schema as $candidate) {
                    if (($candidate['key'] ?? null) === $fieldId) {
                        $field = $candidate;
                        $fieldId = (string)$candidate['id'];
                        break;
                    }
                }
            }
            if ($field === null || !$this->isCliWritable($field)) {
                $output->writeln('<error>Unknown setting "' . $fieldId . '" for provider ' . $targetProvider
                    . '. Available: ' . implode(', ', array_keys(array_filter($schema, $this->isCliWritable(...)))) . '</error>');
                return 1;
            }
            if (array_key_exists($fieldId, $values)) {
                $output->writeln('<error>"' . $fieldId . '" is given more than once</error>');
                return 1;
            }
            $value = substr($arg, $eq + 1);
            if (($field['type'] ?? null) === ProviderSettingsSchema::TYPE_CHECKBOX) {
                // The schema encoder reads anything it does not know as off, so a
                // typo would silently disable the feature. Spell it out instead.
                $bool = match (strtolower($value)) {
                    'on', 'yes', 'true', '1' => true,
                    'off', 'no', 'false', '0' => false,
                    default => null,
                };
                if ($bool === null) {
                    $output->writeln('<error>' . $fieldId . ' takes on or off, got "' . $value . '"</error>');
                    return 1;
                }
                $values[$fieldId] = $bool;
                continue;
            }
            // Access lists take principal ids; a comma-separated string is the
            // shell-friendly form of the list the settings page sends.
            $values[$fieldId] = ($field['storage'] ?? null) === ProviderSettingsSchema::STORAGE_ACCESS
                ? array_values(array_filter(array_map('trim', explode(',', $value)), fn(string $v) => $v !== ''))
                : $value;
        }

        if ($values !== []) {
            // One call: every value is validated before any is stored, so a
            // bad setting leaves the provider as it was.
            try {
                $rejected = $this->providerSettings->writeAdmin($target, $values);
            } catch (\InvalidArgumentException $e) {
                $output->writeln('<error>' . $e->getMessage() . '</error>');
                return 1;
            }
            if ($rejected !== []) {
                // Only reachable for api_key / model on a provider without them.
                $output->writeln('<error>Provider ' . $targetProvider . ' has no ' . implode(', ', $rejected) . ' setting; nothing was changed</error>');
                return 1;
            }
            foreach (array_keys($values) as $fieldId) {
                $output->writeln(match ($fieldId) {
                    'api_key' => '<info>✓ API key updated for provider: ' . $targetProvider . '</info>',
                    'model' => '<info>✓ Model for ' . $targetProvider . ' updated to: ' . $providerModel . '</info>',
                    default => '<info>✓ ' . $targetProvider . ' setting ' . $fieldId . ' updated</info>',
                });
            }
            $updated = true;
        }

        // Activate last, so a provider is only made the default once its
        // configuration has been accepted.
        if ($provider !== null) {
            $this->config->setAppValue(self::APP_NAME, 'provider', $provider);
            $output->writeln('<info>✓ Active provider set to: ' . $provider . '</info>');
            $updated = true;
        }

        // Set API key
        $apiKey = $input->getOption('api-key');
        if ($apiKey !== null) {
            if (!str_starts_with($apiKey, 'sk-ant-')) {
                $output->writeln('<error>Invalid API key format. Must start with sk-ant-</error>');
                return 1;
            }
            $this->credentials->setApiKey(null, $apiKey);
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
            if ($maxTokensInt < 1 || $maxTokensInt > 128000) {
                $output->writeln('<error>Max tokens must be between 1 and 128000</error>');
                return 1;
            }
            $this->config->setAppValue(self::APP_NAME, 'max_tokens', (string)$maxTokensInt);
            $output->writeln('<info>✓ Max tokens updated to: ' . $maxTokensInt . '</info>');
            $updated = true;
        }

        // Set default effort level
        $effort = $input->getOption('effort');
        if ($effort !== null) {
            if ($effort !== '' && !in_array($effort, ClaudeModels::ALL_EFFORTS, true)) {
                $output->writeln('<error>Effort must be one of: ' . implode(', ', ClaudeModels::ALL_EFFORTS) . ' (or empty to reset)</error>');
                return 1;
            }
            $this->config->setAppValue(self::APP_NAME, 'effort', $effort);
            $output->writeln('<info>✓ Default effort ' . ($effort === '' ? 'reset to model default' : 'updated to: ' . $effort) . '</info>');
            $updated = true;
        }

        // Set the thinking defaults (chat and background tasks)
        foreach (['thinking' => 'thinking', 'task-thinking' => 'task_thinking'] as $option => $key) {
            $thinking = $input->getOption($option);
            if ($thinking === null) {
                continue;
            }
            $allowed = $option === 'thinking' ? ThinkingPolicy::MODES : array_merge([''], ThinkingPolicy::MODES);
            if (!in_array($thinking, $allowed, true)) {
                $output->writeln('<error>--' . $option . ' must be one of: ' . implode(', ', ThinkingPolicy::MODES) . '</error>');
                return 1;
            }
            // Stored blank for auto, matching the settings UI.
            $this->config->setAppValue(self::APP_NAME, $key, $thinking === ThinkingPolicy::MODE_AUTO ? '' : $thinking);
            $output->writeln('<info>✓ ' . ($option === 'thinking' ? 'Thinking' : 'Task thinking') . ' default updated to: '
                . ($thinking === '' ? '(default thinking)' : $thinking) . '</info>');
            $updated = true;
        }

        // Set the effort for background tasks
        $taskEffort = $input->getOption('task-effort');
        if ($taskEffort !== null) {
            if ($taskEffort !== '' && !in_array($taskEffort, ClaudeModels::ALL_EFFORTS, true)) {
                $output->writeln('<error>Task effort must be one of: ' . implode(', ', ClaudeModels::ALL_EFFORTS) . ' (or empty to reset)</error>');
                return 1;
            }
            $this->config->setAppValue(self::APP_NAME, 'task_effort', $taskEffort);
            $output->writeln('<info>✓ Task effort ' . ($taskEffort === '' ? 'reset to the default effort' : 'updated to: ' . $taskEffort) . '</info>');
            $updated = true;
        }

        // Set the default thinking budget
        $thinkingBudget = $input->getOption('thinking-budget');
        if ($thinkingBudget !== null) {
            // The upper bound is per-request (it depends on max_tokens at send
            // time), so only the floor can be checked here.
            if ($thinkingBudget !== '' && (!ctype_digit($thinkingBudget) || (int)$thinkingBudget < ClaudeSDKService::MIN_THINKING_BUDGET)) {
                $output->writeln('<error>Thinking budget must be at least ' . ClaudeSDKService::MIN_THINKING_BUDGET . ' tokens (or empty to use adaptive thinking)</error>');
                return 1;
            }
            $this->config->setAppValue(self::APP_NAME, 'thinking_budget', $thinkingBudget);
            $output->writeln('<info>✓ Thinking budget ' . ($thinkingBudget === '' ? 'cleared — using adaptive thinking' : 'updated to: ' . $thinkingBudget . ' tokens') . '</info>');
            $updated = true;
        }

        // Set the service tier
        $serviceTier = $input->getOption('service-tier');
        if ($serviceTier !== null) {
            if ($serviceTier !== '' && !ClaudeModels::isAllowedServiceTier($serviceTier)) {
                $output->writeln('<error>Service tier must be one of: ' . implode(', ', ClaudeModels::ALL_SERVICE_TIERS) . ' (or empty to use the account default)</error>');
                return 1;
            }
            $this->config->setAppValue(self::APP_NAME, 'service_tier', $serviceTier);
            $output->writeln('<info>✓ Service tier ' . ($serviceTier === '' ? 'cleared — using the account default' : 'updated to: ' . $serviceTier) . '</info>');
            $updated = true;
        }

        // Set the fast-mode default
        $fast = $input->getOption('fast');
        if ($fast !== null) {
            if (!in_array($fast, ['on', 'off'], true)) {
                $output->writeln('<error>Fast mode must be "on" or "off"</error>');
                return 1;
            }
            $this->config->setAppValue(self::APP_NAME, 'speed_fast', $fast === 'on' ? 'true' : 'false');
            $output->writeln('<info>✓ Fast mode default updated to: ' . $fast . '</info>');
            if ($fast === 'on') {
                $output->writeln('<comment>  Fast mode costs roughly twice as much per token and only applies on Opus 5 and Opus 4.8.</comment>');
            }
            $updated = true;
        }

        // Set automatic prompt caching
        $autoCache = $input->getOption('auto-cache');
        if ($autoCache !== null) {
            if (!in_array($autoCache, ['on', 'off'], true)) {
                $output->writeln('<error>Auto cache must be "on" or "off"</error>');
                return 1;
            }
            $this->config->setAppValue(self::APP_NAME, 'auto_cache', $autoCache === 'on' ? '1' : '0');
            $output->writeln('<info>✓ Automatic prompt caching updated to: ' . $autoCache . '</info>');
            $updated = true;
        }

        // Set Files API uploads
        $filesApi = $input->getOption('files-api');
        if ($filesApi !== null) {
            if (!in_array($filesApi, ['on', 'off'], true)) {
                $output->writeln('<error>Files API must be "on" or "off"</error>');
                return 1;
            }
            $this->config->setAppValue(self::APP_NAME, 'files_api', $filesApi === 'on' ? '1' : '0');
            $output->writeln('<info>✓ Files API uploads updated to: ' . $filesApi . '</info>');
            if ($filesApi === 'off') {
                $output->writeln('<comment>  Files already uploaded are deleted by the daily cleanup job.</comment>');
            }
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

    /**
     * Field ids --provider-setting accepts for a provider: admin-writable
     * fields that store something (not action buttons).
     */
    private function isCliWritable(array $field): bool {
        return ProviderSettingsSchema::isAdminWritable($field)
            && (isset($field['key']) || !empty($field['sensitive'])
                || ($field['storage'] ?? null) === ProviderSettingsSchema::STORAGE_ACCESS);
    }

    /** @return array<string, array<string, mixed>> */
    private function indexSchema(LLMProviderInterface $provider): array {
        $indexed = [];
        foreach (array_merge($provider->getSettingsSchema(), ProviderSettingsSchema::accessLists()) as $field) {
            $indexed[(string)$field['id']] = $field;
        }
        return $indexed;
    }

    private function showConfiguration(OutputInterface $output, ?string $targetProvider = null): int {
        if ($targetProvider !== null) {
            return $this->showProviderSettings($output, $targetProvider);
        }

        // Report the *active* provider's key and model, not the Anthropic ones —
        // otherwise a hetzner/mistral/local instance looks unconfigured here.
        $providerId = $this->providerFactory->getActiveProviderId();
        $provider = $this->providerFactory->getProviderById($providerId);
        $hasKey = $this->credentials->hasApiKey(null, $providerId);
        $model = $provider->getModel();
        $maxTokens = (string)$provider->getMaxTokens();
        $effort = $this->config->getAppValue(self::APP_NAME, 'effort', '');
        $thinking = ThinkingPolicy::normalizeMode($this->config->getAppValue(self::APP_NAME, 'thinking', ''));
        $taskEffort = $this->config->getAppValue(self::APP_NAME, 'task_effort', '');
        $taskThinking = $this->config->getAppValue(self::APP_NAME, 'task_thinking', '');
        $thinkingBudget = $this->config->getAppValue(self::APP_NAME, 'thinking_budget', '');
        $serviceTier = $this->config->getAppValue(self::APP_NAME, 'service_tier', '');
        $fast = in_array($this->config->getAppValue(self::APP_NAME, 'speed_fast', 'false'), ['true', '1'], true);
        $autoCache = in_array($this->config->getAppValue(self::APP_NAME, 'auto_cache', '1'), ['true', '1'], true);
        $filesApi = in_array($this->config->getAppValue(self::APP_NAME, 'files_api', '1'), ['true', '1'], true);
        $timeout = $this->config->getAppValue(self::APP_NAME, 'api_timeout', '30');

        $output->writeln('');
        $output->writeln('<info>AIquila Configuration:</info>');
        $output->writeln('');
        $output->writeln('  Provider:   <comment>' . $providerId . '</comment>');

        if ($hasKey) {
            $output->writeln('  API Key:    <comment>(configured)</comment>');
        } else {
            $output->writeln('  API Key:    <error>Not configured</error>');
        }

        $output->writeln('  Model:      <comment>' . $model . '</comment>');
        $output->writeln('  Max Tokens: <comment>' . $maxTokens . '</comment>');
        $output->writeln('  Effort:     <comment>' . ($effort !== '' ? $effort : '(model default)') . '</comment>');
        $output->writeln('  Thinking:   <comment>' . ($thinking === ThinkingPolicy::MODE_AUTO ? 'auto (model default)' : $thinking) . '</comment>');
        $output->writeln('  Task effort:   <comment>' . ($taskEffort !== '' ? $taskEffort : '(default effort)') . '</comment>');
        $output->writeln('  Task thinking: <comment>' . ($taskThinking !== '' ? ThinkingPolicy::normalizeMode($taskThinking) : '(default thinking)') . '</comment>');
        $output->writeln('  Budget:     <comment>' . ($thinkingBudget !== '' ? $thinkingBudget . ' tokens' : '(adaptive)') . '</comment>');
        $output->writeln('  Tier:       <comment>' . ($serviceTier !== '' ? $serviceTier : '(account default)') . '</comment>');
        $output->writeln('  Fast mode:  <comment>' . ($fast ? 'on' : 'off') . '</comment>');
        $output->writeln('  Auto cache: <comment>' . ($autoCache ? 'on' : 'off') . '</comment>');
        $output->writeln('  Files API:  <comment>' . ($filesApi ? 'on' : 'off') . '</comment>');
        $output->writeln('  Timeout:    <comment>' . $timeout . ' seconds</comment>');
        $output->writeln('');

        return 0;
    }

    /**
     * Every setting of one provider by field id — the ids --provider-setting
     * takes. Secrets are reported as set or not, never shown.
     */
    private function showProviderSettings(OutputInterface $output, string $providerId): int {
        $provider = $this->providerFactory->getProviderById($providerId);
        $described = $this->providerSettings->describe($provider, null, true);
        $active = $this->config->getAppValue(self::APP_NAME, 'provider', LLMProviderFactory::DEFAULT_PROVIDER);

        $output->writeln('');
        $output->writeln('<info>' . $provider->getLabel() . ' (' . $providerId . ')'
            . ($active === $providerId ? ' — instance default' : '') . '</info>');
        $output->writeln('  Configured: <comment>' . ($described['configured'] ? 'yes' : 'no') . '</comment>');
        $output->writeln('');
        foreach ($described['fields'] as $field) {
            if (!$this->isCliWritable($field)) {
                continue;
            }
            if (!empty($field['sensitive'])) {
                $shown = !empty($field['hasValue']) ? '(configured)' : '(not set)';
            } else {
                $value = $field['value'] ?? '';
                $shown = match (true) {
                    is_bool($value) => $value ? 'on' : 'off',
                    is_array($value) => $value === [] ? '(empty)' : implode(',', array_map('strval', $value)),
                    $value === '' => '(not set)',
                    default => (string)$value,
                };
            }
            $output->writeln('  ' . str_pad((string)$field['id'], 20) . ' <comment>' . $shown . '</comment>');
        }
        $output->writeln('');

        return 0;
    }
}
