<?php

namespace OCA\AIquila\Tests\Unit\Settings;

use OCA\AIquila\Service\ClaudeModels;
use OCA\AIquila\Settings\AdminRequestParamsDeclarativeSettings;
use OCP\Settings\DeclarativeSettingsTypes;
use PHPUnit\Framework\TestCase;

class AdminRequestParamsDeclarativeSettingsTest extends TestCase {
	private AdminRequestParamsDeclarativeSettings $settings;

	protected function setUp(): void {
		$this->settings = new AdminRequestParamsDeclarativeSettings();
	}

	public function testSchemaStructure(): void {
		$schema = $this->settings->getSchema();

		$this->assertSame('aiquila_admin_params', $schema['id']);
		$this->assertSame(DeclarativeSettingsTypes::SECTION_TYPE_ADMIN, $schema['section_type']);
		$this->assertSame('aiquila', $schema['section_id']);
		$this->assertSame(DeclarativeSettingsTypes::STORAGE_TYPE_INTERNAL, $schema['storage_type']);
		$this->assertNotEmpty($schema['title']);
		$this->assertIsArray($schema['fields']);
		$this->assertCount(2, $schema['fields']);
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

	public function testDoesNotContainModel(): void {
		$fields = $this->settings->getSchema()['fields'];
		$this->assertNull($this->findField($fields, 'model'), 'model field lives in its own form');
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
