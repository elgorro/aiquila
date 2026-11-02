<?php

declare(strict_types=1);

namespace OCA\AIquila\Tests\Unit\Service;

use OCA\AIquila\Controller\CoworkerController;
use OCA\AIquila\Cowork\CoworkerTaskRegistry;
use OCA\AIquila\Db\Coworker;
use OCA\AIquila\Service\AppSettings;
use OCA\AIquila\Service\CoworkerService;
use OCA\AIquila\Service\CoworkerTemplates;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\IRequest;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CoworkerTemplatesTest extends TestCase {
    private string $stored = '[]';

    private function templates(): CoworkerTemplates {
        $config = $this->createStub(AppSettings::class);
        $config->method('getAppValue')->willReturnCallback(fn (): string => $this->stored);
        $config->method('setAppValue')->willReturnCallback(function (string $app, string $key, string $value): void {
            $this->stored = $value;
        });
        $random = $this->createStub(ISecureRandom::class);
        $random->method('generate')->willReturn('abc123');
        return new CoworkerTemplates($config, $random);
    }

    private function coworker(): Coworker {
        $cw = new Coworker();
        $cw->setTitle('Nightly tags');
        $cw->setDescription('Tag photos');
        $cw->setTaskType('vision:classify');
        $cw->setCronSchedule('0 3 * * *');
        $cw->setInputType('folder');
        $cw->setInputPath('/Photos');
        $cw->setOutputType('system_tags');
        $cw->setProvider('anthropic');
        $cw->setOptions('{"maxTags":5}');
        return $cw;
    }

    public function testPublishCopiesTheCoworkerConfiguration(): void {
        $store = $this->templates();
        $template = $store->publish($this->coworker(), 'Photo tagging');

        $this->assertSame('org-abc123', $template['id']);
        $this->assertSame('instance', $template['scope']);
        $this->assertSame('Photo tagging', $template['title']);
        $this->assertSame('Tag photos', $template['description']);
        $this->assertSame('vision:classify', $template['task_type']);
        $this->assertSame('/Photos', $template['input_path']);
        $this->assertSame(['maxTags' => 5], $template['options']);
        // The scope is derived, not stored.
        $this->assertStringNotContainsString('scope', $this->stored);
        $this->assertSame([$template], $store->list());
    }

    public function testDeleteAndMalformedStorage(): void {
        $store = $this->templates();
        $store->publish($this->coworker());

        $this->assertFalse($store->delete('org-nope'));
        $this->assertTrue($store->delete('org-abc123'));
        $this->assertSame([], $store->list());

        $this->stored = '{"not":"a list of templates"}';
        $this->assertSame([], $store->list());
    }

    public function testControllerOffersBuiltinThenInstanceTemplatesAndCreatesFromEither(): void {
        $service = $this->createMock(CoworkerService::class);
        $service->method('getTemplates')->willReturn([['id' => 'classify-images-claude', 'title' => 'Built in']]);
        $service->expects($this->once())->method('create')
            ->with('alice', $this->callback(static fn (array $d): bool => !isset($d['id']) && !isset($d['scope']) && $d['input_path'] === '/Photos'))
            ->willReturn(new Coworker());

        $store = $this->templates();
        $store->publish($this->coworker());

        $ctrl = new CoworkerController(
            'aiquila',
            $this->createStub(IRequest::class),
            $service,
            $this->createStub(CoworkerTaskRegistry::class),
            $this->createStub(AppSettings::class),
            'alice',
            $this->createStub(LoggerInterface::class),
            $store,
        );

        $templates = $ctrl->templates()->getData()['templates'];
        $this->assertSame(['builtin', 'instance'], array_column($templates, 'scope'));

        $this->assertSame(200, $ctrl->createFromTemplate('org-abc123')->getStatus());
    }

    public function testAdminTemplateRoutesAreAdminOnly(): void {
        $class = new \ReflectionClass(CoworkerController::class);
        foreach (['adminTemplates', 'publishTemplate', 'deleteTemplate'] as $method) {
            $this->assertEmpty($class->getMethod($method)->getAttributes(NoAdminRequired::class), $method);
        }
    }
}
