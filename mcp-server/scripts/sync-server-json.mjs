import { readFileSync, writeFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

export function syncVersions(pkgPath, serverJsonPath) {
  const { version } = JSON.parse(readFileSync(pkgPath, 'utf8'));
  const s = JSON.parse(readFileSync(serverJsonPath, 'utf8'));
  s.version = version;
  s.packages.forEach(p => {
    if (p.registryType === 'npm') p.version = version;
    if (p.registryType === 'oci') p.identifier = p.identifier.replace(/:[^:]+$/, ':' + version);
  });
  writeFileSync(serverJsonPath, JSON.stringify(s, null, 2) + '\n');
  return version;
}

// Run directly: node scripts/sync-server-json.mjs
if (import.meta.url === pathToFileURL(process.argv[1]).href) {
  const root = dirname(fileURLToPath(import.meta.url)) + '/..';
  const v = syncVersions(resolve(root, 'package.json'), resolve(root, 'server.json'));
  console.log(`server.json synced to ${v}`);
}
