// SPDX-License-Identifier: MIT
// Writes nextcloud-app/lib/Resources/sequence-catalog.json from the tool registry.
// Run from mcp-server/: npm run catalog

import { writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { catalogJson } from '../src/sequences/catalog.js';

const target = fileURLToPath(
  new URL('../../nextcloud-app/lib/Resources/sequence-catalog.json', import.meta.url)
);
writeFileSync(target, catalogJson());
console.log(`Wrote ${target}`);
