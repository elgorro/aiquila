<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

// The parts of the Circles app the code touches. It ships with Nextcloud but
// has no OCP interface for these.

namespace OCA\Circles {
    class CirclesManager {
        public function startSuperSession(bool $forceSync = false): void {
        }

        public function stopSession(): void {
        }

        /** @throws Exceptions\CircleNotFoundException */
        public function getCircle(string $singleId): Model\Circle {
            return new Model\Circle();
        }
    }
}

namespace OCA\Circles\Exceptions {
    class CircleNotFoundException extends \Exception {
    }
}

namespace OCA\Circles\Model {
    class Circle {
        public function getSingleId(): string {
            return '';
        }

        public function getOwner(): Member {
            return new Member();
        }

        /** @return list<Member> */
        public function getMembers(): array {
            return [];
        }
    }

    class Member {
        public const TYPE_USER = 1;
        public const LEVEL_ADMIN = 8;

        public function getUserId(): string {
            return '';
        }

        public function getUserType(): int {
            return 0;
        }

        public function getLevel(): int {
            return 0;
        }
    }
}

namespace OCA\Circles\Events {
    class CircleDestroyedEvent extends \OCP\EventDispatcher\Event {
        public function getCircle(): \OCA\Circles\Model\Circle {
            return new \OCA\Circles\Model\Circle();
        }
    }
}
