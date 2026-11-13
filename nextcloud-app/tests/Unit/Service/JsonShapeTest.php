<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Tests\Unit\Service;

use OCA\AIquila\Service\JsonShape;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every case starts from the JSON the app actually receives, decodes it the
 * way the app does (`json_decode(..., true)`), and asserts on the re-encoded
 * bytes: `{}` and `[]` are the same PHP value, so only the encoding shows the
 * difference.
 */
class JsonShapeTest extends TestCase {
    private static function decode(string $json): mixed {
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    private static function encode(mixed $value): string {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    // ── object() ─────────────────────────────────────────────────────────────

    /** @return iterable<string, array{mixed, string}> */
    public static function objects(): iterable {
        yield 'empty array' => [[], '{}'];
        yield 'null' => [null, '{}'];
        yield 'scalar' => ['oops', '{}'];
        yield 'stdClass' => [new \stdClass(), '{}'];
        yield 'object' => [['path' => '/'], '{"path":"/"}'];
        // A list cannot be made an object without inventing keys.
        yield 'non-empty list' => [[1, 2], '[1,2]'];
    }

    #[DataProvider('objects')]
    public function testObject(mixed $value, string $expected): void {
        $this->assertSame($expected, self::encode(JsonShape::object($value)));
    }

    // ── schema() ─────────────────────────────────────────────────────────────

    /**
     * JSON Schema 2020-12 Core §10.2, §10.3, §11, §8.2.4 and Validation
     * §6.5.4: each keyword whose value is a schema or an object gets `{}`
     * back. A schema is never a list, so a bare `[]` is the empty schema.
     *
     * @return iterable<string, array{string}>
     */
    public static function schemas(): iterable {
        yield 'properties' => ['{"type":"object","properties":{}}'];
        yield 'property schemas' => ['{"type":"object","properties":{"a":{},"b":{"type":"object","properties":{}}}}'];
        yield 'patternProperties' => ['{"patternProperties":{"^x-":{}}}'];
        yield 'additionalProperties schema' => ['{"type":"object","additionalProperties":{}}'];
        yield 'additionalProperties false' => ['{"type":"object","additionalProperties":false}'];
        yield 'unevaluatedProperties' => ['{"unevaluatedProperties":{}}'];
        yield 'propertyNames' => ['{"propertyNames":{}}'];
        yield 'items' => ['{"type":"array","items":{}}'];
        yield 'prefixItems' => ['{"prefixItems":[{},{"type":"string"}],"items":{}}'];
        yield 'unevaluatedItems' => ['{"unevaluatedItems":{}}'];
        yield 'contains' => ['{"contains":{}}'];
        yield 'combinators' => ['{"anyOf":[{},{"type":"object","properties":{}}],"oneOf":[{}],"allOf":[{"properties":{}}]}'];
        yield 'not / if / then / else' => ['{"not":{},"if":{},"then":{"properties":{}},"else":{}}'];
        yield '$defs' => ['{"$defs":{"a":{},"b":{"properties":{}}},"$ref":"#/$defs/a"}'];
        yield 'empty $defs' => ['{"$defs":{}}'];
        yield 'dependentSchemas' => ['{"dependentSchemas":{"a":{}}}'];
        yield 'dependentRequired' => ['{"dependentRequired":{}}'];
        yield 'contentSchema' => ['{"contentMediaType":"application/json","contentSchema":{}}'];
        yield 'draft-07 definitions' => ['{"definitions":{"a":{}}}'];
        yield 'draft-07 tuple items' => ['{"items":[{},{"type":"object","properties":{}}],"additionalItems":{}}'];
        yield 'draft-07 dependencies' => ['{"dependencies":{"a":{"properties":{}},"b":["a"]}}'];
        yield 'boolean subschemas' => ['{"properties":{"a":true,"b":false},"items":true}'];
        yield 'default conforms to its schema' => ['{"type":"object","properties":{"o":{"type":"object","default":{}}}}'];
        yield 'enum / const / examples' => ['{"type":"object","enum":[{}],"const":{},"examples":[{}]}'];
        // Lists that are lists stay lists.
        yield 'required / enum / type' => ['{"type":["array","null"],"required":[],"enum":[[]],"items":{"type":"string"}}'];
        yield 'array default' => ['{"type":"array","default":[]}'];
        yield 'unknown keywords untouched' => ['{"x-meta":[],"title":"t"}'];
    }

    #[DataProvider('schemas')]
    public function testSchemaRoundTripsDecodedJson(string $json): void {
        $this->assertSame($json, self::encode(JsonShape::schema(self::decode($json))));
    }

    public function testSchemaLeavesBooleanSchemasAlone(): void {
        $this->assertTrue(JsonShape::schema(true));
        $this->assertFalse(JsonShape::schema(false));
    }

    public function testEmptySchemaIsAnObject(): void {
        $this->assertSame('{}', self::encode(JsonShape::schema([])));
    }

    // ── toolInputSchema() ────────────────────────────────────────────────────

    /**
     * MCP `Tool.inputSchema` and Anthropic `input_schema` require an object
     * schema with root `type: "object"`.
     *
     * @return iterable<string, array{mixed, string}>
     */
    public static function toolInputSchemas(): iterable {
        yield 'missing' => [null, '{"type":"object","properties":{}}'];
        yield 'empty' => [[], '{"type":"object","properties":{}}'];
        yield 'boolean' => [true, '{"type":"object","properties":{}}'];
        yield 'list' => [[1], '{"type":"object","properties":{}}'];
        yield 'no type' => [['properties' => []], '{"type":"object","properties":{}}'];
        yield 'MCP no-parameter form' => [
            self::decode('{"type":"object","additionalProperties":false}'),
            '{"type":"object","additionalProperties":false}',
        ];
        yield 'kept as is' => [
            self::decode('{"$schema":"https://json-schema.org/draft/2020-12/schema","type":"object","properties":{"p":{"type":"string"}},"required":["p"]}'),
            '{"$schema":"https://json-schema.org/draft/2020-12/schema","type":"object","properties":{"p":{"type":"string"}},"required":["p"]}',
        ];
    }

    #[DataProvider('toolInputSchemas')]
    public function testToolInputSchema(mixed $schema, string $expected): void {
        $this->assertSame($expected, self::encode(JsonShape::toolInputSchema($schema)));
    }

    public function testToolInputSchemaIsIdempotent(): void {
        $once = JsonShape::toolInputSchema(self::decode('{"type":"object","properties":{"a":{"type":"object","properties":{}}}}'));
        $this->assertSame(self::encode($once), self::encode(JsonShape::toolInputSchema($once)));
    }

    // ── conform() / arguments() ──────────────────────────────────────────────

    /** @return iterable<string, array{string, string, string}> */
    public static function conformed(): iterable {
        $schema = '{"type":"object","properties":{'
            . '"obj":{"type":"object"},'
            . '"arr":{"type":"array"},'
            . '"either":{"type":["object","array"]},'
            . '"nullable":{"type":["object","null"]},'
            . '"untyped":{"properties":{"x":{}}},'
            . '"unknown":{},'
            . '"list":{"type":"array","items":{"type":"object"}},'
            . '"tuple":{"type":"array","prefixItems":[{"type":"array"},{"type":"object"}],"items":{"type":"object"}},'
            . '"ref":{"$ref":"#/$defs/thing"},'
            . '"anyOf":{"anyOf":[{"type":"object"},{"type":"string"}]},'
            . '"oneOfMixed":{"oneOf":[{"type":"object"},{"type":"array"}]},'
            . '"allOf":{"allOf":[{"properties":{"deep":{"type":"object"}}}]}'
            . '},'
            . '"patternProperties":{"^map_":{"type":"object"}},'
            . '"additionalProperties":{"type":"object","additionalProperties":{"type":"object"}},'
            . '"$defs":{"thing":{"type":"object","properties":{"inner":{"type":"object"}}}}}';

        yield 'object becomes {}' => [$schema, '{"obj":{}}', '{"obj":{}}'];
        yield 'array stays []' => [$schema, '{"arr":[]}', '{"arr":[]}'];
        yield 'object-or-array is ambiguous' => [$schema, '{"either":[]}', '{"either":[]}'];
        yield 'nullable object' => [$schema, '{"nullable":{}}', '{"nullable":{}}'];
        yield 'type inferred from keywords' => [$schema, '{"untyped":{}}', '{"untyped":{}}'];
        yield 'undescribed stays' => [$schema, '{"unknown":[]}', '{"unknown":[]}'];
        yield 'array items' => [$schema, '{"list":[{},{"a":1}]}', '{"list":[{},{"a":1}]}'];
        yield 'prefixItems then items' => [$schema, '{"tuple":[[],{},{}]}', '{"tuple":[[],{},{}]}'];
        yield 'local $ref' => [$schema, '{"ref":{"inner":{}}}', '{"ref":{"inner":{}}}'];
        yield 'anyOf with one object branch' => [$schema, '{"anyOf":{}}', '{"anyOf":{}}'];
        yield 'oneOf object or array is ambiguous' => [$schema, '{"oneOfMixed":[]}', '{"oneOfMixed":[]}'];
        yield 'allOf branch' => [$schema, '{"allOf":{"deep":{}}}', '{"allOf":{"deep":{}}}'];
        yield 'patternProperties' => [$schema, '{"map_a":{}}', '{"map_a":{}}'];
        yield 'additionalProperties' => [$schema, '{"other":{"k":{}}}', '{"other":{"k":{}}}'];
        // json_decode(..., true) turns {"0": …} into a list; the schema says object.
        yield 'numeric keys stay an object' => [$schema, '{"other":{"0":{}}}', '{"other":{"0":{}}}'];
        yield 'empty top level' => [$schema, '{}', '{}'];
        yield 'empty-object default for untouched keys' => [$schema, '{"obj":{"a":[]}}', '{"obj":{"a":[]}}'];
    }

    #[DataProvider('conformed')]
    public function testConform(string $schema, string $value, string $expected): void {
        $this->assertSame($expected, self::encode(JsonShape::conform(self::decode($value), self::decode($schema))));
    }

    public function testConformWithoutSchemaChangesNothing(): void {
        $this->assertSame('{"a":[]}', self::encode(JsonShape::conform(self::decode('{"a":{}}'), null)));
    }

    public function testRecursiveRefTerminates(): void {
        $schema = self::decode('{"$ref":"#/$defs/node","$defs":{"node":{"type":"object","properties":{"child":{"$ref":"#/$defs/node"}}}}}');
        $value = self::decode('{"child":{"child":{"child":{}}}}');
        $this->assertSame('{"child":{"child":{"child":{}}}}', self::encode(JsonShape::conform($value, $schema)));

        $cycle = self::decode('{"$ref":"#/$defs/a","$defs":{"a":{"$ref":"#/$defs/b"},"b":{"$ref":"#/$defs/a"}}}');
        $this->assertSame('[]', self::encode(JsonShape::conform([], $cycle)));
    }

    public function testInvalidPatternMatchesNothing(): void {
        $schema = self::decode('{"type":"object","patternProperties":{"([":{"type":"object"}}}');
        $this->assertSame('{"a":[]}', self::encode(JsonShape::conform(self::decode('{"a":{}}'), $schema)));
    }

    public function testArgumentsIsAlwaysAnObject(): void {
        $this->assertSame('{}', self::encode(JsonShape::arguments([])));
        $this->assertSame('{}', self::encode(JsonShape::arguments(null, ['type' => 'object'])));
        $this->assertSame(
            '{"f":{}}',
            self::encode(JsonShape::arguments(['f' => []], self::decode('{"type":"object","properties":{"f":{"type":"object"}}}')))
        );
    }
}
