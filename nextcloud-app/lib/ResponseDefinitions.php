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
 *
 * @psalm-type AIquilaSequenceInput = array{
 *     name: string,
 *     type: 'string'|'integer'|'number'|'boolean'|'array',
 *     required: bool,
 *     description: string,
 *     default?: mixed,
 * }
 *
 * @psalm-type AIquilaSequenceStep = array{
 *     id: string,
 *     tool: string,
 *     arguments: array<string, mixed>|\stdClass,
 *     onFailure: 'stop'|'continue',
 * }
 *
 * @psalm-type AIquilaSequenceDefinition = array{
 *     inputs: list<AIquilaSequenceInput>,
 *     steps: list<AIquilaSequenceStep>,
 * }
 *
 * @psalm-type AIquilaSequence = array{
 *     id: int,
 *     scope: 'user'|'instance',
 *     slug: string,
 *     title: string,
 *     description: ?string,
 *     definition: AIquilaSequenceDefinition,
 *     pinned: bool,
 *     enabled: bool,
 *     createdAt: int,
 *     updatedAt: int,
 * }
 *
 * @psalm-type AIquilaSequenceBuiltin = array{
 *     slug: string,
 *     title: string,
 *     description: string,
 *     adminEnabled: bool,
 *     enabled: bool,
 * }
 *
 * @psalm-type AIquilaSequencePolicy = array{
 *     allowPersonal: bool,
 *     maxPerUser: int,
 *     maxSteps: int,
 *     maxPinned: int,
 * }
 *
 * @psalm-type AIquilaEffectiveSequence = array{
 *     scope: 'user'|'instance',
 *     slug: string,
 *     title: string,
 *     description: ?string,
 *     definition: AIquilaSequenceDefinition,
 *     pinned: bool,
 * }
 *
 * @psalm-type AIquilaBatchRun = array{
 *     id: int,
 *     operation: string,
 *     status: 'queued'|'running'|'pending'|'success'|'error'|'cancelled',
 *     itemsTotal: int,
 *     itemsProcessed: int,
 *     itemsFailed: int,
 *     itemsSkipped: int,
 *     summary: ?string,
 *     error: ?string,
 *     startedAt: int,
 *     finishedAt: ?int,
 *     pendingBatchId: ?string,
 *     outputs: list<string>,
 *     pollIntervalSeconds: ?int,
 * }
 *
 * @psalm-type AIquilaBatchRunLogEntry = array{
 *     ts: int,
 *     level: string,
 *     message: string,
 *     item: ?string,
 * }
 *
 * @psalm-type AIquilaBatchRunDetail = AIquilaBatchRun&array{
 *     log: list<AIquilaBatchRunLogEntry>,
 * }
 *
 * @psalm-type AIquilaBatchOperation = array{
 *     id: string,
 *     label: string,
 *     options: list<string>,
 * }
 */
class ResponseDefinitions {
}
