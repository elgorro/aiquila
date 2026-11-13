<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Listener;

use OCA\AIquila\Db\ConversationMapper;
use OCA\AIquila\Db\MessageFileMapper;
use OCA\AIquila\Db\MessageMapper;
use OCA\AIquila\Db\ProjectMapper;
use OCA\AIquila\Db\ProjectPathMapper;
use OCA\AIquila\Db\ProjectTemplateMapper;
use OCA\AIquila\Db\PromptMapper;
use OCA\AIquila\Db\ProviderAccessRule;
use OCA\AIquila\Db\ProviderAccessRuleMapper;
use OCA\AIquila\Db\UsageStatMapper;
use OCA\AIquila\Service\ContextChatService;
use OCA\AIquila\Service\CoworkerService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\BeforeUserDeletedEvent;
use OCP\User\Events\UserDeletedEvent;
use Psr\Log\LoggerInterface;

/**
 * Removes what a deleted user owned.
 *
 * Before the account goes, the runs in flight of their personal coworkers
 * are stopped: a pending batch is cancelled with the user's provider key,
 * which is gone afterwards. Once it is gone, their personal coworkers and run
 * history, conversations, personal projects, own prompts and project
 * templates, usage statistics and provider access rules are deleted.
 *
 * Team coworkers and team projects stay with the team: a team coworker moves
 * to someone who can act for the team on its next run. Instance prompts and
 * templates the user published stay too. Files uploaded to Anthropic expire
 * through CleanupAnthropicFilesJob.
 *
 * @template-implements IEventListener<Event>
 */
class UserDeletedListener implements IEventListener {
    public function __construct(
        private readonly CoworkerService $coworkers,
        private readonly ConversationMapper $conversations,
        private readonly MessageMapper $messages,
        private readonly MessageFileMapper $messageFiles,
        private readonly ProjectMapper $projects,
        private readonly ProjectPathMapper $paths,
        private readonly PromptMapper $prompts,
        private readonly ProjectTemplateMapper $templates,
        private readonly UsageStatMapper $usage,
        private readonly ProviderAccessRuleMapper $accessRules,
        private readonly ContextChatService $contextChat,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(Event $event): void {
        if ($event instanceof BeforeUserDeletedEvent) {
            $this->coworkers->stopForUser($event->getUser()->getUID());
        } elseif ($event instanceof UserDeletedEvent) {
            $this->removeUserData($event->getUser()->getUID());
        }
    }

    /**
     * Each kind of row is removed on its own, so one failure does not leave
     * the rest behind.
     */
    private function removeUserData(string $userId): void {
        $steps = [
            'coworkers' => fn () => $this->coworkers->removeForUser($userId),
            'conversations' => fn () => $this->removeConversations($userId),
            'projects' => fn () => $this->removeProjects($userId),
            'prompts' => function () use ($userId): void {
                foreach ($this->prompts->findAllByUser($userId) as $prompt) {
                    $this->prompts->delete($prompt);
                }
            },
            'project templates' => function () use ($userId): void {
                foreach ($this->templates->findAllByUser($userId) as $template) {
                    $this->templates->delete($template);
                }
            },
            'usage statistics' => fn () => $this->usage->deleteByUser($userId),
            'provider access rules' => fn () => $this->accessRules->deleteByPrincipal(ProviderAccessRule::PRINCIPAL_USER, $userId),
        ];
        foreach ($steps as $what => $step) {
            try {
                $step();
            } catch (\Throwable $e) {
                $this->logger->error('AIquila: could not remove a deleted user\'s ' . $what, [
                    'user' => $userId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function removeConversations(string $userId): void {
        foreach ($this->conversations->findAllByUser($userId) as $conversation) {
            $id = $conversation->getId();
            foreach ($this->messages->findByConversation($id, true) as $message) {
                $this->messageFiles->deleteByMessage($message->getId());
            }
            $this->messages->deleteByConversation($id);
            $this->conversations->delete($conversation);
            $this->contextChat->removeConversation($id);
        }
    }

    /**
     * Personal projects only: a team project belongs to the team, whoever
     * created it. The user's conversations in them are already gone.
     */
    private function removeProjects(string $userId): void {
        foreach ($this->projects->findAllByUser($userId) as $project) {
            if ($project->getTeamId() !== null) {
                continue;
            }
            $this->conversations->clearProject($project->getId());
            $this->paths->deleteByProject($project->getId());
            $this->projects->delete($project);
        }
    }
}
