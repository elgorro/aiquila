<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Service;

/**
 * The MCP server's tools and built-in sequences, as `sequence-catalog.json`
 * describes them. The file is generated in `mcp-server/` (`npm run catalog`)
 * and committed here, so the app can edit and validate sequences without
 * reaching the MCP server.
 *
 * @psalm-type AIquilaCatalogTool = array{name: string, title: string, category: string, description: string, annotations: array{readOnlyHint: bool, destructiveHint: bool, idempotentHint: bool, openWorldHint: bool}, inputSchema: array<string, mixed>}
 * @psalm-type AIquilaCatalogBuiltin = array{slug: string, title: string, description: string, inputs: list<array<string, mixed>>, steps: list<array<string, mixed>>, pinned: bool}
 */
class SequenceCatalog {
    public const FILE = __DIR__ . '/../Resources/sequence-catalog.json';

    /** @var array<string, mixed>|null */
    private ?array $data = null;
    /** @var array<string, AIquilaCatalogTool>|null */
    private ?array $tools = null;

    public function __construct(
        private string $path = self::FILE,
    ) {
    }

    /**
     * The whole catalog, as the file holds it.
     *
     * @return array<string, mixed>
     */
    public function data(): array {
        if ($this->data === null) {
            $raw = @file_get_contents($this->path);
            $decoded = $raw === false ? null : json_decode($raw, true);
            $this->data = is_array($decoded) ? $decoded : [];
        }
        return $this->data;
    }

    /**
     * @return array<string, AIquilaCatalogTool>
     */
    public function tools(): array {
        if ($this->tools === null) {
            $this->tools = [];
            $list = $this->data()['tools'] ?? [];
            foreach (is_array($list) ? $list : [] as $tool) {
                if (is_array($tool) && is_string($tool['name'] ?? null)) {
                    /** @var AIquilaCatalogTool $tool */
                    $this->tools[$tool['name']] = $tool;
                }
            }
        }
        return $this->tools;
    }

    /**
     * @return AIquilaCatalogTool|null
     */
    public function tool(string $name): ?array {
        return $this->tools()[$name] ?? null;
    }

    /**
     * @return list<AIquilaCatalogBuiltin>
     */
    public function builtins(): array {
        $list = $this->data()['builtins'] ?? [];
        /** @var list<AIquilaCatalogBuiltin> */
        return array_values(array_filter(is_array($list) ? $list : [], static fn ($b): bool => is_array($b) && is_string($b['slug'] ?? null)));
    }

    /**
     * @return list<string>
     */
    public function builtinSlugs(): array {
        return array_map(static fn (array $b): string => $b['slug'], $this->builtins());
    }

    public function limit(string $name, int $fallback): int {
        $limits = $this->data()['limits'] ?? [];
        $value = is_array($limits) ? ($limits[$name] ?? null) : null;
        return is_int($value) ? $value : $fallback;
    }
}
