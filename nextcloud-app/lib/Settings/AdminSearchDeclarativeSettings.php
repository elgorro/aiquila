<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Settings;

use OCP\Settings\DeclarativeSettingsTypes;
use OCP\Settings\IDeclarativeSettingsForm;

class AdminSearchDeclarativeSettings implements IDeclarativeSettingsForm {

	public function getSchema(): array {
		return [
			'id' => 'aiquila_admin_search',
			'priority' => 30,
			'section_type' => DeclarativeSettingsTypes::SECTION_TYPE_ADMIN,
			'section_id' => 'aiquila',
			'storage_type' => DeclarativeSettingsTypes::STORAGE_TYPE_INTERNAL,
			'title' => 'Search integration',
			'description' => 'Controls whether AIquila chat conversations appear in Nextcloud\'s unified search results.',
			'fields' => [
				[
					'id' => 'search_enabled',
					'title' => 'Show AIquila conversations in unified search',
					'description' => 'When enabled, past AIquila chats are indexed and can be found via the magnifying glass. Disable if you do not want chat content to appear in search.',
					'type' => DeclarativeSettingsTypes::CHECKBOX,
					'default' => '1',
				],
			],
		];
	}
}
