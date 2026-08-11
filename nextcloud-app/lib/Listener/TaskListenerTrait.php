<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Listener;

use OCP\TaskProcessing\Task;

/**
 * Shared helpers for the TaskProcessing event listeners. Using classes must
 * inject OCP\TaskProcessing\IManager as $taskProcessingManager.
 */
trait TaskListenerTrait {

    private static array $typeLabels = [
        'core:text2text' => 'Text generation',
        'core:text2text:summary' => 'Summarization',
        'core:text2text:headline' => 'Headline generation',
        'core:text2text:topics' => 'Topic extraction',
        'core:text2text:translate' => 'Translation',
        'core:text2text:proofread' => 'Proofreading',
        'core:text2text:reformulate' => 'Reformulation',
        'core:text2text:formalize' => 'Formalization',
        'core:text2text:simplify' => 'Simplification',
        'core:text2text:change-tone' => 'Tone adjustment',
        'core:image2text' => 'Image analysis',
        'core:analyze-images' => 'Multi-image analysis',
    ];

    /**
     * OCP\TaskProcessing\Task exposes no provider id, so the provider is resolved
     * through the manager. Note getPreferredProvider() answers "who would run
     * this task type now", not "who ran this task": the admin preference is
     * cached for a few minutes, so a change between scheduling and completion
     * can misattribute. Best approximation available; for aiquila's synchronous
     * providers the event fires in-process right after the preferred provider
     * ran, so it is accurate in practice.
     */
    private function isAiquilaTask(Task $task): bool {
        try {
            $provider = $this->taskProcessingManager->getPreferredProvider($task->getTaskTypeId());
        } catch (\OCP\TaskProcessing\Exception\Exception) {
            // No provider for this task type -> not ours. Unexpected throwables
            // propagate to handle(), which logs rather than hides them.
            return false;
        }

        return str_starts_with($provider->getId(), 'aiquila:');
    }

    public static function getTaskTypeLabel(string $taskTypeId): string {
        if (isset(self::$typeLabels[$taskTypeId])) {
            return self::$typeLabels[$taskTypeId];
        }

        $parts = explode(':', $taskTypeId);
        return ucfirst(end($parts));
    }
}
