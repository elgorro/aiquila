<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Stubs for the Talk (spreed) events the AIquila bot uses.
 *
 * Talk is an optional app and not a Composer dependency, so its classes only
 * exist at runtime where Talk is installed. These mirror the signatures on
 * Talk 23 (stable33) and later, which are all AIquila supports. Keep them to
 * what lib/ actually calls.
 */

namespace OCA\Talk\Events;

use OCP\EventDispatcher\Event;

if (!class_exists(BotInvokeEvent::class)) {
    class BotInvokeEvent extends Event {
        /** @var list<string> */
        protected array $reactions = [];
        /** @var list<array<string, mixed>> */
        protected array $answers = [];

        public function __construct(
            protected string $botUrl,
            protected array $message,
        ) {
            parent::__construct();
        }

        public function getBotUrl(): string {
            return $this->botUrl;
        }

        public function getMessage(): array {
            return $this->message;
        }

        public function addReaction(string $emoji): void {
            $this->reactions[] = $emoji;
        }

        /** @return list<string> */
        public function getReactions(): array {
            return $this->reactions;
        }

        public function addAnswer(string $message, bool|int $reply = false, bool $silent = false, string $referenceId = '', bool $thread = false, ?string $threadTitle = null): void {
            $this->answers[] = ['message' => $message, 'reply' => $reply];
        }
    }
}

if (!class_exists(BotInstallEvent::class)) {
    class BotInstallEvent extends Event {
        public function __construct(
            protected string $name,
            protected string $secret,
            protected string $url,
            protected string $description = '',
            protected ?int $features = null,
        ) {
            parent::__construct();
        }

        public function getName(): string {
            return $this->name;
        }

        public function getUrl(): string {
            return $this->url;
        }

        public function getFeatures(): int {
            return $this->features ?? 0;
        }
    }
}

if (!class_exists(BotUninstallEvent::class)) {
    class BotUninstallEvent extends Event {
        public function __construct(
            protected string $secret,
            protected string $url,
        ) {
            parent::__construct();
        }

        public function getUrl(): string {
            return $this->url;
        }
    }
}
