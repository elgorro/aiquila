<?php

declare(strict_types=1);

namespace OCA\AIquila\SetupCheck;

use OCA\AIquila\Service\ClaudeSDKService;
use OCA\AIquila\Service\CredentialService;
use OCP\IL10N;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;

class AnthropicApiReachable implements ISetupCheck {
    public function __construct(
        private ClaudeSDKService $sdk,
        private CredentialService $credentials,
        private IL10N $l10n,
    ) {
    }

    public function getName(): string {
        return $this->l10n->t('AIquila API connectivity');
    }

    public function getCategory(): string {
        return 'network';
    }

    public function run(): SetupResult {
        if (!$this->credentials->hasApiKey(null)) {
            return SetupResult::info(
                $this->l10n->t('Skipped — no API key configured. Configure a key first, then re-run this check.')
            );
        }

        try {
            $models = $this->sdk->listModels(null);
            if ($models !== null) {
                return SetupResult::success($this->l10n->t('Anthropic API is reachable.'));
            }
            return SetupResult::error(
                $this->l10n->t('Could not reach the Anthropic API. Check your network configuration and API key.')
            );
        } catch (\Throwable $e) {
            return SetupResult::error(
                $this->l10n->t('Anthropic API request failed: %s', [$e->getMessage()])
            );
        }
    }
}
