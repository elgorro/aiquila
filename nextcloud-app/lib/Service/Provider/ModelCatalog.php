<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service\Provider;

/**
 * A provider's live model list with a compatibility verdict per model, or the
 * reason it could not be fetched.
 *
 * Built by ModelCatalogInterface::fetchModelCatalog(), cached by
 * ProviderSettingsService in toArray() form, and asked about a specific id with
 * assess() — which is how a saved, typed-in or conversation-pinned model that
 * is not in the list still gets a sentence of its own.
 */
final class ModelCatalog {
    /**
     * @param list<ModelCompatibility> $models
     * @param string $source       which listing answered, e.g. "ollama"; stable token for logs and docs
     * @param string $providerLabel provider name used in messages
     * @param string $error        user-facing reason the listing failed, '' on success
     * @param string $missingHint  what to do about an id that is not listed (pull it, load it, …);
     *                             `{model}` is replaced with the id
     */
    private function __construct(
        public readonly array $models,
        public readonly string $source,
        public readonly string $providerLabel,
        public readonly string $error,
        public readonly string $missingHint,
    ) {
    }

    /** @param list<ModelCompatibility> $models */
    public static function listed(array $models, string $source, string $providerLabel, string $missingHint = ''): self {
        usort($models, static fn(ModelCompatibility $a, ModelCompatibility $b) => strcmp($a->id, $b->id));
        return new self($models, $source, $providerLabel, '', $missingHint);
    }

    public static function failed(string $providerLabel, string $error): self {
        return new self([], '', $providerLabel, $error !== '' ? $error : 'the provider gave no reason.', '');
    }

    public function isFailure(): bool {
        return $this->error !== '';
    }

    /** "Couldn't load the model list …" for a failure, '' otherwise. */
    public function failureMessage(): string {
        return $this->isFailure() ? ModelCompatibility::listingFailedMessage($this->providerLabel, $this->error) : '';
    }

    /** @return list<string> */
    public function ids(): array {
        return array_map(static fn(ModelCompatibility $m) => $m->id, $this->models);
    }

    public function find(string $id): ?ModelCompatibility {
        foreach ($this->models as $model) {
            if ($model->id === $id) {
                return $model;
            }
        }
        return null;
    }

    /**
     * The verdict for $id: its listed entry, or "missing" when the listing
     * succeeded without it. A failed listing knows nothing, so it returns null
     * rather than calling every model missing.
     */
    public function assess(string $id): ?ModelCompatibility {
        if ($id === '' || $this->isFailure()) {
            return null;
        }
        return $this->find($id) ?? ModelCompatibility::missing($id, $this->providerLabel, str_replace('{model}', $id, $this->missingHint));
    }

    /**
     * @return array{source: string, providerLabel: string, error: string, missingHint: string, models: list<array<string, mixed>>}
     */
    public function toArray(): array {
        return [
            'source' => $this->source,
            'providerLabel' => $this->providerLabel,
            'error' => $this->error,
            'missingHint' => $this->missingHint,
            'models' => array_map(static fn(ModelCompatibility $m) => $m->toArray(), $this->models),
        ];
    }

    /** @param mixed $data */
    public static function fromArray($data): ?self {
        if (!is_array($data)
            || !is_string($data['source'] ?? null)
            || !is_string($data['providerLabel'] ?? null)
            || !is_string($data['error'] ?? null)
            || !is_array($data['models'] ?? null)
        ) {
            return null;
        }
        $models = [];
        foreach ($data['models'] as $entry) {
            $model = ModelCompatibility::fromArray($entry);
            if ($model === null) {
                return null;
            }
            $models[] = $model;
        }
        return new self(
            $models,
            $data['source'],
            $data['providerLabel'],
            $data['error'],
            is_string($data['missingHint'] ?? null) ? $data['missingHint'] : '',
        );
    }
}
