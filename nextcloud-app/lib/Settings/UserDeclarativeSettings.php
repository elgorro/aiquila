<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Settings;

use OCA\AIquila\Service\ClaudeModels;
use OCP\IConfig;
use OCP\Settings\DeclarativeSettingsTypes;
use OCP\Settings\IDeclarativeSettingsForm;

class UserDeclarativeSettings implements IDeclarativeSettingsForm {

	public function __construct(
		private IConfig $config,
	) {
	}

	public function getSchema(): array {
		$adminModel = $this->config->getAppValue('aiquila', 'model', ClaudeModels::DEFAULT_MODEL);

		return [
			'id' => 'aiquila_user',
			'priority' => 20,
			'section_type' => DeclarativeSettingsTypes::SECTION_TYPE_PERSONAL,
			'section_id' => 'aiquila',
			'storage_type' => DeclarativeSettingsTypes::STORAGE_TYPE_INTERNAL,
			'title' => 'Claude model preference',
			'description' => 'Pick a specific Claude model for your own requests, or stick with whatever the admin has chosen.',
			'fields' => [
				[
					'id' => 'user_model',
					'title' => 'Preferred model',
					'description' => "Leave blank to use the instance default (currently: {$adminModel}). Pick a specific model to override the default for your requests only.",
					'type' => DeclarativeSettingsTypes::SELECT,
					'options' => array_merge([''], ClaudeModels::getAllModels()),
					'placeholder' => '(admin default)',
					'default' => '',
				],
			],
		];
	}
}
