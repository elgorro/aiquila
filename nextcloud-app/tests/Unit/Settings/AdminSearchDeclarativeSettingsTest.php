<?php

namespace OCA\AIquila\Tests\Unit\Settings;

use OCA\AIquila\Settings\AdminSearchDeclarativeSettings;
use OCP\Settings\DeclarativeSettingsTypes;
use PHPUnit\Framework\TestCase;

class AdminSearchDeclarativeSettingsTest extends TestCase {
	private AdminSearchDeclarativeSettings $settings;

	protected function setUp(): void {
		$this->settings = new AdminSearchDeclarativeSettings();
	}

	public function testSchemaStructure(): void {
		$schema = $this->settings->getSchema();

		$this->assertSame('aiquila_admin_search', $schema['id']);
		$this->assertSame(DeclarativeSettingsTypes::SECTION_TYPE_ADMIN, $schema['section_type']);
		$this->assertSame('aiquila', $schema['section_id']);
		$this->assertSame(DeclarativeSettingsTypes::STORAGE_TYPE_INTERNAL, $schema['storage_type']);
		$this->assertNotEmpty($schema['title']);
		$this->assertIsArray($schema['fields']);
		$this->assertCount(1, $schema['fields']);
	}

	public function testSearchEnabledField(): void {
		$fields = $this->settings->getSchema()['fields'];
		$field = $this->findField($fields, 'search_enabled');

		$this->assertNotNull($field, 'search_enabled field should exist');
		$this->assertSame(DeclarativeSettingsTypes::CHECKBOX, $field['type']);
		$this->assertSame('1', $field['default']);
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
