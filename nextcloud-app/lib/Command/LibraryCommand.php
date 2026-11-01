<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Command;

use OCA\AIquila\Service\LibraryTransfer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ aiquila:library export|import
 *
 * Moves the instance library (instructions, organization prompts, project and
 * coworker templates) between instances as JSON. Users' own entries stay put.
 */
class LibraryCommand extends Command {
    public function __construct(
        private LibraryTransfer $transfer,
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this->setName('aiquila:library')
            ->setDescription('Export or import the instance prompt library, templates and instructions as JSON')
            ->addArgument('action', InputArgument::REQUIRED, 'export or import')
            ->addArgument('file', InputArgument::OPTIONAL, 'File to write or read; "-" or none means stdout/stdin', '-')
            ->addOption('replace', null, InputOption::VALUE_NONE, 'import: delete the existing instance entries first')
            ->addOption('author', null, InputOption::VALUE_REQUIRED, 'import: user id recorded as the author of new entries', 'admin');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $action = (string)$input->getArgument('action');
        $file = (string)$input->getArgument('file');

        if ($action === 'export') {
            $json = json_encode($this->transfer->export(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
            if ($file === '-') {
                $output->write($json, false, OutputInterface::OUTPUT_RAW);
                return 0;
            }
            if (file_put_contents($file, $json) === false) {
                $output->writeln('<error>Could not write ' . $file . '</error>');
                return 1;
            }
            $output->writeln('<info>✓ Exported to ' . $file . '</info>');
            return 0;
        }

        if ($action === 'import') {
            $raw = $file === '-' ? stream_get_contents(STDIN) : @file_get_contents($file);
            if (!is_string($raw)) {
                $output->writeln('<error>Could not read ' . $file . '</error>');
                return 1;
            }
            $data = json_decode($raw, true);
            if (!is_array($data)) {
                $output->writeln('<error>Not valid JSON</error>');
                return 1;
            }
            try {
                $counts = $this->transfer->import($data, (string)$input->getOption('author'), (bool)$input->getOption('replace'));
            } catch (\InvalidArgumentException $e) {
                $output->writeln('<error>' . $e->getMessage() . '</error>');
                return 1;
            }
            $output->writeln(sprintf(
                '<info>✓ Imported %d prompt(s), %d project template(s), %d coworker template(s)%s</info>',
                $counts['prompts'],
                $counts['projectTemplates'],
                $counts['coworkerTemplates'],
                $counts['instructions'] ? ' and the instance instructions' : '',
            ));
            return 0;
        }

        $output->writeln('<error>Unknown action "' . $action . '" (export, import)</error>');
        return 1;
    }
}
