<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service;

use OCA\AIquila\Db\Coworker;
use OCP\Security\ISecureRandom;

/**
 * Coworker templates an admin published for the instance, offered next to the
 * built-in ones from CoworkerService::getTemplates().
 *
 * A template is a copy of a coworker's configuration (task, schedule, input,
 * output, provider, model, options), stored as one JSON list in app config.
 * Creating a coworker from it copies again, so later changes to the template
 * never reach existing coworkers.
 */
class CoworkerTemplates {
    private const APP = 'aiquila';
    private const KEY = 'coworker_templates';
    /** Prefix that keeps instance ids apart from the built-in template ids. */
    public const ID_PREFIX = 'org-';

    public function __construct(
        private AppSettings $config,
        private ISecureRandom $random,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(): array {
        $decoded = json_decode($this->config->getAppValue(self::APP, self::KEY, '[]'), true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $template) {
            if (is_array($template) && is_string($template['id'] ?? null) && is_string($template['title'] ?? null)) {
                $out[] = ['scope' => 'instance'] + $template;
            }
        }
        return $out;
    }

    /**
     * Publish $coworker's configuration as an instance template.
     *
     * @return array<string, mixed> the stored template
     */
    public function publish(Coworker $coworker, ?string $title = null, ?string $description = null): array {
        $data = $coworker->jsonSerialize();
        $template = [
            'id' => self::ID_PREFIX . $this->random->generate(12, ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS),
            'title' => $title !== null && trim($title) !== '' ? trim($title) : (string)$data['title'],
            'description' => $description ?? (string)($data['description'] ?? ''),
            'task_type' => $data['taskType'],
            'cron_schedule' => $data['cronSchedule'],
            'input_type' => $data['inputType'],
            'input_path' => $data['inputPath'],
            'output_type' => $data['outputType'],
            'output_path' => $data['outputPath'],
            'provider' => $data['provider'],
            'model' => $data['model'],
            'options' => $data['options'],
        ];
        $this->import([$template]);
        return ['scope' => 'instance'] + $template;
    }

    /**
     * Add templates, e.g. from an export. Ids that already exist are replaced.
     *
     * @param list<array<string, mixed>> $templates
     */
    public function import(array $templates): void {
        $byId = [];
        foreach ($this->list() as $existing) {
            $byId[(string)$existing['id']] = $existing;
        }
        foreach ($templates as $template) {
            if (!is_string($template['id'] ?? null) || !is_string($template['title'] ?? null)) {
                continue;
            }
            $byId[$template['id']] = $template;
        }
        $this->store(array_values($byId));
    }

    public function delete(string $id): bool {
        $before = $this->list();
        $after = array_values(array_filter($before, static fn (array $t): bool => $t['id'] !== $id));
        if (count($after) === count($before)) {
            return false;
        }
        $this->store($after);
        return true;
    }

    public function clear(): void {
        $this->config->deleteAppValue(self::APP, self::KEY);
    }

    /**
     * @param list<array<string, mixed>> $templates
     */
    private function store(array $templates): void {
        $clean = array_map(static function (array $t): array {
            unset($t['scope']);
            return $t;
        }, $templates);
        $this->config->setAppValue(self::APP, self::KEY, json_encode($clean, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]');
    }
}
