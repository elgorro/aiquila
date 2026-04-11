<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Command;

use OC\Core\Command\Base;
use OCA\AIquila\Db\McpServer;
use OCA\AIquila\Db\McpServerMapper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class McpAddCommand extends Base {
    public function __construct(
        private McpServerMapper $mapper,
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this
            ->setName('aiquila:mcp:add')
            ->setDescription('Register an MCP server in the AIquila app')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Display name for the MCP server')
            ->addOption('url', null, InputOption::VALUE_REQUIRED, 'MCP server URL (e.g. http://mcp:3339/mcp)')
            ->addOption('auth', null, InputOption::VALUE_REQUIRED, 'Auth type: none, bearer, or oauth', 'none')
            ->addOption('token', null, InputOption::VALUE_REQUIRED, 'Bearer token (required when --auth=bearer)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $name = $input->getOption('name');
        $url = $input->getOption('url');
        $authType = $input->getOption('auth');
        $token = $input->getOption('token');

        if (!$name || !$url) {
            $output->writeln('<error>--name and --url are required</error>');
            return 1;
        }

        if (!in_array($authType, ['none', 'bearer', 'oauth'], true)) {
            $output->writeln('<error>--auth must be one of: none, bearer, oauth</error>');
            return 1;
        }

        if ($authType === 'bearer' && !$token) {
            $output->writeln('<error>--token is required when --auth=bearer</error>');
            return 1;
        }

        $now = time();
        $server = new McpServer();
        $server->setDisplayName($name);
        $server->setUrl($url);
        $server->setAuthType($authType);
        if ($token) {
            $server->setAuthToken($token);
        }
        $server->setIsEnabled(true);
        $server->setCreatedAt($now);
        $server->setUpdatedAt($now);

        $server = $this->mapper->insert($server);

        $output->writeln('<info>MCP server registered (id=' . $server->getId() . ')</info>');
        return 0;
    }
}
