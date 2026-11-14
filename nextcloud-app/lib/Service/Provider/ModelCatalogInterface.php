<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service\Provider;

/**
 * A provider whose model listing carries capability metadata.
 *
 * Optional on top of LLMProviderInterface: a provider that implements it gets
 * per-model compatibility in the picker (incompatible models disabled, with the
 * reason) and a sentence on its card and in the chat when the model in use is
 * a problem. One that does not keeps the plain id list from listModels().
 */
interface ModelCatalogInterface {
    /**
     * The live model list with a verdict per model.
     *
     * Never throws: a failed listing comes back as ModelCatalog::failed() with
     * the provider's own rendering of the error, so the card can say why it
     * fell back to the built-in list.
     */
    public function fetchModelCatalog(?string $userId = null): ModelCatalog;
}
