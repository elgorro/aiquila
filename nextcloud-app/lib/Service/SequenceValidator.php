<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service;

use OCA\AIquila\Db\Sequence;

/**
 * Checks a sequence before it is stored, against the MCP server's catalog.
 *
 * The MCP server parses every definition again before it lists or runs one
 * (mcp-server/src/sequences/definition.ts and template.ts); these checks are
 * the same, so a user learns of a mistake when saving rather than when the
 * sequence quietly fails to appear.
 *
 * - slug: `[a-z0-9][a-z0-9_-]{0,47}`, so `seq_<slug>` and `seq_my_<slug>` are
 *   valid MCP tool names (MCP 2026-07-28, Tools › Tool Names). An instance
 *   slug may not be a built-in's, nor start with `my_` / `my-`, which would
 *   collide with a personal `seq_my_*` name.
 * - inputs: unique template identifiers of a known type; `dryRun` is reserved.
 * - steps: one to the policy's maximum; each names a tool in the catalog (a
 *   sequence tool never is one, so sequences cannot nest), no other server,
 *   only arguments the tool's input schema has, every required one present,
 *   and `stop` or `continue` on failure.
 * - templates: `{{inputs.<name>}}` of a declared input, `{{steps.<id>.text}}`
 *   or `{{steps.<id>.result…}}` of an earlier step, `{{today±Nd|w}}`,
 *   `{{now±Nm|h|d|w}}`.
 */
class SequenceValidator {
    private const SLUG_RE = '/^[a-z0-9][a-z0-9_-]{0,47}$/';
    private const IDENT_RE = '/^[A-Za-z_][A-Za-z0-9_]{0,31}$/';
    private const PLACEHOLDER_RE = '/\{\{\s*([^{}]*?)\s*\}\}/';
    private const EXPRESSION_RE = '/^(inputs|steps|today|now)((?:\.[A-Za-z0-9_-]+)*)(?:([+-])(\d{1,4})([mhdw]))?$/';
    private const ROOT_RE = '/^(inputs|steps|today|now)\b/';
    private const MAX_INPUT_DESCRIPTION = 500;
    private const JSON_TYPES = ['string', 'integer', 'number', 'boolean', 'array', 'object', 'null'];

    public function __construct(
        private SequenceCatalog $catalog,
    ) {
    }

    /**
     * @param array<array-key, mixed> $definition `{inputs, steps}`
     * @return array{inputs: list<array<string, mixed>>, steps: list<array<string, mixed>>} the normalized definition
     * @throws \InvalidArgumentException with a message safe to show the caller
     */
    public function validateDefinition(array $definition, int $maxSteps): array {
        $inputs = $this->validateInputs($definition['inputs'] ?? []);
        $declared = array_map(static fn (array $i): string => (string)$i['name'], $inputs);
        $steps = $this->validateSteps($definition['steps'] ?? null, $declared, $maxSteps);
        return ['inputs' => $inputs, 'steps' => $steps];
    }

    /**
     * @throws \InvalidArgumentException
     */
    public function validateSlug(string $slug, string $scope): void {
        if (preg_match(self::SLUG_RE, $slug) !== 1) {
            throw new \InvalidArgumentException('The tool name may use lowercase letters, digits, _ and -, start with a letter or digit, and have at most 48 characters');
        }
        if ($scope === Sequence::SCOPE_INSTANCE) {
            if (in_array($slug, $this->catalog->builtinSlugs(), true)) {
                throw new \InvalidArgumentException('A built-in sequence already has this tool name');
            }
            if (preg_match('/^my[_-]/', $slug) === 1) {
                throw new \InvalidArgumentException('Organization tool names cannot start with "my_" or "my-"');
            }
        }
    }

    /**
     * @throws \InvalidArgumentException
     */
    public function validateText(?string $title, ?string $description): void {
        if ($title !== null && (trim($title) === '' || mb_strlen($title) > $this->catalog->limit('titleChars', 255))) {
            throw new \InvalidArgumentException('The title must have 1 to 255 characters');
        }
        $max = $this->catalog->limit('descriptionChars', 2000);
        if ($description !== null && mb_strlen($description) > $max) {
            throw new \InvalidArgumentException(sprintf('The description is longer than %d characters', $max));
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function validateInputs(mixed $raw): array {
        if (!is_array($raw) || !array_is_list($raw)) {
            throw new \InvalidArgumentException('inputs must be a list');
        }
        $max = $this->catalog->limit('inputs', 20);
        if (count($raw) > $max) {
            throw new \InvalidArgumentException(sprintf('A sequence has at most %d inputs', $max));
        }
        $types = $this->catalog->data()['inputTypes'] ?? ['string', 'integer', 'number', 'boolean', 'array'];
        $reserved = $this->catalog->data()['reservedInputs'] ?? ['dryRun'];
        $seen = [];
        $out = [];
        foreach ($raw as $n => $input) {
            $label = 'Input ' . ($n + 1);
            if (!is_array($input)) {
                throw new \InvalidArgumentException($label . ' must be an object');
            }
            $name = $input['name'] ?? null;
            if (!is_string($name) || preg_match(self::IDENT_RE, $name) !== 1) {
                throw new \InvalidArgumentException($label . ': the name must start with a letter or _ and use letters, digits and _ (at most 32)');
            }
            if (is_array($reserved) && in_array($name, $reserved, true)) {
                throw new \InvalidArgumentException('Input name "' . $name . '" is reserved');
            }
            if (isset($seen[$name])) {
                throw new \InvalidArgumentException('Input "' . $name . '" is declared twice');
            }
            $seen[$name] = true;
            $type = $input['type'] ?? null;
            if (!is_string($type) || !is_array($types) || !in_array($type, $types, true)) {
                throw new \InvalidArgumentException('Input "' . $name . '": unknown type');
            }
            $description = $input['description'] ?? '';
            if (!is_string($description) || mb_strlen($description) > self::MAX_INPUT_DESCRIPTION) {
                throw new \InvalidArgumentException(sprintf('Input "%s": the description must be text of at most %d characters', $name, self::MAX_INPUT_DESCRIPTION));
            }
            $entry = [
                'name' => $name,
                'type' => $type,
                'required' => ($input['required'] ?? false) === true,
                'description' => $description,
            ];
            if (array_key_exists('default', $input) && $input['default'] !== null) {
                if (!self::matchesType($input['default'], $type)) {
                    throw new \InvalidArgumentException('Input "' . $name . '": the default is not of type ' . $type);
                }
                $entry['default'] = $input['default'];
            }
            $out[] = $entry;
        }
        return $out;
    }

    /**
     * @param list<string> $declared
     * @return list<array<string, mixed>>
     */
    private function validateSteps(mixed $raw, array $declared, int $maxSteps): array {
        if (!is_array($raw) || !array_is_list($raw) || $raw === []) {
            throw new \InvalidArgumentException('A sequence needs at least one step');
        }
        $max = min($maxSteps, $this->catalog->limit('steps', 20));
        if (count($raw) > $max) {
            throw new \InvalidArgumentException(sprintf('A sequence has at most %d steps', $max));
        }
        $earlier = [];
        $out = [];
        foreach ($raw as $n => $step) {
            $label = 'Step ' . ($n + 1);
            if (!is_array($step)) {
                throw new \InvalidArgumentException($label . ' must be an object');
            }
            $id = $step['id'] ?? null;
            if ($id !== null && $id !== '' && (!is_string($id) || preg_match(self::IDENT_RE, $id) !== 1)) {
                throw new \InvalidArgumentException($label . ': the id must start with a letter or _ and use letters, digits and _');
            }
            $id = is_string($id) && $id !== '' ? $id : 'step' . ($n + 1);
            if (isset($earlier[$id])) {
                throw new \InvalidArgumentException('Two steps have the id "' . $id . '"');
            }
            $server = $step['server'] ?? '';
            if ($server !== '' && $server !== null) {
                throw new \InvalidArgumentException($label . ': steps on other MCP servers are not supported yet');
            }
            $toolName = $step['tool'] ?? null;
            $tool = is_string($toolName) ? $this->catalog->tool($toolName) : null;
            if ($tool === null) {
                throw new \InvalidArgumentException($label . ': unknown tool');
            }
            $onFailure = $step['onFailure'] ?? 'stop';
            if ($onFailure !== 'stop' && $onFailure !== 'continue') {
                throw new \InvalidArgumentException($label . ': on failure must be "stop" or "continue"');
            }
            $args = $step['arguments'] ?? [];
            if (!is_array($args) || ($args !== [] && array_is_list($args))) {
                throw new \InvalidArgumentException($label . ': arguments must be an object');
            }
            $this->validateArguments($label, $tool, $args, $declared, $earlier);

            $entry = ['id' => $id, 'tool' => $tool['name'], 'arguments' => $args, 'onFailure' => $onFailure];
            $out[] = $entry;
            $earlier[$id] = true;
        }
        return $out;
    }

    /**
     * @param array{name: string, inputSchema: array<string, mixed>, ...} $tool
     * @param array<array-key, mixed> $args
     * @param list<string> $declared
     * @param array<string, true> $earlier
     */
    private function validateArguments(string $label, array $tool, array $args, array $declared, array $earlier): void {
        $schema = $tool['inputSchema'];
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];
        foreach ($args as $key => $value) {
            if (!is_string($key) || !array_key_exists($key, $properties)) {
                throw new \InvalidArgumentException(sprintf('%s: %s has no argument "%s"', $label, $tool['name'], (string)$key));
            }
            $this->checkTemplates($label, $value, $declared, $earlier);
            $property = $properties[$key];
            if (is_array($property) && !self::isTemplateValue($value)) {
                $this->checkLiteral($label, $key, $value, $property);
            }
        }
        foreach ($required as $name) {
            if (is_string($name) && !array_key_exists($name, $args)) {
                throw new \InvalidArgumentException(sprintf('%s: %s needs the argument "%s"', $label, $tool['name'], $name));
            }
        }
    }

    /**
     * @param list<string> $declared
     * @param array<string, true> $earlier
     */
    private function checkTemplates(string $label, mixed $value, array $declared, array $earlier): void {
        if (is_array($value)) {
            foreach ($value as $v) {
                $this->checkTemplates($label, $v, $declared, $earlier);
            }
            return;
        }
        if (!is_string($value) || preg_match_all(self::PLACEHOLDER_RE, $value, $matches) === 0) {
            return;
        }
        foreach ($matches[1] as $expr) {
            if (preg_match(self::EXPRESSION_RE, $expr, $m) !== 1) {
                if (preg_match(self::ROOT_RE, $expr) === 1) {
                    throw new \InvalidArgumentException(sprintf('%s: invalid placeholder {{%s}}', $label, $expr));
                }
                continue;
            }
            $root = $m[1];
            $path = ($m[2] ?? '') === '' ? [] : explode('.', substr($m[2], 1));
            $unit = $m[5] ?? '';
            $hasOffset = ($m[3] ?? '') !== '';
            if (($root === 'inputs' || $root === 'steps') && $hasOffset) {
                throw new \InvalidArgumentException(sprintf('%s: only today and now take an offset', $label));
            }
            if ($root === 'inputs') {
                if (count($path) !== 1 || !in_array($path[0], $declared, true)) {
                    throw new \InvalidArgumentException(sprintf('%s: {{%s}} is not a declared input', $label, $expr));
                }
            } elseif ($root === 'steps') {
                $ref = $path[0] ?? '';
                $field = $path[1] ?? '';
                if (!isset($earlier[$ref])) {
                    throw new \InvalidArgumentException(sprintf('%s: {{%s}} does not name an earlier step', $label, $expr));
                }
                if (($field !== 'text' && $field !== 'result') || ($field === 'text' && count($path) > 2)) {
                    throw new \InvalidArgumentException(sprintf('%s: write steps.%s.text or steps.%s.result.<path>', $label, $ref, $ref));
                }
            } elseif ($path !== []) {
                throw new \InvalidArgumentException(sprintf('%s: invalid placeholder {{%s}}', $label, $expr));
            } elseif ($root === 'today' && $hasOffset && $unit !== 'd' && $unit !== 'w') {
                throw new \InvalidArgumentException(sprintf('%s: today shifts by days (d) or weeks (w)', $label));
            }
        }
    }

    /**
     * A value of an argument given literally, against the property's JSON
     * Schema `type` and `enum`. Nested schemas are left to the MCP server,
     * which parses every argument with the tool's own schema before a run.
     *
     * @param array<array-key, mixed> $property
     */
    private function checkLiteral(string $label, string $key, mixed $value, array $property): void {
        $types = $property['type'] ?? null;
        $types = is_string($types) ? [$types] : (is_array($types) ? $types : []);
        $types = array_values(array_filter($types, static fn ($t): bool => is_string($t) && in_array($t, self::JSON_TYPES, true)));
        if ($types !== [] && !array_reduce($types, static fn (bool $ok, string $t): bool => $ok || self::matchesType($value, $t), false)) {
            throw new \InvalidArgumentException(sprintf('%s: "%s" must be of type %s', $label, $key, implode(' or ', $types)));
        }
        $enum = $property['enum'] ?? null;
        if (is_array($enum) && !in_array($value, $enum, true)) {
            throw new \InvalidArgumentException(sprintf('%s: "%s" must be one of %s', $label, $key, implode(', ', array_map(static fn ($e): string => json_encode($e) ?: '', $enum))));
        }
    }

    /** A string that is only a placeholder takes its type at run time. */
    private static function isTemplateValue(mixed $value): bool {
        if (is_string($value)) {
            return preg_match('/^\{\{\s*(inputs|steps|today|now)\b[^{}]*\}\}$/', $value) === 1;
        }
        if (is_array($value)) {
            foreach ($value as $v) {
                if (self::isTemplateValue($v)) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function matchesType(mixed $value, string $type): bool {
        return match ($type) {
            'string' => is_string($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'array' => is_array($value) && array_is_list($value),
            'object' => is_array($value) && ($value === [] || !array_is_list($value)),
            'null' => $value === null,
            default => false,
        };
    }
}
