<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Tests\Unit\Listener;

use OCA\AIquila\Db\Conversation;
use OCA\AIquila\Db\ConversationMapper;
use OCA\AIquila\Db\Message;
use OCA\AIquila\Db\MessageFileMapper;
use OCA\AIquila\Db\MessageMapper;
use OCA\AIquila\Db\Project;
use OCA\AIquila\Db\ProjectMapper;
use OCA\AIquila\Db\ProjectPathMapper;
use OCA\AIquila\Db\ProjectTemplate;
use OCA\AIquila\Db\ProjectTemplateMapper;
use OCA\AIquila\Db\Prompt;
use OCA\AIquila\Db\PromptMapper;
use OCA\AIquila\Db\ProviderAccessRuleMapper;
use OCA\AIquila\Db\UsageStatMapper;
use OCA\AIquila\Listener\UserDeletedListener;
use OCA\AIquila\Service\ContextChatService;
use OCA\AIquila\Service\CoworkerService;
use OCP\EventDispatcher\Event;
use OCP\IUser;
use OCP\User\Events\BeforeUserDeletedEvent;
use OCP\User\Events\UserDeletedEvent;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[AllowMockObjectsWithoutExpectations]
class UserDeletedListenerTest extends TestCase {
    private CoworkerService&MockObject $coworkers;
    private ConversationMapper&MockObject $conversations;
    private MessageMapper&MockObject $messages;
    private MessageFileMapper&MockObject $messageFiles;
    private ProjectMapper&MockObject $projects;
    private ProjectPathMapper&MockObject $paths;
    private PromptMapper&MockObject $prompts;
    private ProjectTemplateMapper&MockObject $templates;
    private UsageStatMapper&MockObject $usage;
    private ProviderAccessRuleMapper&MockObject $accessRules;
    private ContextChatService&MockObject $contextChat;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void {
        $this->coworkers = $this->createMock(CoworkerService::class);
        $this->conversations = $this->createMock(ConversationMapper::class);
        $this->messages = $this->createMock(MessageMapper::class);
        $this->messageFiles = $this->createMock(MessageFileMapper::class);
        $this->projects = $this->createMock(ProjectMapper::class);
        $this->paths = $this->createMock(ProjectPathMapper::class);
        $this->prompts = $this->createMock(PromptMapper::class);
        $this->templates = $this->createMock(ProjectTemplateMapper::class);
        $this->usage = $this->createMock(UsageStatMapper::class);
        $this->accessRules = $this->createMock(ProviderAccessRuleMapper::class);
        $this->contextChat = $this->createMock(ContextChatService::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function listener(): UserDeletedListener {
        return new UserDeletedListener(
            $this->coworkers,
            $this->conversations,
            $this->messages,
            $this->messageFiles,
            $this->projects,
            $this->paths,
            $this->prompts,
            $this->templates,
            $this->usage,
            $this->accessRules,
            $this->contextChat,
            $this->logger,
        );
    }

    private function user(): IUser {
        $user = $this->createStub(IUser::class);
        $user->method('getUID')->willReturn('alice');
        return $user;
    }

    /** @template T of object @param T $entity @return T */
    private function withId(object $entity, int $id): object {
        (new \ReflectionProperty($entity, 'id'))->setValue($entity, $id);
        return $entity;
    }

    public function testBeforeDeletionItOnlyStopsRuns(): void {
        $this->coworkers->expects($this->once())->method('stopForUser')->with('alice');
        $this->coworkers->expects($this->never())->method('removeForUser');
        $this->conversations->expects($this->never())->method('findAllByUser');

        $this->listener()->handle(new BeforeUserDeletedEvent($this->user()));
    }

    public function testAfterDeletionItRemovesWhatTheUserOwned(): void {
        $conversation = $this->withId(new Conversation(), 3);
        $personal = $this->withId(new Project(), 5);
        $team = $this->withId(new Project(), 6);
        $team->setTeamId('team1');
        $prompt = new Prompt();
        $template = new ProjectTemplate();

        $this->coworkers->expects($this->once())->method('removeForUser')->with('alice');
        $this->conversations->expects($this->once())->method('findAllByUser')->with('alice')->willReturn([$conversation]);
        $this->messages->expects($this->once())->method('findByConversation')->with(3, true)
            ->willReturn([$this->withId(new Message(), 11), $this->withId(new Message(), 12)]);
        $this->messageFiles->expects($this->exactly(2))->method('deleteByMessage');
        $this->messages->expects($this->once())->method('deleteByConversation')->with(3);
        $this->conversations->expects($this->once())->method('delete')->with($conversation);
        $this->contextChat->expects($this->once())->method('removeConversation')->with(3);

        $this->projects->expects($this->once())->method('findAllByUser')->with('alice')->willReturn([$personal, $team]);
        $this->paths->expects($this->once())->method('deleteByProject')->with(5);
        $this->projects->expects($this->once())->method('delete')->with($personal);

        $this->prompts->expects($this->once())->method('findAllByUser')->with('alice')->willReturn([$prompt]);
        $this->prompts->expects($this->once())->method('delete')->with($prompt);
        $this->templates->expects($this->once())->method('findAllByUser')->with('alice')->willReturn([$template]);
        $this->templates->expects($this->once())->method('delete')->with($template);
        $this->usage->expects($this->once())->method('deleteByUser')->with('alice');
        $this->accessRules->expects($this->once())->method('deleteByPrincipal')->with('user', 'alice');

        $this->listener()->handle(new UserDeletedEvent($this->user()));
    }

    public function testOneFailureDoesNotLeaveTheRestBehind(): void {
        $this->coworkers->method('removeForUser')->willThrowException(new \RuntimeException('db'));
        $this->conversations->method('findAllByUser')->willThrowException(new \RuntimeException('db'));
        $this->logger->expects($this->exactly(2))->method('error');
        $this->usage->expects($this->once())->method('deleteByUser')->with('alice');
        $this->accessRules->expects($this->once())->method('deleteByPrincipal');

        $this->listener()->handle(new UserDeletedEvent($this->user()));
    }

    public function testItIgnoresOtherEvents(): void {
        $this->coworkers->expects($this->never())->method('stopForUser');
        $this->coworkers->expects($this->never())->method('removeForUser');

        $this->listener()->handle(new Event());
    }
}
