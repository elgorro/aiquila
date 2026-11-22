<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Cowork;

use OCA\AIquila\Batch\AskOperation;
use OCA\AIquila\Batch\BatchOperation;

/**
 * Docs task: ask the coworker's question of every document under a folder,
 * writing each answer to `<name>.<outputSuffix>.md` beside the source.
 */
class DocsAskFolderTaskType extends AbstractBatchTextTaskType {

    public function getId(): string {
        return 'docs:ask';
    }

    public function getLabel(): string {
        return 'Ask about each document (batch)';
    }

    protected function operation(): BatchOperation {
        return new AskOperation();
    }
}
