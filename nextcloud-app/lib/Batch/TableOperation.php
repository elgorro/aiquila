<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Batch;

/**
 * An operation whose answers are rows of one table rather than a file per
 * source: {@see BatchDocumentRunner} collects a row per document and upserts
 * them, keyed by file id, into `<tableName>.csv` per output folder.
 *
 * Like {@see PromptScopedOperation}, a source counts as done only while it
 * carries the current done-tag (the schema changed otherwise) and its row is
 * at least as new as the source.
 */
interface TableOperation extends BatchOperation, PromptScopedOperation {
    /**
     * The table's file name, e.g. `invoices.csv`.
     *
     * @param array<string, mixed> $options
     */
    public function tableFileName(array $options): string;

    /**
     * The extracted columns, in order.
     *
     * @param array<string, mixed> $options
     * @return list<string>
     */
    public function columns(array $options): array;

    /**
     * The JSON Schema a provider with structured outputs constrains the
     * answer to.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function jsonSchema(array $options): array;

    /**
     * One row from the model's answer, by column.
     *
     * @param array<string, mixed> $options
     * @return array<string, string|int|float|bool|null>
     * @throws \InvalidArgumentException when the answer is not a valid row
     */
    public function parseRow(string $response, array $options): array;
}
