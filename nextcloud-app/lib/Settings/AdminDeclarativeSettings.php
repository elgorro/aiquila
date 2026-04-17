<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Settings;

use OCA\AIquila\Service\ClaudeModels;
use OCP\Settings\DeclarativeSettingsTypes;
use OCP\Settings\IDeclarativeSettingsForm;

class AdminDeclarativeSettings implements IDeclarativeSettingsForm {

	public function getSchema(): array {
		return [
			'id' => 'aiquila_admin',
			'priority' => 20,
			'section_type' => DeclarativeSettingsTypes::SECTION_TYPE_ADMIN,
			'section_id' => 'aiquila',
			'storage_type' => DeclarativeSettingsTypes::STORAGE_TYPE_INTERNAL,
			'title' => 'Claude Model Settings',
			'description' => 'Configure the default Claude model and generation parameters.',
			'fields' => [
				[
					'id' => 'model',
					'title' => 'Default Claude Model',
					'description' => 'The Claude model used for all requests unless overridden by a user.',
					'type' => DeclarativeSettingsTypes::SELECT,
					'options' => ClaudeModels::getAllModels(),
					'default' => ClaudeModels::DEFAULT_MODEL,
				],
				[
					'id' => 'max_tokens',
					'title' => 'Max Response Tokens',
					'description' => 'Maximum number of output tokens per response (1–128 000).',
					'type' => DeclarativeSettingsTypes::TEXT,
					'placeholder' => '16384',
					'default' => (string)ClaudeModels::DEFAULT_MAX_TOKENS,
				],
				[
					'id' => 'api_timeout',
					'title' => 'API Timeout (seconds)',
					'description' => 'HTTP timeout for Claude API requests (10–1 800).',
					'type' => DeclarativeSettingsTypes::TEXT,
					'placeholder' => '30',
					'default' => '30',
				],
			],
		];
	}
}
