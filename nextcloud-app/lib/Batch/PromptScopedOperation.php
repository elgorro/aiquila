<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Batch;

/**
 * An operation whose done-tag names its options, so a source is done only
 * while it carries the current tag. Without it a fresh output alone counts,
 * which is right for a fixed question but not for one the user can change.
 */
interface PromptScopedOperation {
}
