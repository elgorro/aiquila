<?php

namespace OCA\AIquila\Tests\Unit\Service;

use OCA\AIquila\Service\OccAllowlist;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

class OccAllowlistTest extends TestCase {
    private function allowlist(string $configured = ''): OccAllowlist {
        $config = $this->createStub(IConfig::class);
        $config->method('getAppValue')->willReturn($configured);
        return new OccAllowlist($config);
    }

    public function testDefaultAllowsReadOnlyCommands(): void {
        $list = $this->allowlist();
        $this->assertTrue($list->isAllowed('status', ['--output=json']));
        $this->assertTrue($list->isAllowed('maintenance:mode', []));
        $this->assertTrue($list->isAllowed('theming:config', []));
        $this->assertTrue($list->isAllowed('user:info', ['alice']));
    }

    public function testDefaultAllowsCommandsOfDedicatedTools(): void {
        $list = $this->allowlist();
        $this->assertTrue($list->isAllowed('app:install', ['tasks', '--keep-disabled']));
        $this->assertTrue($list->isAllowed('app:remove', ['tasks']));
        $this->assertTrue($list->isAllowed('aiquila:configure', ['--show']));
        $this->assertTrue($list->isAllowed('aiquila:test', ['--prompt', 'hi']));
    }

    public function testDefaultRefusesWritesAndArguments(): void {
        $list = $this->allowlist();
        $this->assertFalse($list->isAllowed('maintenance:mode', ['--on']));
        $this->assertFalse($list->isAllowed('theming:config', ['name', 'Evil Corp']));
        $this->assertFalse($list->isAllowed('trashbin:cleanup', ['--all-users']));
        $this->assertFalse($list->isAllowed('notification:generate', ['alice', 'hi']));
        $this->assertFalse($list->isAllowed('files:scan', ['--all']));
        $this->assertFalse($list->isAllowed('config:app:get', ['mail', 'secret']));
        $this->assertFalse($list->isAllowed('maint:mode', []));
    }

    public function testConfiguredListReplacesDefault(): void {
        $list = $this->allowlist(' maintenance:mode --on , config:app:get core * ,');
        $this->assertTrue($list->isAllowed('maintenance:mode', ['--on']));
        $this->assertFalse($list->isAllowed('maintenance:mode', ['--off']));
        $this->assertFalse($list->isAllowed('maintenance:mode', []));
        $this->assertTrue($list->isAllowed('config:app:get', ['core']));
        $this->assertTrue($list->isAllowed('config:app:get', ['core', 'vendor']));
        $this->assertFalse($list->isAllowed('config:app:get', ['mail', 'secret']));
        $this->assertFalse($list->isAllowed('status', []));
    }
}
