<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Listener;

use OCA\AIquila\Talk\TalkBotService;
use OCA\Talk\Events\BotInvokeEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Hands Talk's bot events to the AIquila bot. Registered by class name, since
 * the event only exists where Talk is installed.
 *
 * @template-implements IEventListener<Event>
 */
class TalkBotInvokeListener implements IEventListener {
    public const EVENT = 'OCA\Talk\Events\BotInvokeEvent';

    public function __construct(
        private TalkBotService $bot,
    ) {
    }

    public function handle(Event $event): void {
        if ($event instanceof BotInvokeEvent) {
            $this->bot->handleInvoke($event);
        }
    }
}
