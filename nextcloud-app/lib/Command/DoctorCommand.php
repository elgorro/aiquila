<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Command;

use OC\Core\Command\Base;
use OCA\AIquila\Service\CredentialService;
use OCA\AIquila\Service\Provider\Cloud\CloudSdk;
use OCA\AIquila\SetupCheck\TextAssistantAvailable;
use OCP\App\IAppManager;
use OCP\TaskProcessing\IManager;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DoctorCommand extends Base {

    private const APP_ID = 'aiquila';
    private const PROVIDER_PREFIX = 'aiquila:';

    public function __construct(
        private IManager $taskManager,
        private IAppManager $appManager,
        private CredentialService $credentials,
        private TextAssistantAvailable $textCheck,
        private CloudSdk $cloudSdk,
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this
            ->setName('aiquila:doctor')
            ->setDescription('Diagnose AIquila integration with Nextcloud Assistant');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $ok = true;

        $output->writeln('<info>AIquila Assistant integration check</info>');
        $output->writeln('');

        $output->writeln('<comment>1. App status</comment>');
        if ($this->appManager->isEnabledForUser(self::APP_ID)) {
            $output->writeln('  <info>✓</info> aiquila enabled');
        } else {
            $output->writeln('  <error>✗</error> aiquila not enabled (run: occ app:enable aiquila)');
            $ok = false;
        }
        $assistantInstalled = $this->appManager->isEnabledForAnyone('assistant');
        $output->writeln($assistantInstalled
            ? '  <info>✓</info> nextcloud/assistant enabled'
            : '  <comment>·</comment> nextcloud/assistant not enabled (optional — AIquila still exposes task types to other consumers)');
        $output->writeln('');

        $output->writeln('<comment>2. TaskProcessing providers registered</comment>');
        $providers = $this->taskManager->getProviders();
        $ourProviders = [];
        foreach ($providers as $p) {
            if (str_starts_with($p->getId(), self::PROVIDER_PREFIX)) {
                $ourProviders[] = $p;
            }
        }
        if (count($ourProviders) === 0) {
            $output->writeln('  <error>✗</error> no aiquila:* providers registered');
            $output->writeln('    (check nextcloud.log at app boot — likely a DI failure,');
            $output->writeln('     missing vendor/autoload.php, or a PHP extension gap)');
            $ok = false;
        } else {
            $output->writeln(sprintf('  <info>✓</info> %d aiquila:* providers registered', count($ourProviders)));
            foreach ($ourProviders as $p) {
                $output->writeln(sprintf('    - %s → %s', $p->getId(), $p->getTaskTypeId()));
            }
        }
        $output->writeln('');

        $output->writeln('<comment>3. Task types visible to Assistant</comment>');
        $types = $this->taskManager->getAvailableTaskTypes();
        $ourTypes = [];
        $ourTaskTypeIds = array_map(fn($p) => $p->getTaskTypeId(), $ourProviders);
        foreach ($types as $id => $_def) {
            if (in_array($id, $ourTaskTypeIds, true)) {
                $ourTypes[] = $id;
            }
        }
        if (count($ourTypes) === 0 && count($ourProviders) > 0) {
            $output->writeln('  <error>✗</error> providers registered but no matching task types in IManager::getAvailableTaskTypes()');
            $output->writeln('    (an admin has likely disabled these task types in');
            $output->writeln('     Settings → Administration → Artificial intelligence)');
            $ok = false;
        } elseif (count($ourTypes) === 0) {
            $output->writeln('  <error>✗</error> no AIquila-backed task types exposed');
            $ok = false;
        } else {
            $output->writeln(sprintf('  <info>✓</info> %d AIquila task type(s) exposed to Assistant:', count($ourTypes)));
            foreach ($ourTypes as $t) {
                $output->writeln('    - ' . $t);
            }
            $missing = array_diff(array_unique($ourTaskTypeIds), $ourTypes);
            if (count($missing) > 0) {
                $output->writeln('');
                $output->writeln('  <comment>·</comment> providers registered for task types not present in this Nextcloud:');
                foreach ($missing as $t) {
                    $output->writeln('    - ' . $t . '  (disabled by admin, or task type missing in this NC version)');
                }
            }
        }
        $output->writeln('');

        $output->writeln('<comment>4. Text editor</comment>');
        $text = $this->textCheck->run();
        $output->writeln((in_array($text->getSeverity(), ['success', 'info'], true) ? '  <info>✓</info> ' : '  <comment>!</comment> ') . $text->getDescription());
        $output->writeln('');

        $output->writeln('<comment>5. Anthropic API key</comment>');
        if ($this->credentials->hasApiKey(null)) {
            $output->writeln('  <info>✓</info> API key configured');
        } else {
            $output->writeln('  <comment>·</comment> no API key (registration still works; task execution will fail until set)');
            $output->writeln('    set via: occ aiquila:configure --api-key "sk-ant-..."');
        }
        $output->writeln('');

        // Informational only: no cloud provider needs these for key or token auth.
        $output->writeln('<comment>6. Cloud SDKs (optional)</comment>');
        $autoload = $this->cloudSdk->autoloadPath();
        $output->writeln($autoload === ''
            ? '  <comment>·</comment> ' . CloudSdk::SYSTEM_CONFIG_KEY . ' not set'
            : '  <info>·</info> ' . CloudSdk::SYSTEM_CONFIG_KEY . ' = ' . $autoload);
        foreach ([
            CloudSdk::AWS => 'SigV4 and the AWS credential chain for the AWS-hosted Claude providers',
            CloudSdk::GOOGLE => 'service accounts and Application Default Credentials for the Google Cloud providers',
        ] as $package => $enables) {
            $output->writeln($this->cloudSdk->isAvailable($package)
                ? '  <info>✓</info> ' . $package . ' available (' . $enables . ')'
                : '  <comment>·</comment> ' . $package . ' not installed (needed for ' . $enables . ')');
        }
        $output->writeln('');

        if ($ok) {
            $output->writeln('<info>All required checks passed.</info>');
            return 0;
        }
        $output->writeln('<error>One or more required checks failed.</error>');
        return 1;
    }
}
