<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Cowork;

use OCA\AIquila\Batch\BatchOperation;
use OCA\AIquila\Batch\TranslateOperation;

/**
 * Docs task: translate every document under a folder, writing each translation
 * to `<file>.<language>.md` beside the source.
 */
class DocsTranslateFolderTaskType extends AbstractBatchTextTaskType {

    public function getId(): string {
        return 'docs:translate';
    }

    public function getLabel(): string {
        return 'Translate documents (batch)';
    }

    protected function operation(): BatchOperation {
        return new TranslateOperation();
    }
}
