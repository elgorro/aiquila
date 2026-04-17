<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Settings;

use OCA\AIquila\Service\ClaudeModels;
use OCP\Settings\DeclarativeSettingsTypes;
use OCP\Settings\IDeclarativeSettingsForm;

class UserDeclarativeSettings implements IDeclarativeSettingsForm {

	public function getSchema(): array {
		return [
			'id' => 'aiquila_user',
			'priority' => 20,
			'section_type' => DeclarativeSettingsTypes::SECTION_TYPE_PERSONAL,
			'section_id' => 'aiquila',
			'storage_type' => DeclarativeSettingsTypes::STORAGE_TYPE_INTERNAL,
			'title' => 'Claude Model Preference',
			'description' => 'Override the default Claude model for your requests.',
			'fields' => [
				[
					'id' => 'user_model',
					'title' => 'Preferred Model',
					'description' => 'Leave on the blank entry to use the instance-wide model.',
					'type' => DeclarativeSettingsTypes::SELECT,
					'options' => array_merge([''], ClaudeModels::getAllModels()),
					'placeholder' => '(admin default)',
					'default' => '',
				],
			],
		];
	}
}
