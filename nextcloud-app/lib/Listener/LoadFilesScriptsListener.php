<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Listener;

use OCA\AIquila\AppInfo\Application;
use OCA\AIquila\Template\ViteAssets;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Loads the "Ask AIquila" file action into the Files app.
 *
 * Registered for the Files app's LoadAdditionalScriptsEvent, which it
 * dispatches while rendering its page. That class lives in the Files app
 * rather than OCP, so it is registered by name and not type-checked here.
 *
 * @template-implements IEventListener<Event>
 */
class LoadFilesScriptsListener implements IEventListener {
    public const EVENT = 'OCA\Files\Event\LoadAdditionalScriptsEvent';

    public function handle(Event $event): void {
        ViteAssets::load(Application::APP_ID . '-files');
    }
}
