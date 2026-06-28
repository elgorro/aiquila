<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\ContextChat;

use OCA\AIquila\AppInfo\Application;
use OCA\AIquila\Service\ContextChatService;
use OCP\ContextChat\IContentProvider;
use OCP\IURLGenerator;

/**
 * Context Chat content provider exposing AIquila conversations.
 *
 * This class is only ever autoloaded on a server where Context Chat is
 * installed (it is referenced solely from the registration listener, which
 * fires the ContentProviderRegisterEvent that Context Chat dispatches), so the
 * `OCP\ContextChat\IContentProvider` interface is guaranteed to exist here.
 */
class ConversationContentProvider implements IContentProvider {
    public function __construct(
        private readonly ContextChatService $service,
        private readonly IURLGenerator $urlGenerator,
    ) {
    }

    public function getId(): string {
        return ContextChatService::PROVIDER_ID;
    }

    public function getAppId(): string {
        return Application::APP_ID;
    }

    public function getItemUrl(string $id): string {
        // Mirror the deep link built by the unified search provider.
        return $this->urlGenerator->getAbsoluteURL(
            $this->urlGenerator->linkToRoute('aiquila.page.index') . '#/conversations/' . $id,
        );
    }

    public function triggerInitialImport(): void {
        $this->service->reindexAll();
    }
}
