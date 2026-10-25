<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\SetupCheck;

use OCP\App\IAppManager;
use OCP\IL10N;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;
use OCP\TaskProcessing\IManager;

/**
 * Text shows its "Nextcloud Assistant" menu as soon as one of these task types is
 * available — which AIquila makes true — and every entry hands off to the Assistant
 * app's frontend. Without Assistant, AIquila supplies a fallback for that frontend
 * (LoadTextAssistantListener); this check reports which of the two serves the menu.
 */
class TextAssistantAvailable implements ISetupCheck {
    /** Task types that make Text show its Assistant menu (Text's InitialStateProvider). */
    public const TEXT_MENU_TASK_TYPES = [
        'core:text2text',
        'core:text2text:formalization',
        'core:text2text:headline',
        'core:text2text:reformulation',
        'core:text2text:simplification',
        'core:text2text:summary',
        'core:text2text:topics',
        'core:text2text:translate',
    ];

    public function __construct(
        private IAppManager $appManager,
        private IManager $taskManager,
        private IL10N $l10n,
    ) {
    }

    public function getName(): string {
        return $this->l10n->t('AIquila in the Text editor');
    }

    public function getCategory(): string {
        return 'system';
    }

    public function run(): SetupResult {
        if (!$this->appManager->isEnabledForAnyone('text')) {
            return SetupResult::success($this->l10n->t('The Text app is not enabled, so its Assistant menu is not shown.'));
        }

        if ($this->appManager->isEnabledForAnyone('assistant')) {
            return SetupResult::success($this->l10n->t('The Assistant app is enabled, so AI actions in the Text editor work.'));
        }

        $available = array_keys($this->taskManager->getAvailableTaskTypes());
        if (array_intersect(self::TEXT_MENU_TASK_TYPES, $available) === []) {
            return SetupResult::success($this->l10n->t('No task type that Text offers in its Assistant menu is available.'));
        }

        return SetupResult::info(
            $this->l10n->t('The Assistant app is not enabled, so AIquila provides the AI actions in the Text editor itself. Install the Assistant app for its full task history and notifications.')
        );
    }
}
