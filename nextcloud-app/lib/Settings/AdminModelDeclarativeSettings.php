<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Settings;

use OCA\AIquila\Service\ClaudeModels;
use OCP\Settings\DeclarativeSettingsTypes;
use OCP\Settings\IDeclarativeSettingsForm;

class AdminModelDeclarativeSettings implements IDeclarativeSettingsForm {

	public function getSchema(): array {
		return [
			'id' => 'aiquila_admin_model',
			'priority' => 10,
			'section_type' => DeclarativeSettingsTypes::SECTION_TYPE_ADMIN,
			'section_id' => 'aiquila',
			'storage_type' => DeclarativeSettingsTypes::STORAGE_TYPE_INTERNAL,
			'title' => 'Default Claude model',
			'description' => 'Pick the model AIquila uses for every request, unless a user picks their own in their personal settings. Opus is the most capable (and most expensive); Haiku is the fastest and cheapest; Sonnet sits in the middle.',
			'fields' => [
				[
					'id' => 'model',
					'title' => 'Default model',
					'description' => 'Applied to every request from every user that has not set a personal preference. Takes effect immediately on save.',
					'type' => DeclarativeSettingsTypes::SELECT,
					'options' => ClaudeModels::getAllModels(),
					'default' => ClaudeModels::DEFAULT_MODEL,
				],
			],
		];
	}
}
