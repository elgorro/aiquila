// SPDX-License-Identifier: MIT

import { describe, it, expect } from 'vitest';
import { existsSync, readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { buildCatalog, catalogJson } from '../sequences/catalog.js';
import { isSequenceTool } from '../sequences/definition.js';
import { TOOL_REGISTRY } from '../tool-registry.js';

const committed = fileURLToPath(
  new URL('../../../nextcloud-app/lib/Resources/sequence-catalog.json', import.meta.url)
);

describe('sequence-catalog.json', () => {
  // Like server.json: the Nextcloud app ships a generated copy, which must match the code.
  it.skipIf(!existsSync(committed))(
    'is up to date (run `npm run catalog` from mcp-server/)',
    () => {
      expect(readFileSync(committed, 'utf8')).toBe(catalogJson());
    }
  );

  it('lists every tool a step may call, and no sequence tool', () => {
    const catalog = buildCatalog();
    const names = catalog.tools.map((t) => t.name);
    const expected = TOOL_REGISTRY.flatMap((e) => e.tools)
      .map((t) => t.name)
      .filter((n) => !isSequenceTool(n));
    expect(names).toEqual(expected);
    for (const t of catalog.tools) expect(t.inputSchema.type).toBe('object');
  });
});
