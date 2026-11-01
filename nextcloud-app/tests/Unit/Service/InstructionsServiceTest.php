<?php

declare(strict_types=1);

namespace OCA\AIquila\Tests\Unit\Service;

use OCA\AIquila\Db\Project;
use OCA\AIquila\Db\ProjectPath;
use OCA\AIquila\Service\AppSettings;
use OCA\AIquila\Service\InstructionsService;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

class InstructionsServiceTest extends TestCase {
    /** @var array<string, string> */
    private array $app = [];
    /** @var array<string, string> keyed "uid/app/key" */
    private array $user = [];

    private function service(): InstructionsService {
        $config = $this->createStub(AppSettings::class);
        $config->method('getAppValue')->willReturnCallback(
            fn (string $app, string $key, string $default = ''): string => $this->app[$key] ?? $default,
        );
        $config->method('setAppValue')->willReturnCallback(
            function (string $app, string $key, string $value): void {
                $this->app[$key] = $value;
            },
        );
        $config->method('deleteAppValue')->willReturnCallback(
            function (string $app, string $key): void {
                unset($this->app[$key]);
            },
        );
        $config->method('getUserValue')->willReturnCallback(
            fn (string $uid, string $app, string $key, string $default = ''): string => $this->user["$uid/$app/$key"] ?? $default,
        );
        $users = $this->createStub(IUserManager::class);
        $users->method('getDisplayName')->willReturnCallback(static fn (string $uid): ?string => $uid === 'alice' ? 'Alice Example' : null);
        return new InstructionsService($config, $users);
    }

    private function project(?string $systemPrompt): Project {
        $project = new Project();
        $project->setTitle('Taxes');
        $project->setSystemPrompt($systemPrompt);
        return $project;
    }

    private function path(string $path, string $type): ProjectPath {
        $p = new ProjectPath();
        $p->setPath($path);
        $p->setPathType($type);
        return $p;
    }

    public function testChatLayersAreInstanceUserProjectThenPaths(): void {
        $this->app['instance_instructions'] = 'We are ACME.';
        $this->user['alice/aiquila/default_system_prompt'] = 'Be brief.';

        $prompt = $this->service()->forChat('alice', $this->project('Cite the law.'), [$this->path('/Taxes', 'directory')]);

        $this->assertSame("We are ACME.\n\nBe brief.\n\nCite the law.\n\nProject: Taxes\n- [directory] /Taxes", $prompt);
    }

    public function testEmptyLayersAreSkipped(): void {
        $this->user['alice/aiquila/default_system_prompt'] = 'Be brief.';

        $this->assertSame('Be brief.', $this->service()->forChat('alice', $this->project(null)));
    }

    public function testNoLayersMeansNoSystemPrompt(): void {
        $this->assertNull($this->service()->forChat('alice'));
    }

    public function testChatScopeOffDropsInstanceLayerButKeepsTheUsers(): void {
        $this->app['instance_instructions'] = 'We are ACME.';
        $this->app['instance_instructions_chat'] = '0';
        $this->user['alice/aiquila/default_system_prompt'] = 'Be brief.';

        $this->assertSame('Be brief.', $this->service()->forChat('alice'));
    }

    public function testDefaultScopesCoverChatAndTalkOnly(): void {
        $this->assertSame(
            ['chat' => true, 'talk' => true, 'coworkers' => false, 'tasks' => false],
            $this->service()->getScopes(),
        );
    }

    public function testWithInstanceRespectsScope(): void {
        $this->app['instance_instructions'] = 'We are ACME.';
        $service = $this->service();

        $this->assertSame("We are ACME.\n\nYou are a bot.", $service->withInstance(InstructionsService::SCOPE_TALK, 'alice', 'You are a bot.'));
        $this->assertSame('Summarise.', $service->withInstance(InstructionsService::SCOPE_TASKS, 'alice', 'Summarise.'));
        $this->assertNull($service->withInstance(InstructionsService::SCOPE_COWORKERS, 'alice', null));

        $service->setScopes(['coworkers' => true, 'bogus' => true]);
        $this->assertSame('We are ACME.', $service->withInstance(InstructionsService::SCOPE_COWORKERS, 'alice', null));
        $this->assertArrayNotHasKey('instance_instructions_bogus', $this->app);
    }

    public function testVariablesResolveInOurLayersOnly(): void {
        $this->app['instance_instructions'] = 'Address {user} in {locale}.';
        $this->user['alice/core/locale'] = 'de_DE';
        $service = $this->service();

        $this->assertSame('Address Alice Example in de_DE.', $service->forChat('alice'));
        // A caller's own prompt is left alone: it is not ours to rewrite.
        $this->assertSame(
            "Address Alice Example in de_DE.\n\nKeep {user} literal.",
            $service->withInstance(InstructionsService::SCOPE_TALK, 'alice', 'Keep {user} literal.'),
        );
    }

    public function testVariablesWithoutAUserResolveEmpty(): void {
        $this->app['instance_instructions'] = 'Hello {user}.';

        $this->assertSame('Hello .', $this->service()->withInstance(InstructionsService::SCOPE_TALK, null, null));
    }

    public function testLocaleFallsBackToLanguage(): void {
        $this->user['alice/core/lang'] = 'fr';

        $this->assertSame('fr', $this->service()->resolveVariables('{locale}', 'alice'));
    }

    public function testSettingEmptyInstructionsDeletesThem(): void {
        $service = $this->service();
        $service->setInstanceInstructions('  text  ');
        $this->assertSame('text', $this->app['instance_instructions']);

        $service->setInstanceInstructions('   ');
        $this->assertArrayNotHasKey('instance_instructions', $this->app);
    }

    public function testTooLong(): void {
        $this->assertNull(InstructionsService::tooLong(null));
        $this->assertNull(InstructionsService::tooLong(str_repeat('ä', InstructionsService::MAX_LENGTH)));
        $this->assertSame(
            'Title is longer than 3 characters',
            InstructionsService::tooLong('four', 3, 'Title'),
        );
    }
}
