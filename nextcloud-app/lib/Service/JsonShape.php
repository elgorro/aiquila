<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service;

/**
 * Put back the JSON objects that associative decoding loses.
 *
 * `json_decode(..., true)` turns `{}` into `[]`, and `json_encode` writes an
 * empty PHP array back as the list `[]`. A JSON object with the keys "0", "1",
 * … comes back as a list too. Wherever the app decodes JSON from an MCP server,
 * a model or a client and later sends it on, an object position can turn into
 * a list and make the request invalid. These helpers restore the object at the
 * positions where the receiving spec requires one:
 *
 *  - object(): a value that must be an object as a whole, such as MCP
 *    `tools/call` `arguments`, Anthropic `tool_use.input` or the JSON in an
 *    OpenAI `function.arguments` string.
 *  - schema() / toolInputSchema(): a JSON Schema. Every keyword that holds a
 *    schema or a map of schemas gets `{}` back; a schema is never a list.
 *  - conform(): an instance validated against a schema, such as a tool call's
 *    arguments. `[]` becomes `{}` where the schema admits an object and no
 *    array, at any depth.
 *
 * A list that is genuinely a list (`required`, `enum`, an array argument)
 * stays a list. Values that are already `\stdClass` are left as they are.
 *
 * Keywords per JSON Schema 2020-12 Core §8.2.4 (`$defs`), §10.2.1 (`allOf`,
 * `anyOf`, `oneOf`, `not`), §10.2.2 (`if`, `then`, `else`,
 * `dependentSchemas`), §10.3.1 (`prefixItems`, `items`, `contains`), §10.3.2
 * (`properties`, `patternProperties`, `additionalProperties`,
 * `propertyNames`), §11 (`unevaluatedItems`, `unevaluatedProperties`), and
 * Validation §6.5.4 (`dependentRequired`), §8.5 (`contentSchema`). MCP lets a
 * tool name draft-07 with `$schema`, so its `definitions`, `dependencies`,
 * `additionalItems` and array form of `items` are handled too.
 */
final class JsonShape {
    /** Keywords whose value is one schema. */
    private const SCHEMA = [
        'additionalProperties', 'unevaluatedProperties', 'propertyNames',
        'items', 'additionalItems', 'unevaluatedItems', 'contains',
        'not', 'if', 'then', 'else', 'contentSchema',
    ];

    /** Keywords whose value is an object whose members are schemas. */
    private const SCHEMA_MAP = ['properties', 'patternProperties', 'dependentSchemas', '$defs', 'definitions'];

    /** Keywords whose value is an array of schemas. */
    private const SCHEMA_LIST = ['allOf', 'anyOf', 'oneOf', 'prefixItems'];

    /** Keywords whose value is an object whose members are not schemas. */
    private const OBJECT = ['dependentRequired'];

    /** Keywords whose value is an instance of the schema they sit in. */
    private const INSTANCE = ['const', 'default'];

    /** Keywords whose value is a list of instances of the schema they sit in. */
    private const INSTANCE_LIST = ['enum', 'examples'];

    /** Keywords that only apply to an object instance, for a schema without `type`. */
    private const OBJECT_HINTS = [
        'properties', 'patternProperties', 'additionalProperties', 'unevaluatedProperties',
        'propertyNames', 'required', 'minProperties', 'maxProperties',
        'dependentRequired', 'dependentSchemas', 'dependencies',
    ];

    /** Keywords that only apply to an array instance, for a schema without `type`. */
    private const ARRAY_HINTS = [
        'items', 'prefixItems', 'additionalItems', 'unevaluatedItems',
        'contains', 'minContains', 'maxContains', 'minItems', 'maxItems', 'uniqueItems',
    ];

    /** Bounds the walk through `$ref`, so a recursive schema terminates. */
    private const MAX_DEPTH = 64;

    /**
     * A value that must be a JSON object.
     *
     * An empty array, null or a scalar becomes `{}`. A non-empty list is
     * returned unchanged: turning it into `{"0": …}` would invent keys, and
     * the receiver should reject it rather than act on a guess.
     *
     * @return array<array-key, mixed>|\stdClass
     */
    public static function object(mixed $value): array|\stdClass {
        if ($value instanceof \stdClass) {
            return $value;
        }
        if (!is_array($value) || $value === []) {
            return new \stdClass();
        }
        return $value;
    }

    /**
     * A JSON Schema with `{}` restored at every keyword that holds a schema or
     * an object. A boolean schema (2020-12 Core §4.3.2) is returned unchanged.
     */
    public static function schema(mixed $schema): mixed {
        return self::normalizeSchema($schema, 0);
    }

    /**
     * The input schema of a tool, as MCP `Tool.inputSchema` and Anthropic
     * `input_schema` require it: a schema object whose root `type` is
     * `"object"`. A missing, empty or non-object schema becomes one that
     * accepts any object.
     *
     * @return array<string, mixed>
     */
    public static function toolInputSchema(mixed $schema): array {
        $schema = self::schema($schema);
        if (!is_array($schema) || $schema === [] || array_is_list($schema)) {
            return ['type' => 'object', 'properties' => new \stdClass()];
        }
        /** @var array<string, mixed> $schema */
        if (!isset($schema['type'])) {
            $schema = ['type' => 'object'] + $schema;
        }
        return $schema;
    }

    /**
     * Tool-call arguments as the receiver expects them: an object, with `{}`
     * restored wherever $schema (the tool's input schema) admits only an
     * object.
     *
     * @return array<array-key, mixed>|\stdClass
     */
    public static function arguments(mixed $arguments, mixed $schema = null): array|\stdClass {
        return self::object(self::conform($arguments, $schema));
    }

    /**
     * $value with `[]` turned back into `{}` wherever $schema admits an object
     * and no array. Positions the schema does not describe, or describes
     * ambiguously, are left as they are.
     *
     * Local references (`#/$defs/…`, `#/definitions/…`) are resolved against
     * $schema itself; other references are not followed.
     */
    public static function conform(mixed $value, mixed $schema): mixed {
        if (!is_array($schema)) {
            return $value;
        }
        return self::conformAt($value, $schema, $schema, 0);
    }

    // ── Schemas ──────────────────────────────────────────────────────────────

    private static function normalizeSchema(mixed $schema, int $depth): mixed {
        if (!is_array($schema)) {
            return $schema;
        }
        if ($schema === []) {
            return new \stdClass();
        }
        if (array_is_list($schema) || $depth >= self::MAX_DEPTH) {
            // Not a schema. Leave it to the receiver to reject.
            return $schema;
        }
        foreach ($schema as $key => $value) {
            if (!is_array($value)) {
                continue;
            }
            $key = (string)$key;
            if (in_array($key, self::SCHEMA, true)) {
                // Draft-07 `items` may be an array of schemas.
                $schema[$key] = $key === 'items' && $value !== [] && array_is_list($value)
                    ? self::normalizeSchemaList($value, $depth)
                    : self::normalizeSchema($value, $depth + 1);
            } elseif (in_array($key, self::SCHEMA_MAP, true)) {
                $schema[$key] = self::normalizeSchemaMap($value, $depth);
            } elseif (in_array($key, self::SCHEMA_LIST, true)) {
                $schema[$key] = self::normalizeSchemaList($value, $depth);
            } elseif (in_array($key, self::OBJECT, true)) {
                $schema[$key] = self::object($value);
            } elseif ($key === 'dependencies') {
                // Draft-07: each member is a schema or an array of property names.
                // An empty member means "no constraint" either way, so it stays.
                $schema[$key] = self::object(array_map(
                    static fn (mixed $member): mixed => is_array($member) && $member !== [] && !array_is_list($member)
                        ? self::normalizeSchema($member, $depth + 1)
                        : $member,
                    $value,
                ));
            }
        }
        // Instances last, so they are conformed to the schema as normalized.
        foreach (self::INSTANCE as $key) {
            if (array_key_exists($key, $schema)) {
                $schema[$key] = self::conformAt($schema[$key], $schema, $schema, $depth);
            }
        }
        foreach (self::INSTANCE_LIST as $key) {
            if (is_array($schema[$key] ?? null) && array_is_list($schema[$key])) {
                $schema[$key] = array_map(
                    static fn (mixed $instance): mixed => self::conformAt($instance, $schema, $schema, $depth),
                    $schema[$key],
                );
            }
        }
        return $schema;
    }

    /** @param array<array-key, mixed> $map */
    private static function normalizeSchemaMap(array $map, int $depth): array|\stdClass {
        if ($map === []) {
            return new \stdClass();
        }
        foreach ($map as $name => $member) {
            $map[$name] = self::normalizeSchema($member, $depth + 1);
        }
        return $map;
    }

    /**
     * @param array<array-key, mixed> $list
     * @return array<array-key, mixed>
     */
    private static function normalizeSchemaList(array $list, int $depth): array {
        return array_map(static fn (mixed $member): mixed => self::normalizeSchema($member, $depth + 1), $list);
    }

    // ── Instances ────────────────────────────────────────────────────────────

    /**
     * @param array<array-key, mixed> $schema
     * @param array<array-key, mixed> $root
     */
    private static function conformAt(mixed $value, array $schema, array $root, int $depth): mixed {
        if (!is_array($value) || $depth >= self::MAX_DEPTH) {
            return $value;
        }
        $branches = self::branches($schema, $root, $depth);
        [$object, $array] = self::admits($branches);

        if ($value === []) {
            return $object && !$array ? new \stdClass() : $value;
        }

        if (array_is_list($value) && !($object && !$array)) {
            foreach ($value as $i => $item) {
                $candidates = [];
                foreach ($branches as $branch) {
                    $candidate = self::itemSchema($branch, $i);
                    if ($candidate !== null) {
                        $candidates[] = $candidate;
                    }
                }
                $value[$i] = self::conformCandidates($item, $candidates, $root, $depth);
            }
            return $value;
        }

        // An object, including one whose keys "0", "1", … made it a list.
        $isList = array_is_list($value);
        foreach ($value as $name => $member) {
            $candidates = [];
            foreach ($branches as $branch) {
                $candidates = array_merge($candidates, self::propertySchemas($branch, (string)$name));
            }
            $value[$name] = self::conformCandidates($member, $candidates, $root, $depth);
        }
        return $isList ? (object)$value : $value;
    }

    /**
     * @param list<array<array-key, mixed>> $candidates
     * @param array<array-key, mixed> $root
     */
    private static function conformCandidates(mixed $value, array $candidates, array $root, int $depth): mixed {
        return match (count($candidates)) {
            0 => $value,
            1 => self::conformAt($value, $candidates[0], $root, $depth + 1),
            default => self::conformAt($value, ['anyOf' => $candidates], $root, $depth + 1),
        };
    }

    /**
     * The schemas that apply at one position: $schema itself, what its local
     * `$ref` points at, and the branches of its `allOf` / `anyOf` / `oneOf`,
     * recursively. Boolean and empty schemas say nothing about shape and are
     * left out.
     *
     * @param array<array-key, mixed> $schema
     * @param array<array-key, mixed> $root
     * @return list<array<array-key, mixed>>
     */
    private static function branches(array $schema, array $root, int $depth): array {
        if ($schema === [] || $depth >= self::MAX_DEPTH) {
            return [];
        }
        $out = [$schema];
        $ref = $schema['$ref'] ?? null;
        if (is_string($ref)) {
            $target = self::resolve($ref, $root);
            if ($target !== null) {
                $out = array_merge($out, self::branches($target, $root, $depth + 1));
            }
        }
        foreach (['allOf', 'anyOf', 'oneOf'] as $key) {
            if (!is_array($schema[$key] ?? null)) {
                continue;
            }
            foreach ($schema[$key] as $sub) {
                if (is_array($sub)) {
                    $out = array_merge($out, self::branches($sub, $root, $depth + 1));
                }
            }
        }
        return $out;
    }

    /**
     * Whether the branches admit an object, and whether they admit an array.
     *
     * @param list<array<array-key, mixed>> $branches
     * @return array{bool, bool}
     */
    private static function admits(array $branches): array {
        $object = false;
        $array = false;
        foreach ($branches as $branch) {
            $type = $branch['type'] ?? null;
            if (is_string($type) || (is_array($type) && $type !== [])) {
                $types = (array)$type;
                $object = $object || in_array('object', $types, true);
                $array = $array || in_array('array', $types, true);
                continue;
            }
            $object = $object || array_intersect_key($branch, array_flip(self::OBJECT_HINTS)) !== [];
            $array = $array || array_intersect_key($branch, array_flip(self::ARRAY_HINTS)) !== [];
        }
        return [$object, $array];
    }

    /**
     * The schemas for member $name of an object: its `properties` entry, else
     * every matching `patternProperties` entry, else `additionalProperties`.
     *
     * @param array<array-key, mixed> $schema
     * @return list<array<array-key, mixed>>
     */
    private static function propertySchemas(array $schema, string $name): array {
        $properties = $schema['properties'] ?? null;
        if (is_array($properties) && array_key_exists($name, $properties)) {
            return is_array($properties[$name]) ? [$properties[$name]] : [];
        }
        $out = [];
        $matched = false;
        if (is_array($schema['patternProperties'] ?? null)) {
            foreach ($schema['patternProperties'] as $pattern => $sub) {
                if (self::matches((string)$pattern, $name)) {
                    $matched = true;
                    if (is_array($sub)) {
                        $out[] = $sub;
                    }
                }
            }
        }
        if (!$matched && is_array($schema['additionalProperties'] ?? null)) {
            $out[] = $schema['additionalProperties'];
        }
        return $out;
    }

    /**
     * The schema for item $index of an array: `prefixItems` (or draft-07
     * array-form `items`) by position, then `items` (or `additionalItems`).
     *
     * @param array<array-key, mixed> $schema
     * @return array<array-key, mixed>|null
     */
    private static function itemSchema(array $schema, int $index): ?array {
        $items = $schema['items'] ?? null;
        $tuple = $schema['prefixItems'] ?? (is_array($items) && $items !== [] && array_is_list($items) ? $items : null);
        if (is_array($tuple)) {
            if (array_key_exists($index, $tuple)) {
                return is_array($tuple[$index]) ? $tuple[$index] : null;
            }
            $rest = isset($schema['prefixItems']) ? $items : ($schema['additionalItems'] ?? null);
            return is_array($rest) && !array_is_list($rest) ? $rest : null;
        }
        return is_array($items) ? $items : null;
    }

    /**
     * The schema a local JSON Pointer reference (RFC 6901) points at.
     *
     * @param array<array-key, mixed> $root
     * @return array<array-key, mixed>|null
     */
    private static function resolve(string $ref, array $root): ?array {
        if ($ref === '#') {
            return $root;
        }
        if (!str_starts_with($ref, '#/')) {
            return null;
        }
        $node = $root;
        foreach (explode('/', substr($ref, 2)) as $token) {
            $token = str_replace(['~1', '~0'], ['/', '~'], rawurldecode($token));
            if (!is_array($node) || !array_key_exists($token, $node)) {
                return null;
            }
            $node = $node[$token];
        }
        return is_array($node) ? $node : null;
    }

    /**
     * Whether an ECMA-262 pattern (2020-12 Core §6.4) matches $name. A pattern
     * PCRE cannot compile matches nothing.
     */
    private static function matches(string $pattern, string $name): bool {
        return @preg_match("\x01" . $pattern . "\x01u", $name) === 1;
    }
}
