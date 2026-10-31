<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Flow;

use OCA\AIquila\AppInfo\Application;
use OCA\AIquila\BackgroundJob\FlowOperationJob;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\GenericEvent;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\SystemTag\MapperEvent;
use OCP\WorkflowEngine\IRuleMatcher;
use OCP\WorkflowEngine\ISpecificOperation;
use Psr\Log\LoggerInterface;

/**
 * "Process with AIquila" in Settings → Flow.
 *
 * When a rule matches a file event (created, updated, tag assigned, …) the file
 * is queued for FlowOperationJob, which runs the configured action and writes
 * the result next to the file as `<name>.aiquila.md`. Nothing runs inside the
 * file event itself: a model call takes seconds, and the upload that triggered
 * the rule must not wait for it.
 *
 * Result files are never processed themselves, so a rule on "file created"
 * cannot loop on its own output.
 */
class AIquilaOperation implements ISpecificOperation {
    /** Suffix of the files results are written to. */
    public const RESULT_SUFFIX = '.aiquila.md';

    /** The File entity's class; the workflowengine app owns it, so it is named here. */
    private const FILE_ENTITY = 'OCA\WorkflowEngine\Entity\File';

    public function __construct(
        private IL10N $l,
        private IURLGenerator $urlGenerator,
        private IJobList $jobList,
        private IRootFolder $rootFolder,
        private LoggerInterface $logger,
    ) {
    }

    public function getDisplayName(): string {
        return $this->l->t('Process with AIquila');
    }

    public function getDescription(): string {
        return $this->l->t('Summarize, describe or ask about a file with AI and save the answer next to it.');
    }

    public function getIcon(): string {
        return $this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg');
    }

    public function isAvailableForScope(int $scope): bool {
        return true;
    }

    public function validateOperation(string $name, array $checks, string $operation): void {
        FlowConfig::parse($operation);
    }

    public function getEntityId(): string {
        return self::FILE_ENTITY;
    }

    public function onEvent(string $eventName, Event $event, IRuleMatcher $ruleMatcher): void {
        if (str_ends_with($eventName, '::postDelete')) {
            return;
        }
        $node = $this->nodeFromEvent($eventName, $event);
        if (!$node instanceof File || str_ends_with($node->getName(), self::RESULT_SUFFIX)) {
            return;
        }
        // '', <user>, 'files', <path>: only the user's own files, not trash or versions.
        $parts = explode('/', $node->getPath(), 4);
        if (($parts[2] ?? '') !== 'files') {
            return;
        }

        $owner = $node->getOwner()?->getUID();
        if ($owner === null) {
            return;
        }

        // One job per distinct configuration; several matching rules with the
        // same settings run once.
        $configs = [];
        foreach ($ruleMatcher->getFlows(false) as $flow) {
            try {
                $config = FlowConfig::parse((string)($flow['operation'] ?? ''));
            } catch (\UnexpectedValueException $e) {
                $this->logger->info('AIquila Flow: skipping a rule with invalid settings', ['error' => $e->getMessage()]);
                continue;
            }
            $configs[json_encode($config->toArray())] = $config;
        }

        foreach ($configs as $config) {
            $this->jobList->add(FlowOperationJob::class, [
                'fileId' => $node->getId(),
                'user' => $owner,
            ] + $config->toArray());
        }
    }

    private function nodeFromEvent(string $eventName, Event $event): ?Node {
        try {
            if ($event instanceof MapperEvent) {
                if ($event->getObjectType() !== 'files') {
                    return null;
                }
                return $this->rootFolder->getFirstNodeById((int)$event->getObjectId());
            }
            if (!$event instanceof GenericEvent) {
                return null;
            }
            $subject = $event->getSubject();
            // Rename and copy carry [old, new].
            if (is_array($subject)) {
                $subject = $subject[1] ?? null;
            }
            return $subject instanceof Node ? $subject : null;
        } catch (\Throwable $e) {
            $this->logger->debug('AIquila Flow: could not resolve the event\'s file', ['event' => $eventName, 'error' => $e->getMessage()]);
            return null;
        }
    }
}
