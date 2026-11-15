<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service\Provider\Cloud;

use OCA\AIquila\Service\Provider\ModelCatalog;
use OCA\AIquila\Service\Provider\ModelCompatibility;

/**
 * Turns the Bedrock control plane's listings into a ModelCatalog.
 *
 * Sources, both on `bedrock.{region}.amazonaws.com`:
 *   - ListFoundationModels (`GET /foundation-models?byProvider=Anthropic&byOutputModality=TEXT`):
 *     `modelId`, `modelName`, `inputModalities`, `inferenceTypesSupported`,
 *     `responseStreamingSupported` and `modelLifecycle` (`ACTIVE` / `LEGACY`,
 *     with optional `endOfLifeTime`). Not paginated.
 *     https://docs.aws.amazon.com/bedrock/latest/APIReference/API_ListFoundationModels.html
 *   - ListInferenceProfiles (`GET /inference-profiles?type=…`), paginated by
 *     `nextToken`: `inferenceProfileId`, `inferenceProfileArn`,
 *     `inferenceProfileName`, `models[].modelArn`, `status`, `type`.
 *     https://docs.aws.amazon.com/bedrock/latest/APIReference/API_ListInferenceProfiles.html
 *
 * A model whose `inferenceTypesSupported` lacks `ON_DEMAND` cannot be called
 * by its bare id; its profiles are offered instead and the bare id says so.
 * Every verdict comes from these fields — nothing is read from the id.
 */
final class BedrockModelCatalog {
    public const SOURCE = 'bedrock-control-plane';

    /**
     * @param list<array<array-key, mixed>> $models   ListFoundationModels `modelSummaries`
     * @param list<array<array-key, mixed>>|null $profiles ListInferenceProfiles summaries, all
     *                                            types; null when they could not be listed
     * @param bool $streams whether the provider streams chat, so a model
     *                      without `responseStreamingSupported` gets a note
     */
    public static function build(array $models, ?array $profiles, string $providerLabel, bool $streams, \DateTimeImmutable $now): ModelCatalog {
        $byArn = [];
        foreach ($models as $model) {
            if (is_string($model['modelArn'] ?? null)) {
                $byArn[$model['modelArn']] = $model;
            }
        }
        $profilesFor = self::profilesByModelArn($profiles ?? [], $byArn);

        $listed = [];
        $retired = [];
        foreach ($models as $model) {
            $id = is_string($model['modelId'] ?? null) ? $model['modelId'] : '';
            if ($id === '' || !self::hasText($model['outputModalities'] ?? null)) {
                continue;
            }
            $label = is_string($model['modelName'] ?? null) ? $model['modelName'] : '';
            $arn = is_string($model['modelArn'] ?? null) ? $model['modelArn'] : '';
            $verdict = self::assess($id, $label, $model, $streams, $now);

            if ($verdict->status === ModelCompatibility::STATUS_RETIRED) {
                $retired[] = $verdict;
                continue;
            }
            $profileIds = array_keys($profilesFor[$arn] ?? []);
            if ($verdict->isSelectable() && !self::onDemand($model)) {
                $verdict = ModelCompatibility::unavailable(
                    $id,
                    $label,
                    'can\'t be called by its model ID on Bedrock: it is served only through an inference profile.',
                    $profileIds,
                    $profiles === null ? 'Its inference profiles could not be listed with these credentials (bedrock:ListInferenceProfiles); use the profile ID for your geography from the Bedrock console.' : '',
                );
            }
            $listed[] = $verdict;

            // Each profile is the same model reached through other Regions.
            foreach ($profilesFor[$arn] ?? [] as $profileId => $profileLabel) {
                $listed[] = self::assess($profileId, $profileLabel, $model, $streams, $now);
            }
        }

        return ModelCatalog::listed(
            self::unique($listed),
            self::SOURCE,
            $providerLabel,
            'Check the model or inference profile ID, and that it is offered in this Region.',
            $retired,
        );
    }

    /** @param array<array-key, mixed> $model */
    private static function assess(string $id, string $label, array $model, bool $streams, \DateTimeImmutable $now): ModelCompatibility {
        $lifecycle = is_array($model['modelLifecycle'] ?? null) ? $model['modelLifecycle'] : [];
        $endOfLife = self::date($lifecycle['endOfLifeTime'] ?? null);
        if ($endOfLife !== null && $endOfLife <= $now) {
            return ModelCompatibility::retired($id, $label, $endOfLife->format('Y-m-d'));
        }
        if (($lifecycle['status'] ?? null) === 'LEGACY') {
            return ModelCompatibility::deprecated($id, $label, $endOfLife?->format('Y-m-d') ?? '');
        }
        $inputs = is_array($model['inputModalities'] ?? null) ? $model['inputModalities'] : [];
        $note = $streams && ($model['responseStreamingSupported'] ?? true) === false
            ? sprintf('Bedrock reports that %s can\'t stream responses, so streamed chat with it fails.', $id)
            : '';
        // Every Claude model on Bedrock takes tools; there is no flag for it.
        return ModelCompatibility::chat($id, $label, true, in_array('IMAGE', $inputs, true), null, $note);
    }

    /**
     * Profiles per foundation model ARN, active Anthropic ones only. A
     * system-defined profile is called by its id (`eu.anthropic.…`); an
     * application profile only by its ARN.
     *
     * @param list<array<array-key, mixed>> $profiles
     * @param array<string, array<array-key, mixed>> $byArn
     * @return array<string, array<string, string>> model ARN → [profile id → name]
     */
    private static function profilesByModelArn(array $profiles, array $byArn): array {
        $out = [];
        foreach ($profiles as $profile) {
            if (($profile['status'] ?? 'ACTIVE') !== 'ACTIVE') {
                continue;
            }
            $id = ($profile['type'] ?? null) === 'APPLICATION'
                ? ($profile['inferenceProfileArn'] ?? null)
                : ($profile['inferenceProfileId'] ?? null);
            if (!is_string($id) || $id === '') {
                continue;
            }
            $name = is_string($profile['inferenceProfileName'] ?? null) ? $profile['inferenceProfileName'] : '';
            foreach (is_array($profile['models'] ?? null) ? $profile['models'] : [] as $member) {
                $memberArn = is_array($member) && is_string($member['modelArn'] ?? null) ? $member['modelArn'] : '';
                $target = self::foundationArn($memberArn, $byArn);
                if ($target !== null) {
                    $out[$target][$id] = $name;
                }
            }
        }
        return $out;
    }

    /**
     * A profile lists the model's ARN in each Region it routes to; only the
     * part after the Region identifies the model, so match on that.
     *
     * @param array<string, array<array-key, mixed>> $byArn
     */
    private static function foundationArn(string $memberArn, array $byArn): ?string {
        if (isset($byArn[$memberArn])) {
            return $memberArn;
        }
        $suffix = strstr($memberArn, ':foundation-model/');
        if ($suffix === false) {
            return null;
        }
        foreach (array_keys($byArn) as $arn) {
            if (str_ends_with($arn, $suffix)) {
                return $arn;
            }
        }
        return null;
    }

    /** @param array<array-key, mixed> $model */
    private static function onDemand(array $model): bool {
        $types = $model['inferenceTypesSupported'] ?? null;
        // Absent means the summary does not say; offer it rather than hide it.
        return !is_array($types) || in_array('ON_DEMAND', $types, true);
    }

    private static function hasText(mixed $modalities): bool {
        return !is_array($modalities) || in_array('TEXT', $modalities, true);
    }

    /** AWS timestamps arrive as ISO 8601 strings or epoch seconds. */
    private static function date(mixed $value): ?\DateTimeImmutable {
        if (is_int($value) || is_float($value)) {
            return (new \DateTimeImmutable('@' . (int)$value));
        }
        if (!is_string($value) || $value === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * A profile can reach several listed models; keep its first verdict.
     *
     * @param list<ModelCompatibility> $models
     * @return list<ModelCompatibility>
     */
    private static function unique(array $models): array {
        $seen = [];
        foreach ($models as $model) {
            $seen[$model->id] ??= $model;
        }
        return array_values($seen);
    }
}
