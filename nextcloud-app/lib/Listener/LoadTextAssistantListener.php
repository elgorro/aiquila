<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Listener;

use OCA\AIquila\AppInfo\Application;
use OCA\AIquila\Template\ViteAssets;
use OCP\App\IAppManager;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;

/**
 * Loads the Text editor's Assistant fallback (src/textassistant.js) on pages
 * where Text can open, for users who do not have the Assistant app.
 *
 * Text shows its "Nextcloud Assistant" menu whenever a matching task type is
 * available, but every entry calls the Assistant app's frontend. The fallback
 * supplies that frontend so the menu works with AIquila alone.
 *
 * Registered by name for the Files, Viewer and Text page events, none of
 * which live in OCP.
 *
 * @template-implements IEventListener<Event>
 */
class LoadTextAssistantListener implements IEventListener {
    public const EVENTS = [
        'OCA\Files\Event\LoadAdditionalScriptsEvent',
        'OCA\Viewer\Event\LoadViewer',
        'OCA\Text\Event\LoadEditor',
    ];

    /** Files pages dispatch more than one of these events; emit the script once. */
    private bool $loaded = false;

    public function __construct(
        private IAppManager $appManager,
        private IUserSession $userSession,
    ) {
    }

    public function handle(Event $event): void {
        if ($this->loaded) {
            return;
        }
        $user = $this->userSession->getUser();
        // TaskProcessing needs a user, so public share pages are left alone.
        if ($user === null
            || !$this->appManager->isEnabledForUser('text', $user)
            || $this->appManager->isEnabledForUser('assistant', $user)) {
            return;
        }
        $this->loaded = true;
        ViteAssets::load(Application::APP_ID . '-text');
    }
}
