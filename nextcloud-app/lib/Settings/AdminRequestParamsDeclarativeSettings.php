<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Settings;

use OCA\AIquila\Service\ClaudeModels;
use OCP\Settings\DeclarativeSettingsTypes;
use OCP\Settings\IDeclarativeSettingsForm;

class AdminRequestParamsDeclarativeSettings implements IDeclarativeSettingsForm {

	public function getSchema(): array {
		return [
			'id' => 'aiquila_admin_params',
			'priority' => 20,
			'section_type' => DeclarativeSettingsTypes::SECTION_TYPE_ADMIN,
			'section_id' => 'aiquila',
			'storage_type' => DeclarativeSettingsTypes::STORAGE_TYPE_INTERNAL,
			'title' => 'Request parameters',
			'description' => 'Tune how AIquila talks to the Claude API. The defaults work well — only change these if you understand the tradeoff.',
			'fields' => [
				[
					'id' => 'max_tokens',
					'title' => 'Max response tokens',
					'description' => 'Upper bound on how long a single reply can be. Higher = longer answers at higher cost and latency. Automatically clamped to the selected model\'s ceiling (64K for Sonnet 4.6, 128K for Opus 4.x and Fable 5). Range 1–128 000.',
					'type' => DeclarativeSettingsTypes::TEXT,
					'placeholder' => '16384',
					'default' => (string)ClaudeModels::DEFAULT_MAX_TOKENS,
				],
				[
					'id' => 'api_timeout',
					'title' => 'API timeout (seconds)',
					'description' => 'How long to wait for Claude\'s reply before giving up. Chat replies are streamed and not affected; this mainly applies to non-streaming calls (connection test, background tasks). Range 10–1 800; 30–60 seconds is typical.',
					'type' => DeclarativeSettingsTypes::TEXT,
					'placeholder' => '30',
					'default' => '30',
				],
			],
		];
	}
}
