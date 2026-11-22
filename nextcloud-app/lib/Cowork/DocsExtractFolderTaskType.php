<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Cowork;

use OCA\AIquila\Batch\BatchOperation;
use OCA\AIquila\Batch\ExtractOperation;

/**
 * Docs task: pull the coworker's fields out of every document under a folder
 * into one table, `<tableName>.csv`, with a row per document.
 */
class DocsExtractFolderTaskType extends AbstractBatchTextTaskType {

    public function getId(): string {
        return 'docs:extract';
    }

    public function getLabel(): string {
        return 'Extract fields into a table (batch)';
    }

    protected function operation(): BatchOperation {
        return new ExtractOperation();
    }
}
