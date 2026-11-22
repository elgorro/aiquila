<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service\UsageExport;

/**
 * Encodes export rows as CSV or JSON, as a stream of string chunks.
 *
 * CSV follows RFC 4180: a header row, CRLF line breaks (§2.1), and a field
 * quoted with doubled inner quotes when it holds a comma, quote, CR or LF
 * (§2.5–2.7). Text fields that a spreadsheet would read as a formula (`=`,
 * `+`, `-`, `@`, tab, CR) get a leading `'`, the OWASP guard against CSV
 * injection; numbers are left alone. An empty field is a null.
 *
 * JSON is one object: the export's parameters, then `rows`, an array of
 * objects with the same keys and types as the CSV columns.
 *
 * Output is buffered into chunks of about CHUNK_BYTES, so a large export is
 * a few thousand writes rather than one per field.
 */
final class UsageExportWriter {
    public const CHUNK_BYTES = 65536;

    // A model id from a provider response is not guaranteed UTF-8; substitute
    // rather than abort a stream whose headers are already sent.
    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;

    /**
     * @param list<string> $columns
     * @param iterable<array<string, int|string|null>> $rows
     * @param array<string, int|string|null> $meta JSON only: written before `rows`
     * @return \Generator<int, string>
     */
    public static function chunks(string $format, array $columns, iterable $rows, array $meta = []): \Generator {
        $lines = $format === UsageExportFilter::FORMAT_JSON
            ? self::jsonParts($columns, $rows, $meta)
            : self::csvLines($columns, $rows);

        $buffer = '';
        foreach ($lines as $line) {
            $buffer .= $line;
            if (strlen($buffer) >= self::CHUNK_BYTES) {
                yield $buffer;
                $buffer = '';
            }
        }
        if ($buffer !== '') {
            yield $buffer;
        }
    }

    public static function contentType(string $format): string {
        // RFC 7111 registers text/csv with the charset and header parameters.
        return $format === UsageExportFilter::FORMAT_JSON
            ? 'application/json; charset=utf-8'
            : 'text/csv; charset=utf-8; header=present';
    }

    /**
     * @param list<string> $columns
     * @param iterable<array<string, int|string|null>> $rows
     * @return \Generator<int, string>
     */
    private static function csvLines(array $columns, iterable $rows): \Generator {
        yield implode(',', array_map(self::csvField(...), $columns)) . "\r\n";
        foreach ($rows as $row) {
            $fields = [];
            foreach ($columns as $column) {
                $fields[] = self::csvField($row[$column] ?? null);
            }
            yield implode(',', $fields) . "\r\n";
        }
    }

    public static function csvField(int|string|null $value): string {
        if ($value === null) {
            return '';
        }
        if (is_int($value)) {
            return (string)$value;
        }
        if ($value !== '' && strpbrk($value[0], "=+-@\t\r") !== false) {
            $value = "'" . $value;
        }
        if (strpbrk($value, ",\"\r\n") !== false) {
            return '"' . str_replace('"', '""', $value) . '"';
        }
        return $value;
    }

    /**
     * @param list<string> $columns
     * @param iterable<array<string, int|string|null>> $rows
     * @param array<string, int|string|null> $meta
     * @return \Generator<int, string>
     */
    private static function jsonParts(array $columns, iterable $rows, array $meta): \Generator {
        $head = '{';
        foreach ($meta as $key => $value) {
            $head .= json_encode((string)$key, self::JSON_FLAGS) . ':' . json_encode($value, self::JSON_FLAGS) . ',';
        }
        yield $head . '"rows":[';

        $first = true;
        foreach ($rows as $row) {
            $object = [];
            foreach ($columns as $column) {
                $object[$column] = $row[$column] ?? null;
            }
            yield ($first ? "\n" : ",\n") . json_encode($object, self::JSON_FLAGS);
            $first = false;
        }
        yield ($first ? '' : "\n") . "]}\n";
    }
}
