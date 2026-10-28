<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Turns the specs generate-spec writes with --openapi-version 3.1.0 into valid
 * OpenAPI 3.1. The extractor only relabels the version: it still emits the
 * 3.0 keyword `nullable`, which 3.1 dropped in favour of JSON Schema 2020-12
 * (OpenAPI 3.1.0 §4.8.24; a validator ignores the unknown keyword and treats
 * the value as non-nullable). Each `nullable: true` becomes a type list with
 * "null", or an anyOf with a null schema when there is no plain type. An
 * empty components.schemas, written as [] by the extractor, becomes {}.
 *
 * Usage: php tools/openapi-3.1.php openapi.json [more.json ...]
 */

function convert(mixed $node): mixed {
	if (is_array($node)) {
		return array_map(convert(...), $node);
	}
	if (!$node instanceof stdClass) {
		return $node;
	}
	foreach (get_object_vars($node) as $key => $value) {
		$node->{$key} = convert($value);
	}
	if (!property_exists($node, 'nullable')) {
		return $node;
	}
	$nullable = $node->nullable === true;
	unset($node->nullable);
	if (!$nullable) {
		return $node;
	}
	if (isset($node->type) && is_string($node->type)) {
		$node->type = [$node->type, 'null'];
		if (isset($node->enum) && is_array($node->enum) && !in_array(null, $node->enum, true)) {
			$node->enum[] = null;
		}
		return $node;
	}
	// $ref, allOf, anyOf, oneOf: wrap, keeping annotations on the outside.
	$outer = new stdClass();
	$inner = new stdClass();
	foreach (get_object_vars($node) as $key => $value) {
		if (in_array($key, ['description', 'default', 'title', 'deprecated'], true)) {
			$outer->{$key} = $value;
		} else {
			$inner->{$key} = $value;
		}
	}
	$outer->anyOf = [$inner, (object)['type' => 'null']];
	return $outer;
}

if ($argc < 2) {
	fwrite(STDERR, "usage: php tools/openapi-3.1.php <spec.json>...\n");
	exit(2);
}
foreach (array_slice($argv, 1) as $file) {
	$spec = json_decode((string)file_get_contents($file), false, 512, JSON_THROW_ON_ERROR);
	if (($spec->openapi ?? '') !== '3.1.0') {
		fwrite(STDERR, "$file: expected openapi 3.1.0, got " . ($spec->openapi ?? 'none') . "\n");
		exit(1);
	}
	// PHP's json_encode writes an empty components.schemas as [], but it is a
	// map (OpenAPI 3.1.0 §4.8.7).
	if (isset($spec->components) && $spec->components->schemas === []) {
		$spec->components->schemas = new stdClass();
	}
	$out = json_encode(convert($spec), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
	file_put_contents($file, $out . "\n");
}
