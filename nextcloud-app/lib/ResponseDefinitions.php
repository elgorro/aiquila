<?php

declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\AIquila;

/**
 * Shared response shapes. openapi-extractor turns each alias into a schema under
 * `components/schemas` (prefix stripped), and controllers import them with
 * `@psalm-import-type`, so Psalm checks the code and the spec against one definition.
 *
 * @psalm-type AIquilaFileNode = array{
 *     name: string,
 *     path: string,
 *     size: int,
 *     mimeType: string,
 *     mtime: int,
 *     etag: string,
 *     permissions: int,
 *     type: 'file'|'folder',
 *     isEncrypted: bool,
 *     id: int,
 * }
 */
class ResponseDefinitions {
}
