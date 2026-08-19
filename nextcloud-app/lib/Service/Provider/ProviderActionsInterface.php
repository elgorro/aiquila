<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service\Provider;

/**
 * Optional companion to LLMProviderInterface for providers whose settings card
 * carries a button rather than only stored values (ProviderSettingsSchema::action()).
 *
 * Actions are admin-only and run through
 * ProviderSettingsController::adminAction(); a provider that does not implement
 * this interface simply has no actions.
 */
interface ProviderActionsInterface {
    /**
     * Run one action declared by this provider's schema.
     *
     * @param string $actionId The `id` of a TYPE_ACTION field.
     * @return array<string, mixed> Result rendered by the settings card. By
     *         convention: `message` (string) for a status line and `value`
     *         (string) for a secret to reveal.
     * @throws \InvalidArgumentException when the provider has no such action.
     */
    public function runAction(string $actionId): array;
}
