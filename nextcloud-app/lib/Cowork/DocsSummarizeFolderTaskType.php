<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Cowork;

use OCA\AIquila\Batch\BatchOperation;
use OCA\AIquila\Batch\SummarizeOperation;

/**
 * Docs task: summarize every document under a folder, writing each summary to
 * `<file>.summary.md` beside the source.
 */
class DocsSummarizeFolderTaskType extends AbstractBatchTextTaskType {

    public function getId(): string {
        return 'docs:summarize';
    }

    public function getLabel(): string {
        return 'Summarize documents (batch)';
    }

    protected function operation(): BatchOperation {
        return new SummarizeOperation();
    }
}
