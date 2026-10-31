<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Command;

use OCA\AIquila\Service\AppSettings;
use OCA\AIquila\Talk\TalkBotService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ aiquila:talk-bot install|uninstall|status
 *
 * Talk only accepts bots set up by an administrator, so the bot is installed
 * here rather than when the app is enabled.
 */
class TalkBotCommand extends Command {
    public function __construct(
        private TalkBotService $bot,
        private AppSettings $config,
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this->setName('aiquila:talk-bot')
            ->setDescription('Install, uninstall or show the AIquila bot in Nextcloud Talk')
            ->addArgument('action', InputArgument::OPTIONAL, 'install, uninstall or status', 'status')
            ->addOption(
                'base-url',
                null,
                InputOption::VALUE_REQUIRED,
                'URL this server reaches itself on to post replies, if overwrite.cli.url does not work from the server (empty resets)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $action = (string)$input->getArgument('action');
        try {
            switch ($action) {
                case 'install':
                    $baseUrl = $input->getOption('base-url');
                    if (is_string($baseUrl)) {
                        if ($baseUrl === '') {
                            $this->config->deleteAppValue('aiquila', TalkBotService::CONFIG_BASE_URL);
                        } elseif (!preg_match('#^https?://#', $baseUrl)) {
                            $output->writeln('<error>--base-url must start with http:// or https://</error>');
                            return 1;
                        } else {
                            $this->config->setAppValue('aiquila', TalkBotService::CONFIG_BASE_URL, $baseUrl);
                        }
                    }
                    $this->bot->install();
                    $output->writeln('<info>✓ AIquila bot installed in Talk.</info>');
                    $output->writeln('  Moderators can now add it under Conversation settings → Bots,');
                    $output->writeln('  or: occ talk:bot:list, then occ talk:bot:setup <bot-id> <conversation-token>');
                    return 0;
                case 'uninstall':
                    $this->bot->uninstall();
                    $output->writeln('<info>✓ AIquila bot removed from Talk; buffered messages deleted.</info>');
                    return 0;
                case 'status':
                    $output->writeln('Talk:  ' . ($this->bot->isTalkAvailable() ? 'enabled' : 'not enabled'));
                    $output->writeln('Bot:   ' . ($this->bot->isInstalled() ? 'installed' : 'not installed'));
                    $baseUrl = $this->config->getAppValue('aiquila', TalkBotService::CONFIG_BASE_URL, '');
                    $output->writeln('Reply URL: ' . ($baseUrl !== '' ? $baseUrl : '(overwrite.cli.url)'));
                    return 0;
                default:
                    $output->writeln('<error>Unknown action "' . $action . '" (install, uninstall, status)</error>');
                    return 1;
            }
        } catch (\Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return 1;
        }
    }
}
