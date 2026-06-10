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
				[
					'id' => 'effort',
					'title' => 'Default effort level',
					'description' => 'How much work Claude puts into each response. "Model default" picks a sensible level per model. "xhigh" is only accepted by Fable 5 and Opus 4.7+; on other models the value falls back to the model default. Users can override this per conversation with /effort.',
					'type' => DeclarativeSettingsTypes::SELECT,
					'options' => array_merge([''], ClaudeModels::ALL_EFFORTS),
					'default' => '',
				],
				[
					'id' => 'thinking',
					'title' => 'Enable adaptive thinking by default',
					'description' => 'Lets Claude reason before answering on models that support it. Off by default; users can override this per conversation with /thinking.',
					'type' => DeclarativeSettingsTypes::CHECKBOX,
					'default' => false,
				],
			],
		];
	}
}
