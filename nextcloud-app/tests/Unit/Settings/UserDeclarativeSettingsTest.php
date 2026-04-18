<?php

namespace OCA\AIquila\Tests\Unit\Settings;

use OCA\AIquila\Service\ClaudeModels;
use OCA\AIquila\Settings\UserDeclarativeSettings;
use OCP\IConfig;
use OCP\Settings\DeclarativeSettingsTypes;
use PHPUnit\Framework\TestCase;

class UserDeclarativeSettingsTest extends TestCase {
	private UserDeclarativeSettings $settings;
	private IConfig $config;

	protected function setUp(): void {
		$this->config = $this->createMock(IConfig::class);
		$this->settings = new UserDeclarativeSettings($this->config);
	}

	public function testSchemaStructure(): void {
		$this->config->method('getAppValue')
			->willReturn(ClaudeModels::DEFAULT_MODEL);

		$schema = $this->settings->getSchema();

		$this->assertSame('aiquila_user', $schema['id']);
		$this->assertSame(DeclarativeSettingsTypes::SECTION_TYPE_PERSONAL, $schema['section_type']);
		$this->assertSame('aiquila', $schema['section_id']);
		$this->assertSame(DeclarativeSettingsTypes::STORAGE_TYPE_INTERNAL, $schema['storage_type']);
		$this->assertNotEmpty($schema['title']);
		$this->assertIsArray($schema['fields']);
	}

	public function testModelField(): void {
		$this->config->method('getAppValue')
			->willReturn(ClaudeModels::DEFAULT_MODEL);

		$fields = $this->settings->getSchema()['fields'];
		$model = $this->findField($fields, 'user_model');

		$this->assertNotNull($model, 'user_model field should exist');
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

	public function testDescriptionShowsCurrentAdminModel(): void {
		$this->config->expects($this->once())
			->method('getAppValue')
			->with('aiquila', 'model', ClaudeModels::DEFAULT_MODEL)
			->willReturn('claude-opus-4-7');

		$fields = $this->settings->getSchema()['fields'];
		$model = $this->findField($fields, 'user_model');

		$this->assertStringContainsString('claude-opus-4-7', $model['description']);
	}

	public function testDescriptionFallsBackToClaudeModelsDefault(): void {
		$this->config->method('getAppValue')
			->willReturnCallback(static fn ($app, $key, $default) => $default);

		$fields = $this->settings->getSchema()['fields'];
		$model = $this->findField($fields, 'user_model');

		$this->assertStringContainsString(ClaudeModels::DEFAULT_MODEL, $model['description']);
	}

	public function testDoesNotContainApiKey(): void {
		$this->config->method('getAppValue')
			->willReturn(ClaudeModels::DEFAULT_MODEL);

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
