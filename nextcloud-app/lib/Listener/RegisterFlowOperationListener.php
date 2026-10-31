<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Listener;

use OCA\AIquila\AppInfo\Application;
use OCA\AIquila\Flow\AIquilaOperation;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;
use OCP\WorkflowEngine\Events\RegisterOperationsEvent;
use Psr\Container\ContainerInterface;

/**
 * Offers "Process with AIquila" in Settings → Flow, and loads the editor for
 * its settings. The event only fires where the Flow (workflowengine) app runs.
 *
 * @template-implements IEventListener<Event>
 */
class RegisterFlowOperationListener implements IEventListener {
    public function __construct(
        private ContainerInterface $container,
    ) {
    }

    public function handle(Event $event): void {
        if (!$event instanceof RegisterOperationsEvent) {
            return;
        }
        $operation = $this->container->get(AIquilaOperation::class);
        assert($operation instanceof AIquilaOperation);
        $event->registerOperation($operation);
        Util::addScript(Application::APP_ID, 'flow-operation');
    }
}
