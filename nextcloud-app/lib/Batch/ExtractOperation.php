<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Batch;

/**
 * Pull the same fields out of every document into one table: a row per
 * document in `<tableName>.csv`.
 *
 * Fields are a list (name, type, description) rather than raw JSON Schema;
 * the schema is built from it. On Anthropic models with structured outputs
 * the answer is constrained to that schema, and on every provider it is
 * checked here, so a row in the table always has the declared types.
 */
final class ExtractOperation implements BatchOperation, TableOperation {
    public const TYPES = ['string', 'number', 'integer', 'boolean', 'date'];
    public const MAX_FIELDS = 30;
    public const MAX_DESCRIPTION_LENGTH = 300;
    private const DEFAULT_TABLE = 'extracted';
    /** Columns the table adds around the fields. */
    public const RESERVED_COLUMNS = ['file_id', 'file', 'extracted_at'];

    public function getId(): string {
        return 'extract';
    }

    public function getLabel(): string {
        return 'Extract fields into a table';
    }

    public function optionNames(): array {
        return ['fields', 'tableName'];
    }

    public function validateOptions(array $options): void {
        $fields = $options['fields'] ?? null;
        if (!is_array($fields) || $fields === [] || !array_is_list($fields)) {
            throw new \InvalidArgumentException('fields is required: a list of {name, type, description}');
        }
        if (count($fields) > self::MAX_FIELDS) {
            throw new \InvalidArgumentException('fields holds at most ' . self::MAX_FIELDS . ' entries');
        }
        $seen = [];
        foreach ($fields as $i => $field) {
            $at = 'fields[' . $i . ']';
            if (!is_array($field)) {
                throw new \InvalidArgumentException("$at must be an object with name and type");
            }
            $name = $field['name'] ?? null;
            if (!is_string($name) || preg_match('/^[a-z][a-z0-9_]{0,39}$/', $name) !== 1) {
                throw new \InvalidArgumentException("$at.name must be 1 to 40 lowercase letters, digits or underscores, starting with a letter");
            }
            if (in_array($name, self::RESERVED_COLUMNS, true)) {
                throw new \InvalidArgumentException("$at.name \"$name\" is a column the table adds itself");
            }
            if (isset($seen[$name])) {
                throw new \InvalidArgumentException("$at.name \"$name\" appears twice");
            }
            $seen[$name] = true;
            if (!in_array($field['type'] ?? null, self::TYPES, true)) {
                throw new \InvalidArgumentException("$at.type must be one of: " . implode(', ', self::TYPES));
            }
            $description = $field['description'] ?? '';
            if (!is_string($description) || mb_strlen($description) > self::MAX_DESCRIPTION_LENGTH) {
                throw new \InvalidArgumentException("$at.description must be text of at most " . self::MAX_DESCRIPTION_LENGTH . ' characters');
            }
        }
        if (isset($options['tableName'])
            && (!is_string($options['tableName']) || preg_match('/^[a-z0-9][a-z0-9-]{0,31}$/', $options['tableName']) !== 1)) {
            throw new \InvalidArgumentException('tableName must be 1 to 32 lowercase letters, digits or hyphens, such as "invoices"');
        }
    }

    public function buildPrompt(string $content, array $options): string {
        return $this->instruction('the document below', $options) . "\n\nDocument:\n$content";
    }

    public function buildDocumentPrompt(array $options): string {
        return $this->instruction('the attached document', $options);
    }

    /** @param array<string, mixed> $options */
    private function instruction(string $document, array $options): string {
        $lines = [];
        foreach (self::fields($options) as $f) {
            $hint = match ($f['type']) {
                'number' => 'a number, without currency symbols or thousands separators',
                'integer' => 'a whole number',
                'boolean' => 'true or false',
                'date' => 'a date as YYYY-MM-DD',
                default => 'text',
            };
            $lines[] = '- ' . $f['name'] . ' (' . $hint . ')' . ($f['description'] !== '' ? ': ' . $f['description'] : '');
        }
        return "Extract these fields from $document:\n" . implode("\n", $lines) . "\n\n"
            . 'Respond with a single JSON object that has exactly these keys and nothing else — no '
            . 'Markdown, no commentary. Use null for a field the document does not contain; do not guess.';
    }

    /**
     * The table is not a per-source file, so there is no suffix to match;
     * the options only name the table ({@see tableFileName()}).
     */
    public function outputSuffix(array $options): string {
        unset($options);
        return '';
    }

    public function doneTag(array $options = []): string {
        $key = json_encode([self::fields($options), $this->tableFileName($options)]) ?: '';
        return 'aiquila:extracted-' . substr(hash('sha256', $key), 0, 8);
    }

    public function summaryVerb(): string {
        return 'Extracted';
    }

    public function tableFileName(array $options): string {
        $name = $options['tableName'] ?? null;
        return (is_string($name) && $name !== '' ? $name : self::DEFAULT_TABLE) . '.csv';
    }

    public function columns(array $options): array {
        return array_map(static fn (array $f): string => $f['name'], self::fields($options));
    }

    public function jsonSchema(array $options): array {
        $properties = [];
        foreach (self::fields($options) as $f) {
            $property = match ($f['type']) {
                'number' => ['type' => ['number', 'null']],
                'integer' => ['type' => ['integer', 'null']],
                'boolean' => ['type' => ['boolean', 'null']],
                'date' => ['type' => ['string', 'null'], 'format' => 'date'],
                default => ['type' => ['string', 'null']],
            };
            if ($f['description'] !== '') {
                $property['description'] = $f['description'];
            }
            $properties[$f['name']] = $property;
        }
        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => array_keys($properties),
            'additionalProperties' => false,
        ];
    }

    public function parseRow(string $response, array $options): array {
        $json = trim($response);
        // Without structured outputs a model may still wrap the object in a
        // code fence or a sentence; take the outermost object.
        $first = strcspn($json, '{[');
        if (($json[$first] ?? '') === '[') {
            throw new \InvalidArgumentException('The answer is not a JSON object');
        }
        $start = strpos($json, '{');
        $end = strrpos($json, '}');
        if ($start === false || $end === false || $end < $start) {
            throw new \InvalidArgumentException('The answer holds no JSON object');
        }
        $decoded = json_decode(substr($json, $start, $end - $start + 1), true);
        if (!is_array($decoded) || array_is_list($decoded) && $decoded !== []) {
            throw new \InvalidArgumentException('The answer is not a JSON object');
        }
        $row = [];
        foreach (self::fields($options) as $f) {
            $row[$f['name']] = self::coerce($f['name'], $f['type'], $decoded[$f['name']] ?? null);
        }
        return $row;
    }

    /** A value of the field's type, or null; anything else fails the row. */
    private static function coerce(string $name, string $type, mixed $value): string|int|float|bool|null {
        if ($value === null || $value === '') {
            return null;
        }
        $bad = static fn (): \InvalidArgumentException => new \InvalidArgumentException(
            "$name: expected $type, got " . (is_scalar($value) ? var_export($value, true) : gettype($value))
        );
        switch ($type) {
            case 'number':
                if (is_int($value) || is_float($value)) {
                    return $value;
                }
                if (is_string($value) && is_numeric(trim($value))) {
                    return (float)trim($value);
                }
                throw $bad();
            case 'integer':
                if (is_int($value)) {
                    return $value;
                }
                if (is_float($value) && floor($value) === $value) {
                    return (int)$value;
                }
                if (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
                    return (int)trim($value);
                }
                throw $bad();
            case 'boolean':
                if (is_bool($value)) {
                    return $value;
                }
                if (is_string($value) && in_array(strtolower(trim($value)), ['true', 'false'], true)) {
                    return strtolower(trim($value)) === 'true';
                }
                throw $bad();
            case 'date':
                if (is_string($value) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($value), $m) === 1
                    && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
                    return trim($value);
                }
                throw $bad();
            default:
                if (is_scalar($value)) {
                    return is_bool($value) ? ($value ? 'true' : 'false') : (string)$value;
                }
                throw $bad();
        }
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array{name: string, type: string, description: string}>
     */
    private static function fields(array $options): array {
        $out = [];
        foreach (is_array($options['fields'] ?? null) ? $options['fields'] : [] as $f) {
            if (is_array($f) && is_string($f['name'] ?? null)) {
                $out[] = [
                    'name' => $f['name'],
                    'type' => is_string($f['type'] ?? null) ? $f['type'] : 'string',
                    'description' => is_string($f['description'] ?? null) ? trim($f['description']) : '',
                ];
            }
        }
        return $out;
    }
}
