<?php

namespace OCA\AIquila\Tests\Unit\Settings;

use OCA\AIquila\Service\ClaudeModels;
use OCA\AIquila\Settings\AdminDeclarativeSettings;
use OCP\Settings\DeclarativeSettingsTypes;
use PHPUnit\Framework\TestCase;

class AdminDeclarativeSettingsTest extends TestCase {
	private AdminDeclarativeSettings $settings;

	protected function setUp(): void {
		$this->settings = new AdminDeclarativeSettings();
	}

	public function testSchemaStructure(): void {
		$schema = $this->settings->getSchema();

		$this->assertSame('aiquila_admin', $schema['id']);
		$this->assertSame(DeclarativeSettingsTypes::SECTION_TYPE_ADMIN, $schema['section_type']);
		$this->assertSame('aiquila', $schema['section_id']);
		$this->assertSame(DeclarativeSettingsTypes::STORAGE_TYPE_INTERNAL, $schema['storage_type']);
		$this->assertNotEmpty($schema['title']);
		$this->assertIsArray($schema['fields']);
	}

	public function testModelField(): void {
		$fields = $this->settings->getSchema()['fields'];
		$model = $this->findField($fields, 'model');

		$this->assertNotNull($model, 'model field should exist');
		$this->assertSame(DeclarativeSettingsTypes::SELECT, $model['type']);
		$this->assertSame(ClaudeModels::DEFAULT_MODEL, $model['default']);

		$optionValues = array_column($model['options'], 'value');
		$this->assertSame(ClaudeModels::getAllModels(), $optionValues);
	}

	public function testMaxTokensField(): void {
		$fields = $this->settings->getSchema()['fields'];
		$field = $this->findField($fields, 'max_tokens');

		$this->assertNotNull($field, 'max_tokens field should exist');
		$this->assertSame(DeclarativeSettingsTypes::TEXT, $field['type']);
		$this->assertSame((string)ClaudeModels::DEFAULT_MAX_TOKENS, $field['default']);
	}

	public function testApiTimeoutField(): void {
		$fields = $this->settings->getSchema()['fields'];
		$field = $this->findField($fields, 'api_timeout');

		$this->assertNotNull($field, 'api_timeout field should exist');
		$this->assertSame(DeclarativeSettingsTypes::TEXT, $field['type']);
		$this->assertSame('30', $field['default']);
	}

	public function testDoesNotContainApiKey(): void {
		$fields = $this->settings->getSchema()['fields'];
		$this->assertNull($this->findField($fields, 'api_key'), 'api_key must not be in declarative settings');
	}

	private function findField(array $fields, string $id): ?array {
		foreach ($fields as $field) {
			if ($field['id'] === $id) {
				return $field;
			}
		}
		return null;
	}
}
