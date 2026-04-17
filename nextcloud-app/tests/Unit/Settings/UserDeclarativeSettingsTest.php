<?php

namespace OCA\AIquila\Tests\Unit\Settings;

use OCA\AIquila\Service\ClaudeModels;
use OCA\AIquila\Settings\UserDeclarativeSettings;
use OCP\Settings\DeclarativeSettingsTypes;
use PHPUnit\Framework\TestCase;

class UserDeclarativeSettingsTest extends TestCase {
	private UserDeclarativeSettings $settings;

	protected function setUp(): void {
		$this->settings = new UserDeclarativeSettings();
	}

	public function testSchemaStructure(): void {
		$schema = $this->settings->getSchema();

		$this->assertSame('aiquila_user', $schema['id']);
		$this->assertSame(DeclarativeSettingsTypes::SECTION_TYPE_PERSONAL, $schema['section_type']);
		$this->assertSame('aiquila', $schema['section_id']);
		$this->assertSame(DeclarativeSettingsTypes::STORAGE_TYPE_INTERNAL, $schema['storage_type']);
		$this->assertNotEmpty($schema['title']);
		$this->assertIsArray($schema['fields']);
	}

	public function testModelField(): void {
		$fields = $this->settings->getSchema()['fields'];
		$model = $this->findField($fields, 'user_model');

		$this->assertNotNull($model, 'model field should exist');
		$this->assertSame(DeclarativeSettingsTypes::SELECT, $model['type']);
		$this->assertSame('', $model['default']);

		// First option is the empty-string admin-default sentinel
		$this->assertSame('', $model['options'][0]);

		// Remaining options are all available models
		$this->assertSame(
			ClaudeModels::getAllModels(),
			array_values(array_slice($model['options'], 1))
		);
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
