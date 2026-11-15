<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service;

use OCA\AIquila\Db\ProjectTemplate;
use OCA\AIquila\Db\ProjectTemplateMapper;
use OCA\AIquila\Db\Prompt;
use OCA\AIquila\Db\PromptMapper;
use OCA\AIquila\Db\Sequence;
use OCA\AIquila\Db\SequenceMapper;

/**
 * Exports and imports the instance library as JSON, so admins running several
 * instances can keep them in step: instance instructions and their scopes,
 * organization prompts, project templates, coworker templates, and
 * organization sequences with the sequence policy. Users' own entries are
 * never exported or touched.
 *
 * Import upserts by title (prompts, project templates), by id (coworker
 * templates) and by slug (sequences). With `replace`, the existing instance
 * entries are deleted first. `sequences` and `sequencePolicy` are optional,
 * so files exported before sequences existed still import.
 */
class LibraryTransfer {
    public const FORMAT = 'aiquila-library';
    public const VERSION = 1;

    public function __construct(
        private InstructionsService $instructions,
        private PromptMapper $prompts,
        private ProjectTemplateMapper $templates,
        private CoworkerTemplates $coworkerTemplates,
        private SequenceMapper $sequences,
        private SequenceValidator $sequenceValidator,
        private SequencePolicy $sequencePolicy,
    ) {
    }

    /**
     * @return array{format: string, version: int, instructions: array{text: string, scopes: array<string, bool>}, prompts: list<array{title: string, description: ?string, content: string}>, projectTemplates: list<array{title: string, description: ?string, systemPrompt: ?string, paths: list<array{path: string, pathType: string}>}>, coworkerTemplates: list<array<string, mixed>>, sequences: list<array{slug: string, title: string, description: ?string, definition: array<string, mixed>, pinned: bool, enabled: bool}>, sequencePolicy: array{allowPersonal: bool, maxPerUser: int, maxSteps: int, maxPinned: int, disabledBuiltins: list<string>}}
     */
    public function export(): array {
        return [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'instructions' => [
                'text' => $this->instructions->getInstanceInstructions(),
                'scopes' => $this->instructions->getScopes(),
            ],
            'prompts' => array_map(static fn (Prompt $p): array => [
                'title' => $p->getTitle(),
                'description' => $p->getDescription(),
                'content' => $p->getContent(),
            ], $this->prompts->findAllInstance()),
            'projectTemplates' => array_map(static fn (ProjectTemplate $t): array => [
                'title' => $t->getTitle(),
                'description' => $t->getDescription(),
                'systemPrompt' => $t->getSystemPrompt(),
                'paths' => $t->getPathList(),
            ], $this->templates->findAllInstance()),
            'coworkerTemplates' => array_map(static function (array $t): array {
                unset($t['scope']);
                return $t;
            }, $this->coworkerTemplates->list()),
            'sequences' => array_map(static fn (Sequence $s): array => [
                'slug' => $s->getSlug(),
                'title' => $s->getTitle(),
                'description' => $s->getDescription(),
                'definition' => $s->getDefinitionArray(),
                'pinned' => $s->getPinned() === 1,
                'enabled' => $s->getEnabled() === 1,
            ], $this->sequences->findAllInstance()),
            'sequencePolicy' => $this->sequencePolicy->get() + ['disabledBuiltins' => $this->sequencePolicy->disabledBuiltins()],
        ];
    }

    /**
     * @param array<array-key, mixed> $data a decoded export
     * @param string $author user id recorded as the author of imported rows
     * @return array{instructions: bool, prompts: int, projectTemplates: int, coworkerTemplates: int, sequences: int}
     * @throws \InvalidArgumentException when $data is not an export, or an entry is invalid
     */
    public function import(array $data, string $author, bool $replace = false): array {
        if (($data['format'] ?? null) !== self::FORMAT || ($data['version'] ?? null) !== self::VERSION) {
            throw new \InvalidArgumentException('Not an AIquila library export (format ' . self::FORMAT . ', version ' . self::VERSION . ')');
        }
        $prompts = $this->listOf($data, 'prompts');
        $templates = $this->listOf($data, 'projectTemplates');
        $coworkers = $this->listOf($data, 'coworkerTemplates');
        $sequences = $this->listOf($data, 'sequences');
        $this->validate($prompts, $templates);
        $policy = is_array($data['sequencePolicy'] ?? null) ? $data['sequencePolicy'] : null;
        if ($policy !== null) {
            $this->sequencePolicy->validate(
                is_int($policy['maxPerUser'] ?? null) ? $policy['maxPerUser'] : null,
                is_int($policy['maxSteps'] ?? null) ? $policy['maxSteps'] : null,
                is_int($policy['maxPinned'] ?? null) ? $policy['maxPinned'] : null,
            );
        }
        $normalized = $this->validateSequences($sequences, $policy);

        if ($replace) {
            foreach ($this->prompts->findAllInstance() as $p) {
                $this->prompts->delete($p);
            }
            foreach ($this->templates->findAllInstance() as $t) {
                $this->templates->delete($t);
            }
            $this->coworkerTemplates->clear();
            foreach ($this->sequences->findAllInstance() as $sequence) {
                $this->sequences->delete($sequence);
            }
        }

        $instructions = false;
        if (is_array($data['instructions'] ?? null)) {
            $text = $data['instructions']['text'] ?? null;
            if (is_string($text)) {
                $this->instructions->setInstanceInstructions($text);
                $instructions = true;
            }
            if (is_array($data['instructions']['scopes'] ?? null)) {
                $scopes = [];
                foreach ($data['instructions']['scopes'] as $scope => $on) {
                    $scopes[(string)$scope] = (bool)$on;
                }
                $this->instructions->setScopes($scopes);
                $instructions = true;
            }
        }

        $now = time();
        $existingPrompts = [];
        foreach ($this->prompts->findAllInstance() as $p) {
            $existingPrompts[$p->getTitle()] = $p;
        }
        foreach ($prompts as $entry) {
            $title = trim((string)$entry['title']);
            $prompt = $existingPrompts[$title] ?? null;
            $isNew = $prompt === null;
            if ($prompt === null) {
                $prompt = new Prompt();
                $prompt->setScope(Prompt::SCOPE_INSTANCE);
                $prompt->setUserId($author);
                $prompt->setTitle($title);
                $prompt->setCreatedAt($now);
            }
            $prompt->setDescription(self::nullableString($entry['description'] ?? null));
            $prompt->setContent((string)$entry['content']);
            $prompt->setUpdatedAt($now);
            $isNew ? $this->prompts->insert($prompt) : $this->prompts->update($prompt);
        }

        $existingTemplates = [];
        foreach ($this->templates->findAllInstance() as $t) {
            $existingTemplates[$t->getTitle()] = $t;
        }
        foreach ($templates as $entry) {
            $title = trim((string)$entry['title']);
            $template = $existingTemplates[$title] ?? null;
            $isNew = $template === null;
            if ($template === null) {
                $template = new ProjectTemplate();
                $template->setScope(Prompt::SCOPE_INSTANCE);
                $template->setUserId($author);
                $template->setTitle($title);
                $template->setCreatedAt($now);
            }
            $template->setDescription(self::nullableString($entry['description'] ?? null));
            $template->setSystemPrompt(self::nullableString($entry['systemPrompt'] ?? null));
            $template->setPathList(is_array($entry['paths'] ?? null) ? $entry['paths'] : []);
            $template->setUpdatedAt($now);
            $isNew ? $this->templates->insert($template) : $this->templates->update($template);
        }

        $this->coworkerTemplates->import($coworkers);

        if ($policy !== null) {
            $this->sequencePolicy->set(
                is_bool($policy['allowPersonal'] ?? null) ? $policy['allowPersonal'] : null,
                is_int($policy['maxPerUser'] ?? null) ? $policy['maxPerUser'] : null,
                is_int($policy['maxSteps'] ?? null) ? $policy['maxSteps'] : null,
                is_int($policy['maxPinned'] ?? null) ? $policy['maxPinned'] : null,
            );
            if (is_array($policy['disabledBuiltins'] ?? null)) {
                $this->sequencePolicy->setDisabledBuiltins(array_values($policy['disabledBuiltins']));
            }
        }

        $existingSequences = [];
        foreach ($this->sequences->findAllInstance() as $sequence) {
            $existingSequences[$sequence->getSlug()] = $sequence;
        }
        foreach ($normalized as $entry) {
            $sequence = $existingSequences[$entry['slug']] ?? null;
            $isNew = $sequence === null;
            if ($sequence === null) {
                $sequence = new Sequence();
                $sequence->setScope(Sequence::SCOPE_INSTANCE);
                $sequence->setUserId('');
                $sequence->setAuthorId($author);
                $sequence->setSlug($entry['slug']);
                $sequence->setCreatedAt($now);
            }
            $sequence->setTitle($entry['title']);
            $sequence->setDescription($entry['description']);
            $sequence->setDefinitionArray($entry['definition']);
            $sequence->setPinned($entry['pinned'] ? 1 : 0);
            $sequence->setEnabled($entry['enabled'] ? 1 : 0);
            $sequence->setUpdatedAt($now);
            $isNew ? $this->sequences->insert($sequence) : $this->sequences->update($sequence);
        }

        return [
            'instructions' => $instructions,
            'prompts' => count($prompts),
            'projectTemplates' => count($templates),
            'coworkerTemplates' => count($coworkers),
            'sequences' => count($normalized),
        ];
    }

    /**
     * Check every sequence before anything is written, against the steps the
     * imported policy (or else the current one) allows.
     *
     * @param list<array<array-key, mixed>> $sequences
     * @param array<array-key, mixed>|null $policy
     * @return list<array{slug: string, title: string, description: ?string, definition: array{inputs: list<array<string, mixed>>, steps: list<array<string, mixed>>}, pinned: bool, enabled: bool}>
     */
    private function validateSequences(array $sequences, ?array $policy): array {
        $imported = $policy['maxSteps'] ?? null;
        $maxSteps = is_int($imported) ? $imported : $this->sequencePolicy->get()['maxSteps'];
        $out = [];
        $seen = [];
        foreach ($sequences as $i => $s) {
            $slug = $s['slug'] ?? null;
            $title = $s['title'] ?? null;
            $description = $s['description'] ?? null;
            try {
                $raw = $s['definition'] ?? null;
                if (!is_string($slug) || !is_string($title) || !is_array($raw)) {
                    throw new \InvalidArgumentException('needs a slug, a title and a definition');
                }
                if (isset($seen[$slug])) {
                    throw new \InvalidArgumentException('slug ' . $slug . ' is listed twice');
                }
                $seen[$slug] = true;
                $this->sequenceValidator->validateSlug($slug, Sequence::SCOPE_INSTANCE);
                $this->sequenceValidator->validateText($title, is_string($description) ? $description : null);
                $definition = $this->sequenceValidator->validateDefinition($raw, $maxSteps);
            } catch (\InvalidArgumentException $e) {
                throw new \InvalidArgumentException("sequences[$i]: " . $e->getMessage());
            }
            $out[] = [
                'slug' => $slug,
                'title' => trim($title),
                'description' => self::nullableString($description),
                'definition' => $definition,
                'pinned' => ($s['pinned'] ?? false) === true,
                'enabled' => ($s['enabled'] ?? true) !== false,
            ];
        }
        return $out;
    }

    /**
     * @param array<array-key, mixed> $data
     * @return list<array<array-key, mixed>>
     */
    private function listOf(array $data, string $key): array {
        $value = $data[$key] ?? [];
        if (!is_array($value)) {
            throw new \InvalidArgumentException($key . ' must be a list');
        }
        return array_values(array_filter($value, 'is_array'));
    }

    /**
     * Check every entry before anything is written, so a bad file changes nothing.
     *
     * @param list<array<array-key, mixed>> $prompts
     * @param list<array<array-key, mixed>> $templates
     */
    private function validate(array $prompts, array $templates): void {
        foreach ($prompts as $i => $p) {
            if (!is_string($p['title'] ?? null) || trim($p['title']) === '' || !is_string($p['content'] ?? null) || trim($p['content']) === '') {
                throw new \InvalidArgumentException("prompts[$i] needs a title and content");
            }
            $error = InstructionsService::tooLong($p['title'], InstructionsService::MAX_TITLE_LENGTH, "prompts[$i].title")
                ?? InstructionsService::tooLong($p['content'], InstructionsService::MAX_LENGTH, "prompts[$i].content");
            if ($error !== null) {
                throw new \InvalidArgumentException($error);
            }
        }
        foreach ($templates as $i => $t) {
            if (!is_string($t['title'] ?? null) || trim($t['title']) === '') {
                throw new \InvalidArgumentException("projectTemplates[$i] needs a title");
            }
            $system = $t['systemPrompt'] ?? null;
            $error = InstructionsService::tooLong($t['title'], InstructionsService::MAX_TITLE_LENGTH, "projectTemplates[$i].title")
                ?? InstructionsService::tooLong(is_string($system) ? $system : null, InstructionsService::MAX_LENGTH, "projectTemplates[$i].systemPrompt");
            if ($error !== null) {
                throw new \InvalidArgumentException($error);
            }
        }
    }

    private static function nullableString(mixed $value): ?string {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
