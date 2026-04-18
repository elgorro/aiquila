<?php

namespace OCA\AIquila\Tests\Unit\Settings;

use OCA\AIquila\Service\ClaudeModels;
use OCA\AIquila\Settings\AdminModelDeclarativeSettings;
use OCP\Settings\DeclarativeSettingsTypes;
use PHPUnit\Framework\TestCase;

class AdminModelDeclarativeSettingsTest extends TestCase {
	private AdminModelDeclarativeSettings $settings;

	protected function setUp(): void {
		$this->settings = new AdminModelDeclarativeSettings();
	}

	public function testSchemaStructure(): void {
		$schema = $this->settings->getSchema();

		$this->assertSame('aiquila_admin_model', $schema['id']);
		$this->assertSame(DeclarativeSettingsTypes::SECTION_TYPE_ADMIN, $schema['section_type']);
		$this->assertSame('aiquila', $schema['section_id']);
		$this->assertSame(DeclarativeSettingsTypes::STORAGE_TYPE_INTERNAL, $schema['storage_type']);
		$this->assertNotEmpty($schema['title']);
		$this->assertIsArray($schema['fields']);
		$this->assertCount(1, $schema['fields']);
	}

	public function testModelField(): void {
		$fields = $this->settings->getSchema()['fields'];
		$model = $this->findField($fields, 'model');

		$this->assertNotNull($model, 'model field should exist');
		$this->assertSame(DeclarativeSettingsTypes::SELECT, $model['type']);
		$this->assertSame(ClaudeModels::DEFAULT_MODEL, $model['default']);

		$this->assertSame(ClaudeModels::getAllModels(), $model['options']);
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
