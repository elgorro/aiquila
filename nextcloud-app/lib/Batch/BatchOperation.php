<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Batch;

/**
 * One question asked about each document of a batch run: what to send, where
 * the answer goes, and how a finished source is marked.
 *
 * Operations carry no state and know nothing about who runs them. A coworker
 * task type ({@see \OCA\AIquila\Cowork\AbstractBatchTextTaskType}) and a
 * manual run started from Files both hand one to {@see BatchDocumentRunner}.
 */
interface BatchOperation {
    /** Stable id, e.g. "summarize". */
    public function getId(): string;

    /** Human-readable label, e.g. "Summarize". */
    public function getLabel(): string;

    /**
     * Validate the options only this operation reads. The options every
     * operation shares are checked by {@see BatchDocumentRunner::validateOptions()}.
     *
     * @param array<string, mixed> $options
     * @throws \InvalidArgumentException
     */
    public function validateOptions(array $options): void;

    /**
     * The question asked about one document.
     *
     * @param array<string, mixed> $options
     */
    public function buildPrompt(string $content, array $options): string;

    /**
     * Suffix appended to the source's base name, e.g. ".summary.md".
     *
     * @param array<string, mixed> $options
     */
    public function outputSuffix(array $options): string;

    /** System tag applied to a source once its output exists. */
    public function doneTag(): string;

    /** Verb used in the run summary and log, e.g. "Summarized". */
    public function summaryVerb(): string;
}
