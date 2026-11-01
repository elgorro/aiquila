<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service;

use OCA\AIquila\Db\ProjectTemplate;
use OCA\AIquila\Db\ProjectTemplateMapper;
use OCA\AIquila\Db\Prompt;
use OCA\AIquila\Db\PromptMapper;

/**
 * Exports and imports the instance library as JSON, so admins running several
 * instances can keep them in step: instance instructions and their scopes,
 * organization prompts, project templates and coworker templates. Users' own
 * entries are never exported or touched.
 *
 * Import upserts by title (prompts, project templates) and by id (coworker
 * templates). With `replace`, the existing instance entries are deleted first.
 */
class LibraryTransfer {
    public const FORMAT = 'aiquila-library';
    public const VERSION = 1;

    public function __construct(
        private InstructionsService $instructions,
        private PromptMapper $prompts,
        private ProjectTemplateMapper $templates,
        private CoworkerTemplates $coworkerTemplates,
    ) {
    }

    /**
     * @return array{format: string, version: int, instructions: array{text: string, scopes: array<string, bool>}, prompts: list<array{title: string, description: ?string, content: string}>, projectTemplates: list<array{title: string, description: ?string, systemPrompt: ?string, paths: list<array{path: string, pathType: string}>}>, coworkerTemplates: list<array<string, mixed>>}
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
        ];
    }

    /**
     * @param array<array-key, mixed> $data a decoded export
     * @param string $author user id recorded as the author of imported rows
     * @return array{instructions: bool, prompts: int, projectTemplates: int, coworkerTemplates: int}
     * @throws \InvalidArgumentException when $data is not an export, or an entry is invalid
     */
    public function import(array $data, string $author, bool $replace = false): array {
        if (($data['format'] ?? null) !== self::FORMAT || ($data['version'] ?? null) !== self::VERSION) {
            throw new \InvalidArgumentException('Not an AIquila library export (format ' . self::FORMAT . ', version ' . self::VERSION . ')');
        }
        $prompts = $this->listOf($data, 'prompts');
        $templates = $this->listOf($data, 'projectTemplates');
        $coworkers = $this->listOf($data, 'coworkerTemplates');
        $this->validate($prompts, $templates);

        if ($replace) {
            foreach ($this->prompts->findAllInstance() as $p) {
                $this->prompts->delete($p);
            }
            foreach ($this->templates->findAllInstance() as $t) {
                $this->templates->delete($t);
            }
            $this->coworkerTemplates->clear();
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

        return [
            'instructions' => $instructions,
            'prompts' => count($prompts),
            'projectTemplates' => count($templates),
            'coworkerTemplates' => count($coworkers),
        ];
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
