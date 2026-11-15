<?php

declare(strict_types=1);

namespace OCA\AIquila\Tests\Unit\Service;

use OCA\AIquila\Db\ProjectTemplate;
use OCA\AIquila\Db\ProjectTemplateMapper;
use OCA\AIquila\Db\Prompt;
use OCA\AIquila\Db\PromptMapper;
use OCA\AIquila\Service\CoworkerTemplates;
use OCA\AIquila\Service\InstructionsService;
use OCA\AIquila\Service\LibraryTransfer;
use OCA\AIquila\Db\Sequence;
use OCA\AIquila\Db\SequenceMapper;
use OCA\AIquila\Service\AppSettings;
use OCA\AIquila\Service\SequenceCatalog;
use OCA\AIquila\Service\SequencePolicy;
use OCA\AIquila\Service\SequenceValidator;
use PHPUnit\Framework\TestCase;

class LibraryTransferTest extends TestCase {
    /** @var array<string, string> */
    private array $app = [];

    /**
     * The sequence collaborators: a mapper, the real validator, a policy on
     * an in-memory app config.
     *
     * @return array{SequenceMapper, SequenceValidator, SequencePolicy}
     */
    private function sequenceDeps(?SequenceMapper $mapper = null): array {
        $settings = $this->createStub(AppSettings::class);
        $settings->method('getAppValue')->willReturnCallback(fn (string $a, string $k, string $d = ''): string => $this->app[$k] ?? $d);
        $settings->method('setAppValue')->willReturnCallback(function (string $a, string $k, string $v): void {
            $this->app[$k] = $v;
        });
        $catalog = new SequenceCatalog();
        return [$mapper ?? $this->createStub(SequenceMapper::class), new SequenceValidator($catalog), new SequencePolicy($settings, $catalog)];
    }
    private function prompt(string $title, string $content): Prompt {
        $p = new Prompt();
        $p->setScope('instance');
        $p->setTitle($title);
        $p->setContent($content);
        return $p;
    }

    public function testExportHoldsOnlyInstanceData(): void {
        $instructions = $this->createStub(InstructionsService::class);
        $instructions->method('getInstanceInstructions')->willReturn('We are ACME.');
        $instructions->method('getScopes')->willReturn(InstructionsService::SCOPE_DEFAULTS);
        $prompts = $this->createStub(PromptMapper::class);
        $prompts->method('findAllInstance')->willReturn([$this->prompt('Policy', 'No secrets.')]);
        $template = new ProjectTemplate();
        $template->setTitle('Tax');
        $template->setPathList([['path' => '/Taxes', 'pathType' => 'directory']]);
        $templates = $this->createStub(ProjectTemplateMapper::class);
        $templates->method('findAllInstance')->willReturn([$template]);
        $coworkers = $this->createStub(CoworkerTemplates::class);
        $coworkers->method('list')->willReturn([['scope' => 'instance', 'id' => 'org-1', 'title' => 'Tags']]);

        $export = (new LibraryTransfer($instructions, $prompts, $templates, $coworkers, ...$this->sequenceDeps()))->export();

        $this->assertSame('aiquila-library', $export['format']);
        $this->assertSame('We are ACME.', $export['instructions']['text']);
        $this->assertSame([['title' => 'Policy', 'description' => null, 'content' => 'No secrets.']], $export['prompts']);
        $this->assertSame([['path' => '/Taxes', 'pathType' => 'directory']], $export['projectTemplates'][0]['paths']);
        $this->assertSame([['id' => 'org-1', 'title' => 'Tags']], $export['coworkerTemplates']);
    }

    public function testImportUpsertsByTitle(): void {
        $existing = $this->prompt('Policy', 'old');
        $prompts = $this->createMock(PromptMapper::class);
        $prompts->method('findAllInstance')->willReturn([$existing]);
        $prompts->expects($this->once())->method('update')->with($this->callback(static fn (Prompt $p): bool => $p->getContent() === 'new'));
        $prompts->expects($this->once())->method('insert')->with($this->callback(
            static fn (Prompt $p): bool => $p->getTitle() === 'Fresh' && $p->getScope() === 'instance' && $p->getUserId() === 'boss',
        ));
        $prompts->expects($this->never())->method('delete');

        $instructions = $this->createMock(InstructionsService::class);
        $instructions->expects($this->once())->method('setInstanceInstructions')->with('Hi');
        $instructions->expects($this->once())->method('setScopes')->with(['coworkers' => true]);

        $counts = (new LibraryTransfer(
            $instructions,
            $prompts,
            $this->createStub(ProjectTemplateMapper::class),
            $this->createStub(CoworkerTemplates::class),
            ...$this->sequenceDeps(),
        ))->import([
            'format' => 'aiquila-library',
            'version' => 1,
            'instructions' => ['text' => 'Hi', 'scopes' => ['coworkers' => 1]],
            'prompts' => [['title' => 'Policy', 'content' => 'new'], ['title' => 'Fresh', 'content' => 'x']],
        ], 'boss');

        $this->assertSame(['instructions' => true, 'prompts' => 2, 'projectTemplates' => 0, 'coworkerTemplates' => 0, 'sequences' => 0], $counts);
    }

    public function testReplaceDeletesInstanceEntriesFirst(): void {
        $prompts = $this->createMock(PromptMapper::class);
        $prompts->method('findAllInstance')->willReturn([$this->prompt('Old', 'x')]);
        $prompts->expects($this->once())->method('delete');
        $coworkers = $this->createMock(CoworkerTemplates::class);
        $coworkers->expects($this->once())->method('clear');

        (new LibraryTransfer(
            $this->createStub(InstructionsService::class),
            $prompts,
            $this->createStub(ProjectTemplateMapper::class),
            $coworkers,
            ...$this->sequenceDeps(),
        ))->import(['format' => 'aiquila-library', 'version' => 1], 'admin', true);
    }

    public function testInvalidFilesChangeNothing(): void {
        $prompts = $this->createMock(PromptMapper::class);
        $prompts->expects($this->never())->method('insert');
        $prompts->expects($this->never())->method('delete');
        $transfer = new LibraryTransfer(
            $this->createStub(InstructionsService::class),
            $prompts,
            $this->createStub(ProjectTemplateMapper::class),
            $this->createStub(CoworkerTemplates::class),
            ...$this->sequenceDeps(),
        );

        foreach ([
            ['format' => 'something-else', 'version' => 1],
            ['format' => 'aiquila-library', 'version' => 1, 'prompts' => [['title' => 'ok', 'content' => 'x'], ['title' => '', 'content' => 'x']]],
            ['format' => 'aiquila-library', 'version' => 1, 'prompts' => [['title' => 't', 'content' => str_repeat('x', InstructionsService::MAX_LENGTH + 1)]]],
            ['format' => 'aiquila-library', 'version' => 1, 'prompts' => 'nope'],
            ['format' => 'aiquila-library', 'version' => 1, 'sequences' => [['slug' => 'x', 'title' => 'X', 'definition' => ['steps' => [['tool' => 'nope']]]]]],
            ['format' => 'aiquila-library', 'version' => 1, 'sequences' => [['slug' => 'daily_briefing', 'title' => 'X', 'definition' => ['steps' => [['tool' => 'list_notifications']]]]]],
            ['format' => 'aiquila-library', 'version' => 1, 'sequencePolicy' => ['maxSteps' => 99]],
        ] as $data) {
            try {
                $transfer->import($data, 'admin', true);
                $this->fail('expected InvalidArgumentException');
            } catch (\InvalidArgumentException) {
            }
        }
    }

    public function testSequencesAndThePolicyRoundTrip(): void {
        $org = new Sequence();
        $org->setScope('instance');
        $org->setSlug('standup');
        $org->setTitle('Standup');
        $org->setDefinitionArray(['inputs' => [], 'steps' => [['id' => 'n', 'tool' => 'list_notifications', 'arguments' => [], 'onFailure' => 'stop']]]);
        $org->setPinned(1);
        $mapper = $this->createStub(SequenceMapper::class);
        $mapper->method('findAllInstance')->willReturn([$org]);
        $this->app['sequences_max_steps'] = '5';
        $this->app['sequences_disabled_builtins'] = '["back_at_work"]';
        $export = (new LibraryTransfer(
            $this->createStub(InstructionsService::class),
            $this->createStub(PromptMapper::class),
            $this->createStub(ProjectTemplateMapper::class),
            $this->createStub(CoworkerTemplates::class),
            ...$this->sequenceDeps($mapper),
        ))->export();

        $this->assertSame('standup', $export['sequences'][0]['slug']);
        $this->assertTrue($export['sequences'][0]['pinned']);
        $this->assertSame(5, $export['sequencePolicy']['maxSteps']);
        $this->assertSame(['back_at_work'], $export['sequencePolicy']['disabledBuiltins']);

        // Into an empty instance, as another admin.
        $this->app = [];
        $target = $this->createMock(SequenceMapper::class);
        $target->method('findAllInstance')->willReturn([]);
        $target->expects($this->once())->method('insert')->with($this->callback(
            static fn (Sequence $s): bool => $s->getSlug() === 'standup' && $s->getScope() === 'instance' && $s->getUserId() === '' && $s->getAuthorId() === 'boss' && $s->getPinned() === 1,
        ));
        $counts = (new LibraryTransfer(
            $this->createStub(InstructionsService::class),
            $this->createStub(PromptMapper::class),
            $this->createStub(ProjectTemplateMapper::class),
            $this->createStub(CoworkerTemplates::class),
            ...$this->sequenceDeps($target),
        ))->import(json_decode(json_encode($export), true), 'boss');

        $this->assertSame(1, $counts['sequences']);
        $this->assertSame('5', $this->app['sequences_max_steps']);
        $this->assertSame('["back_at_work"]', $this->app['sequences_disabled_builtins']);
    }
}
