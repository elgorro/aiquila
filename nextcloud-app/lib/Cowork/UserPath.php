<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Cowork;

use OCP\Files\Node;

/**
 * A node's path as its owner sees it in Files (`/Photos/a.jpg`), not the
 * internal `/alice/files/Photos/a.jpg`. Used for run log items, which are
 * shown back to that user.
 */
final class UserPath {
    public static function of(Node $node): string {
        $path = $node->getPath();
        return preg_match('#^/[^/]+/files(/.*)$#', $path, $m) === 1 ? $m[1] : $path;
    }
}
