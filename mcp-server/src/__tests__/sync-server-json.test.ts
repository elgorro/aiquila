import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { writeFileSync, readFileSync, mkdtempSync, rmSync } from 'node:fs';
import { resolve } from 'node:path';
import { tmpdir } from 'node:os';
import { syncVersions } from '../../scripts/sync-server-json.mjs';

describe('sync-server-json', () => {
  let tmpDir: string;
  beforeEach(() => {
    tmpDir = mkdtempSync(resolve(tmpdir(), 'aiquila-test-'));
  });
  afterEach(() => {
    rmSync(tmpDir, { recursive: true });
  });

  it('syncs all three version fields in server.json', () => {
    writeFileSync(resolve(tmpDir, 'package.json'), JSON.stringify({ version: '9.9.9' }));
    writeFileSync(
      resolve(tmpDir, 'server.json'),
      JSON.stringify({
        version: '0.0.1',
        packages: [
          { registryType: 'npm', version: '0.0.1' },
          { registryType: 'oci', identifier: 'ghcr.io/elgorro/aiquila-mcp:0.0.1' },
        ],
      })
    );

    syncVersions(resolve(tmpDir, 'package.json'), resolve(tmpDir, 'server.json'));

    const result = JSON.parse(readFileSync(resolve(tmpDir, 'server.json'), 'utf8'));
    expect(result.version).toBe('9.9.9');
    expect(result.packages[0].version).toBe('9.9.9');
    expect(result.packages[1].identifier).toBe('ghcr.io/elgorro/aiquila-mcp:9.9.9');
  });

  it('returns the version string', () => {
    writeFileSync(resolve(tmpDir, 'package.json'), JSON.stringify({ version: '1.2.3' }));
    writeFileSync(
      resolve(tmpDir, 'server.json'),
      JSON.stringify({
        version: '0.0.0',
        packages: [
          { registryType: 'npm', version: '0.0.0' },
          { registryType: 'oci', identifier: 'ghcr.io/elgorro/aiquila-mcp:0.0.0' },
        ],
      })
    );
    expect(syncVersions(resolve(tmpDir, 'package.json'), resolve(tmpDir, 'server.json'))).toBe(
      '1.2.3'
    );
  });
});
