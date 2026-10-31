<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Migration;

use OCA\AIquila\Service\CredentialService;
use OCP\Config\IUserConfig;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Moves the plaintext `api_key` settings early releases stored into the
 * credential manager, for the instance and for every user.
 *
 * CredentialService already does this lazily when a key is read, but a key
 * nobody reads again would otherwise stay in plaintext in the app and user
 * config tables — and show up in `occ config:list`. After this step no AIquila
 * secret is kept in app or user config at all.
 */
class RepairMovePlaintextApiKeys implements IRepairStep {
    public function __construct(
        private CredentialService $credentials,
        private IAppConfig $appConfig,
        private IUserConfig $userConfig,
        private LoggerInterface $logger,
    ) {
    }

    public function getName(): string {
        return 'Move plaintext AIquila API keys into the credential manager';
    }

    public function run(IOutput $output): void {
        $moved = 0;
        try {
            if ($this->appConfig->hasKey('aiquila', 'api_key')) {
                $this->credentials->migratePlaintextApiKey(null);
                $moved++;
            }
            foreach (array_keys($this->userConfig->getValuesByUsers('aiquila', 'api_key')) as $userId) {
                $this->credentials->migratePlaintextApiKey((string)$userId);
                $moved++;
            }
        } catch (\Throwable $e) {
            $this->logger->warning('AIquila: moving plaintext API keys failed', ['exception' => $e]);
            $output->warning('Could not move every plaintext API key: ' . $e->getMessage());
            return;
        }
        if ($moved > 0) {
            $output->info('Removed ' . $moved . ' plaintext API key(s); keys not yet in the credential manager were moved there.');
        }
    }
}
