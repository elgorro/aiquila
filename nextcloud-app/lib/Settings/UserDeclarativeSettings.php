<?php

declare(strict_types=1);

namespace OCA\AIquila\Settings;

use OCA\AIquila\Service\ClaudeModels;
use OCP\Settings\DeclarativeSettingsTypes;
use OCP\Settings\IDeclarativeSettingsForm;

class UserDeclarativeSettings implements IDeclarativeSettingsForm {

	public function getSchema(): array {
		$modelOptions = [['name' => '(admin default)', 'value' => '']];
		foreach (ClaudeModels::getAllModels() as $id) {
			$modelOptions[] = ['name' => $id, 'value' => $id];
		}

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
					'id' => 'model',
					'title' => 'Preferred Model',
					'description' => 'Leave on "(admin default)" to use the instance-wide model.',
					'type' => DeclarativeSettingsTypes::SELECT,
					'options' => $modelOptions,
					'default' => '',
				],
			],
		];
	}
}
