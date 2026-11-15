<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service;

use OCA\AIquila\Db\Sequence;
use OCA\AIquila\Db\SequenceMapper;
use OCA\AIquila\Service\Exception\SequencePolicyException;
use OCP\DB\Exception as DbException;

/**
 * Stores sequences and builds the view the MCP server reads.
 *
 * Organization (`instance`) sequences are written by admins and read by
 * everyone; personal (`user`) ones by their owner only. The MCP server runs
 * either kind as the calling user, never as the admin who wrote it.
 *
 * @psalm-import-type AIquilaSequencePolicy from \OCA\AIquila\ResponseDefinitions
 * @psalm-import-type AIquilaSequenceBuiltin from \OCA\AIquila\ResponseDefinitions
 * @psalm-import-type AIquilaEffectiveSequence from \OCA\AIquila\ResponseDefinitions
 * @psalm-type AIquilaSequenceFields = array{slug?: ?string, title?: ?string, description?: ?string, definition?: ?array<array-key, mixed>, pinned?: ?bool, enabled?: ?bool}
 */
class SequenceService {
    public function __construct(
        private SequenceMapper $mapper,
        private SequenceValidator $validator,
        private SequencePolicy $policy,
        private SequenceCatalog $catalog,
    ) {
    }

    /**
     * @param AIquilaSequenceFields $fields
     * @throws \InvalidArgumentException a field is invalid, or a limit is reached
     * @throws SequencePolicyException personal sequences are switched off
     * @throws DbException
     */
    public function create(string $scope, string $userId, array $fields): Sequence {
        $sequence = new Sequence();
        $sequence->setScope($scope);
        $sequence->setUserId($scope === Sequence::SCOPE_USER ? $userId : '');
        $sequence->setAuthorId($userId);
        $now = time();
        $sequence->setCreatedAt($now);
        if (!is_string($fields['slug'] ?? null) || !is_string($fields['title'] ?? null) || !is_array($fields['definition'] ?? null)) {
            throw new \InvalidArgumentException('A tool name, a title and a definition are required');
        }
        if ($scope === Sequence::SCOPE_USER) {
            $this->assertPersonalAllowed();
            if (count($this->mapper->findAllByUser($userId)) >= $this->policy->get()['maxPerUser']) {
                throw new \InvalidArgumentException('You have reached the maximum number of sequences');
            }
        }
        $this->apply($sequence, $fields, $now);
        try {
            return $this->mapper->insert($sequence);
        } catch (DbException $e) {
            if ($e->getReason() === DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                throw new \InvalidArgumentException('A sequence with this tool name already exists');
            }
            throw $e;
        }
    }

    /**
     * @param AIquilaSequenceFields $fields null fields stay as they are
     * @throws \InvalidArgumentException
     * @throws SequencePolicyException
     * @throws DbException
     */
    public function update(Sequence $sequence, array $fields): Sequence {
        if ($sequence->getScope() === Sequence::SCOPE_USER) {
            $this->assertPersonalAllowed();
        }
        $this->apply($sequence, $fields, time());
        try {
            return $this->mapper->update($sequence);
        } catch (DbException $e) {
            if ($e->getReason() === DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                throw new \InvalidArgumentException('A sequence with this tool name already exists');
            }
            throw $e;
        }
    }

    /**
     * @param AIquilaSequenceFields $fields
     */
    private function apply(Sequence $sequence, array $fields, int $now): void {
        $scope = $sequence->getScope();
        $slug = $fields['slug'] ?? null;
        $title = isset($fields['title']) ? trim($fields['title']) : null;
        $description = $fields['description'] ?? null;
        $this->validator->validateText($title, $description);
        if ($slug !== null) {
            $this->validator->validateSlug($slug, $scope);
            $sequence->setSlug($slug);
        }
        if ($title !== null) {
            $sequence->setTitle($title);
        }
        if ($description !== null) {
            $description = trim($description);
            $sequence->setDescription($description === '' ? null : $description);
        }
        $definition = $fields['definition'] ?? null;
        if ($definition !== null) {
            $sequence->setDefinitionArray($this->validator->validateDefinition($definition, $this->policy->get()['maxSteps']));
        }
        $enabled = $fields['enabled'] ?? null;
        if ($enabled !== null) {
            $sequence->setEnabled($enabled ? 1 : 0);
        }
        $pin = $fields['pinned'] ?? null;
        if ($pin !== null) {
            if ($pin && $scope === Sequence::SCOPE_USER && $sequence->getPinned() !== 1) {
                $pinned = count(array_filter(
                    $this->mapper->findAllByUser($sequence->getUserId()),
                    static fn (Sequence $s): bool => $s->getPinned() === 1 && $s->getId() !== $sequence->getId(),
                ));
                if ($pinned >= $this->policy->get()['maxPinned']) {
                    throw new \InvalidArgumentException('You have reached the maximum number of sequences shown as their own tool');
                }
            }
            $sequence->setPinned($pin ? 1 : 0);
        }
        $sequence->setUpdatedAt($now);
    }

    /**
     * @throws SequencePolicyException
     */
    private function assertPersonalAllowed(): void {
        if (!$this->policy->get()['allowPersonal']) {
            throw new SequencePolicyException('Your administrator does not allow personal sequences');
        }
    }

    /**
     * The built-ins with their state for `userId`.
     *
     * @return list<AIquilaSequenceBuiltin>
     */
    public function builtinsFor(?string $userId): array {
        $admin = $this->policy->disabledBuiltins();
        $mine = $userId === null ? [] : $this->policy->userDisabledBuiltins($userId);
        return array_map(static fn (array $b): array => [
            'slug' => $b['slug'],
            'title' => (string)$b['title'],
            'description' => (string)$b['description'],
            'adminEnabled' => !in_array($b['slug'], $admin, true),
            'enabled' => !in_array($b['slug'], $admin, true) && !in_array($b['slug'], $mine, true),
        ], $this->catalog->builtins());
    }

    /**
     * What the MCP server lists and runs for `userId`: the organization's
     * enabled sequences, the user's own enabled ones (if allowed, up to the
     * maximum), the built-ins switched off, and the policy.
     *
     * @return array{sequences: list<AIquilaEffectiveSequence>, disabledBuiltins: list<string>, policy: AIquilaSequencePolicy}
     */
    public function effective(string $userId): array {
        $policy = $this->policy->get();
        $rows = array_values(array_filter($this->mapper->findAllInstance(), static fn (Sequence $s): bool => $s->getEnabled() === 1));
        if ($policy['allowPersonal']) {
            $mine = array_values(array_filter($this->mapper->findAllByUser($userId), static fn (Sequence $s): bool => $s->getEnabled() === 1));
            $rows = array_merge($rows, array_slice($mine, 0, $policy['maxPerUser']));
        }
        $disabled = array_values(array_unique(array_merge($this->policy->disabledBuiltins(), $this->policy->userDisabledBuiltins($userId))));
        sort($disabled);
        return [
            'sequences' => array_map(static function (Sequence $s): array {
                $data = $s->jsonSerialize();
                return [
                    'scope' => $data['scope'],
                    'slug' => $data['slug'],
                    'title' => $data['title'],
                    'description' => $data['description'],
                    'definition' => $data['definition'],
                    'pinned' => $data['pinned'],
                ];
            }, $rows),
            'disabledBuiltins' => $disabled,
            'policy' => $policy,
        ];
    }

    /**
     * The opaque tag of a strong entity tag for a body (RFC 9110 §8.8.3),
     * from its JSON. Response::setETag() adds the quotes.
     */
    public static function etag(array $body): string {
        return substr(hash('sha256', json_encode($body, JSON_THROW_ON_ERROR)), 0, 32);
    }

    /**
     * Whether an If-None-Match field value matches `etag` (RFC 9110 §13.1.2:
     * `*`, or any listed tag by weak comparison).
     */
    public static function noneMatch(string $header, string $etag): bool {
        $etag = '"' . $etag . '"';
        $header = trim($header);
        if ($header === '') {
            return false;
        }
        if ($header === '*') {
            return true;
        }
        $opaque = static fn (string $tag): string => str_starts_with($tag, 'W/') ? substr($tag, 2) : $tag;
        foreach (explode(',', $header) as $tag) {
            if ($opaque(trim($tag)) === $opaque($etag)) {
                return true;
            }
        }
        return false;
    }
}
