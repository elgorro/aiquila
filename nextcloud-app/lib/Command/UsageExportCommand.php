<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Command;

use OCA\AIquila\Service\UsageExport\UsageExportFilter;
use OCA\AIquila\Service\UsageExport\UsageExportService;
use OCP\AppFramework\Utility\ITimeFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ aiquila:usage:export
 *
 * The admin usage export for cron jobs and scripts: the same rows, columns
 * and filters as GET /api/admin/usage/export, written to stdout or a file.
 */
class UsageExportCommand extends Command {
    public function __construct(
        private UsageExportService $export,
        private ITimeFactory $time,
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this->setName('aiquila:usage:export')
            ->setDescription('Export token usage as CSV or JSON, per request or summed per day, week or month')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'csv or json', UsageExportFilter::FORMAT_CSV)
            ->addOption('granularity', null, InputOption::VALUE_REQUIRED, 'request, day, week or month (UTC; weeks start on Monday)', UsageExportFilter::GRANULARITY_REQUEST)
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'start, inclusive: YYYY-MM-DD (UTC) or an ISO 8601 date-time with offset')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'end, exclusive; a bare date includes that whole day')
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'only this user')
            ->addOption('group', null, InputOption::VALUE_REQUIRED, 'only the members of this group')
            ->addOption('model', null, InputOption::VALUE_REQUIRED, 'only this model id')
            ->addOption('provider', null, InputOption::VALUE_REQUIRED, 'only this provider id')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'only this request type: chat, api, task, coworker, talk or flow')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'file to write; "-" means stdout', '-');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        try {
            $filter = UsageExportFilter::fromInput(
                self::option($input, 'format'),
                self::option($input, 'granularity'),
                self::option($input, 'from'),
                self::option($input, 'to'),
                self::option($input, 'model'),
                self::option($input, 'provider'),
                self::option($input, 'type'),
            );
        } catch (\InvalidArgumentException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return 1;
        }

        $user = self::option($input, 'user');
        $group = self::option($input, 'group');
        if ($user !== null && $group !== null) {
            $output->writeln('<error>Use --user or --group, not both</error>');
            return 1;
        }
        if ($group !== null) {
            $userIds = $this->export->groupUserIds($group);
            if ($userIds === null) {
                $output->writeln('<error>Group "' . $group . '" not found</error>');
                return 1;
            }
        } else {
            $userIds = $user !== null ? [$user] : $this->export->allUserIds($filter);
        }

        $chunks = $this->export->stream($filter, $userIds, true, $this->time->getTime());

        $file = self::option($input, 'output') ?? '-';
        if ($file === '-') {
            foreach ($chunks as $chunk) {
                $output->write($chunk, false, OutputInterface::OUTPUT_RAW);
            }
            return 0;
        }

        $handle = @fopen($file, 'wb');
        if ($handle === false) {
            $output->writeln('<error>Could not write ' . $file . '</error>');
            return 1;
        }
        try {
            foreach ($chunks as $chunk) {
                if (fwrite($handle, $chunk) === false) {
                    $output->writeln('<error>Could not write ' . $file . '</error>');
                    return 1;
                }
            }
        } finally {
            fclose($handle);
        }
        $output->writeln('<info>✓ Exported to ' . $file . '</info>');
        return 0;
    }

    private static function option(InputInterface $input, string $name): ?string {
        $value = $input->getOption($name);
        return is_string($value) && $value !== '' ? $value : null;
    }
}
