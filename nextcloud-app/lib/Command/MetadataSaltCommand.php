<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Command;

use OC\Core\Command\Base;
use OCA\AIquila\Service\RequestMetadataService;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * Show or rotate the salt behind the hashed `metadata.user_id` sent to
 * Anthropic. See docs/dev/request-metadata.md.
 */
class MetadataSaltCommand extends Base {

    public function __construct(
        private RequestMetadataService $requestMetadata,
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this
            ->setName('aiquila:metadata-salt')
            ->setDescription('Show or rotate the salt used to hash user ids sent to Anthropic')
            ->addOption('rotate', null, InputOption::VALUE_NONE, 'Generate a new salt, making previously sent hashes unresolvable')
            ->addOption('hash', null, InputOption::VALUE_REQUIRED, 'Print the hash for this Nextcloud user id instead of the salt');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        if ($input->getOption('rotate')) {
            if (!$this->confirmRotate($input, $output)) {
                $output->writeln('<comment>Aborted — the salt is unchanged.</comment>');
                return 1;
            }
            $output->writeln('<info>New salt:</info> ' . $this->requestMetadata->rotateSalt());
            $output->writeln('<comment>Hashes sent before now can no longer be resolved back to a user.</comment>');
            return 0;
        }

        $uid = $input->getOption('hash');
        if (is_string($uid) && $uid !== '') {
            // Deliberately independent of the opt-in switch: an admin answering
            // an abuse report needs the mapping even after turning sending off.
            $output->writeln(hash_hmac('sha256', $uid, $this->requestMetadata->getSalt()));
            return 0;
        }

        $output->writeln('<info>Sending enabled:</info> ' . ($this->requestMetadata->isEnabled() ? 'yes' : 'no'));
        $output->writeln('<info>Salt:</info> ' . $this->requestMetadata->getSalt());
        return 0;
    }

    private function confirmRotate(InputInterface $input, OutputInterface $output): bool {
        if (!$input->isInteractive()) {
            return true;
        }
        $helper = $this->getHelper('question');
        if (!$helper instanceof QuestionHelper) {
            return true;
        }
        return (bool)$helper->ask(
            $input,
            $output,
            new ConfirmationQuestion('Rotate the metadata salt? Previously sent hashes become unresolvable. [y/N] ', false),
        );
    }
}
