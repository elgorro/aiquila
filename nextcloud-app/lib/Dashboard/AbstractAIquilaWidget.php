<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Dashboard;

use OCP\Dashboard\IIconWidget;
use OCP\Dashboard\IWidget;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserSession;

/**
 * Shared base for all AIquila dashboard widgets.
 *
 * Implements the common {@see IWidget} + {@see IIconWidget} surface (icon, app
 * deep-link, current user) so concrete widgets only supply id, title, order and
 * their item payload.
 */
abstract class AbstractAIquilaWidget implements IWidget, IIconWidget {
    protected const APP_ID = 'aiquila';

    public function __construct(
        protected readonly IL10N $l10n,
        protected readonly IURLGenerator $urlGenerator,
        protected readonly IUserSession $userSession,
    ) {
    }

    /**
     * Hash route inside the SPA this widget links to (e.g. '/chat'). Override
     * per widget. Defaults to the app home.
     */
    protected function hashRoute(): string {
        return '/';
    }

    /**
     * Absolute URL into the AIquila SPA, optionally targeting a hash route.
     */
    protected function appUrl(?string $hashRoute = null): string {
        $base = $this->urlGenerator->linkToRouteAbsolute(self::APP_ID . '.page.index');
        return $base . '#' . ($hashRoute ?? $this->hashRoute());
    }

    public function getUrl(): ?string {
        return $this->appUrl();
    }

    public function getIconClass(): string {
        return 'icon-aiquila';
    }

    public function getIconUrl(): string {
        return $this->urlGenerator->getAbsoluteURL(
            $this->urlGenerator->imagePath(self::APP_ID, 'app-dark.svg')
        );
    }

    /**
     * API widgets render server-side and need no client scripts.
     */
    public function load(): void {
    }

    /**
     * Current user id, or null when no user is in session.
     */
    protected function currentUserId(): ?string {
        $user = $this->userSession->getUser();
        return $user?->getUID();
    }
}
