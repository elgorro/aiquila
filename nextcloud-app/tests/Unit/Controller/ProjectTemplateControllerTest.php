<?php

declare(strict_types=1);

namespace OCA\AIquila\Tests\Unit\Controller;

use OCA\AIquila\Controller\ProjectController;
use OCA\AIquila\Controller\ProjectTemplateController;
use OCA\AIquila\Db\ConversationMapper;
use OCA\AIquila\Db\Project;
use OCA\AIquila\Db\ProjectMapper;
use OCA\AIquila\Db\ProjectPath;
use OCA\AIquila\Db\ProjectPathMapper;
use OCA\AIquila\Db\ProjectTemplate;
use OCA\AIquila\Db\ProjectTemplateMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ProjectTemplateControllerTest extends TestCase {
    private function controller(ProjectTemplateMapper $templates, ?ProjectMapper $projects = null, ?ProjectPathMapper $paths = null): ProjectTemplateController {
        return new ProjectTemplateController(
            'aiquila',
            $this->createStub(IRequest::class),
            $templates,
            $projects ?? $this->createStub(ProjectMapper::class),
            $paths ?? $this->createStub(ProjectPathMapper::class),
            'alice',
            $this->createStub(LoggerInterface::class),
        );
    }

    private function template(string $scope): ProjectTemplate {
        $t = new ProjectTemplate();
        $t->setId(4);
        $t->setScope($scope);
        $t->setUserId('admin');
        $t->setTitle('Tax return');
        $t->setDescription('Yearly');
        $t->setSystemPrompt('Cite the law.');
        $t->setPathList([['path' => '/Taxes', 'pathType' => 'directory'], ['path' => '', 'pathType' => 'file']]);
        return $t;
    }

    public function testOnlyUserRoutesSkipTheAdminCheck(): void {
        $class = new \ReflectionClass(ProjectTemplateController::class);
        foreach (['index', 'create', 'update', 'destroy', 'createProject'] as $method) {
            $this->assertNotEmpty($class->getMethod($method)->getAttributes(NoAdminRequired::class), $method);
        }
        foreach (['adminIndex', 'adminCreate', 'adminUpdate', 'adminDestroy'] as $method) {
            $this->assertEmpty($class->getMethod($method)->getAttributes(NoAdminRequired::class), $method);
        }
    }

    public function testCreateProjectCopiesAnInstanceTemplate(): void {
        $templates = $this->createStub(ProjectTemplateMapper::class);
        $templates->method('findInstanceById')->willReturn($this->template('instance'));

        $projects = $this->createMock(ProjectMapper::class);
        $projects->expects($this->once())->method('insert')->willReturnCallback(static function (Project $p): Project {
            $p->setId(11);
            return $p;
        });
        $paths = $this->createMock(ProjectPathMapper::class);
        $paths->expects($this->once())->method('insert')->willReturnArgument(0);

        $data = $this->controller($templates, $projects, $paths)->createProject(4)->getData();

        $this->assertSame('alice', $data['userId']);
        $this->assertSame('Tax return', $data['title']);
        $this->assertSame('Yearly', $data['description']);
        $this->assertSame('Cite the law.', $data['systemPrompt']);
        $this->assertSame('/Taxes', $data['paths'][0]['path']);
        $this->assertSame('directory', $data['paths'][0]['pathType']);
        $this->assertSame(11, $data['paths'][0]['projectId']);
    }

    public function testCreateProjectFromSomeoneElsesTemplateIs404(): void {
        $templates = $this->createStub(ProjectTemplateMapper::class);
        $templates->method('findInstanceById')->willThrowException(new DoesNotExistException(''));
        $templates->method('findByIdAndUser')->willThrowException(new DoesNotExistException(''));
        $projects = $this->createMock(ProjectMapper::class);
        $projects->expects($this->never())->method('insert');

        $this->assertSame(404, $this->controller($templates, $projects)->createProject(4)->getStatus());
    }

    public function testSaveProjectAsTemplateCopiesIt(): void {
        $project = new Project();
        $project->setId(2);
        $project->setTitle('Garden');
        $project->setSystemPrompt('Plants only.');
        $projects = $this->createStub(ProjectMapper::class);
        $projects->method('findByIdAndUser')->willReturn($project);

        $path = new ProjectPath();
        $path->setPath('/Garden/plan.md');
        $path->setPathType('file');
        $paths = $this->createStub(ProjectPathMapper::class);
        $paths->method('findByProject')->willReturn([$path]);

        $templates = $this->createMock(ProjectTemplateMapper::class);
        $templates->expects($this->once())->method('insert')->willReturnArgument(0);

        $data = $this->controller($templates, $projects, $paths)->create(projectId: 2, description: 'Mine')->getData();

        $this->assertSame('user', $data['scope']);
        $this->assertSame('Garden', $data['title']);
        $this->assertSame('Mine', $data['description']);
        $this->assertSame('Plants only.', $data['systemPrompt']);
        $this->assertSame([['path' => '/Garden/plan.md', 'pathType' => 'file']], $data['paths']);
    }

    public function testAdminCreateIsInstanceScopeAndNeedsATitle(): void {
        $templates = $this->createStub(ProjectTemplateMapper::class);
        $templates->method('insert')->willReturnArgument(0);
        $ctrl = $this->controller($templates);

        $this->assertSame(400, $ctrl->adminCreate()->getStatus());
        $this->assertSame('instance', $ctrl->adminCreate('Onboarding')->getData()['scope']);
    }

    public function testUserCannotEditAnInstanceTemplate(): void {
        $templates = $this->createMock(ProjectTemplateMapper::class);
        $templates->method('findByIdAndUser')->willThrowException(new DoesNotExistException(''));
        $templates->expects($this->never())->method('update');
        $templates->expects($this->never())->method('delete');

        $this->assertSame(404, $this->controller($templates)->update(4, 'x')->getStatus());
        $this->assertSame(404, $this->controller($templates)->destroy(4)->getStatus());
    }

    public function testRenamingAProjectKeepsItsPromptAndDescription(): void {
        $project = new Project();
        $project->setId(2);
        $project->setTitle('Old');
        $project->setDescription('Keep me');
        $project->setSystemPrompt('Keep me too');
        $projects = $this->createStub(ProjectMapper::class);
        $projects->method('findByIdAndUser')->willReturn($project);

        $ctrl = new ProjectController(
            'aiquila',
            $this->createStub(IRequest::class),
            $projects,
            $this->createStub(ProjectPathMapper::class),
            $this->createStub(ConversationMapper::class),
            'alice',
            $this->createStub(LoggerInterface::class),
        );

        $data = $ctrl->update(2, 'New')->getData();
        $this->assertSame('New', $data['title']);
        $this->assertSame('Keep me', $data['description']);
        $this->assertSame('Keep me too', $data['systemPrompt']);

        $data = $ctrl->update(2, null, '', '')->getData();
        $this->assertNull($data['description']);
        $this->assertNull($data['systemPrompt']);

        $this->assertSame(400, $ctrl->update(2, '  ')->getStatus());
    }
}
