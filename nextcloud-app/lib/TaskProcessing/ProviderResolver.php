<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\TaskProcessing;

use OCA\AIquila\Service\Provider\LLMProviderFactory;
use OCA\AIquila\Service\Provider\LLMProviderInterface;
use OCA\AIquila\Service\Provider\NoPermittedProviderException;

/**
 * Picks the LLM provider that should serve a TaskProcessing run.
 *
 * A task carries no conversation, so there is no per-conversation pin to read:
 * resolution is the user's own provider choice, then the instance default, as
 * LLMProviderFactory defines it. Going through the factory is also what applies
 * the admin's per-user and per-group access rules — a provider reached directly
 * would bypass them.
 *
 * The framework expects process() to fail with a RuntimeException, so the two
 * things that can go wrong here — no permitted provider at all, and a provider
 * that cannot see images — are translated into one, with a message that tells
 * the user what to switch to.
 */
class ProviderResolver {

    public function __construct(
        private LLMProviderFactory $providerFactory,
    ) {
    }

    /**
     * An optional `provider` task input, if one was supplied.
     *
     * The id is not trusted: resolve() hands it to the factory, which honours it
     * only when the user is actually allowed to use that provider.
     *
     * @param array<string, mixed> $input
     */
    public function requestedId(array $input): ?string {
        $requested = $input['provider'] ?? null;
        return is_string($requested) && $requested !== '' ? $requested : null;
    }

    /**
     * @throws \RuntimeException when no provider is available to this user
     */
    public function resolve(?string $userId, ?string $requestedId = null): LLMProviderInterface {
        try {
            return $this->providerFactory->getProviderForUser($userId, $requestedId);
        } catch (NoPermittedProviderException $e) {
            throw new \RuntimeException(NoPermittedProviderException::USER_MESSAGE, 0, $e);
        }
    }

    /**
     * Same as resolve(), but for tasks that send images.
     *
     * Vision is not a static property of a provider — Hetzner derives it from the
     * selected model and the local provider from an admin flag — so this has to be
     * asked per run rather than at registration time. A provider without it is an
     * error rather than a silent hand-off to some other provider: the point of
     * picking a local model is that the image does not leave the server.
     *
     * @throws \RuntimeException when the resolved provider cannot process images
     */
    public function resolveVisionCapable(?string $userId, ?string $requestedId = null): LLMProviderInterface {
        $provider = $this->resolve($userId, $requestedId);
        if ($provider->getCapabilities()['vision']) {
            return $provider;
        }

        $alternatives = $this->visionCapableLabels($userId);
        throw new \RuntimeException(
            $alternatives === []
                ? $provider->getLabel() . ' cannot process images, and no vision-capable AI provider is available for your account. Ask your administrator.'
                : $provider->getLabel() . ' cannot process images. Pick a vision-capable provider in your AIquila settings — available: ' . implode(', ', $alternatives) . '.'
        );
    }

    /**
     * Labels of the configured, vision-capable providers this user may use.
     *
     * @return list<string>
     */
    private function visionCapableLabels(?string $userId): array {
        $labels = [];
        foreach ($this->providerFactory->getProviderIdsForUser($userId) as $id) {
            $candidate = $this->providerFactory->getProviderById($id);
            if ($candidate->getCapabilities()['vision'] && $candidate->isConfigured($userId)) {
                $labels[] = $candidate->getLabel();
            }
        }
        return $labels;
    }
}
