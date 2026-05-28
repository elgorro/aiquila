// SPDX-License-Identifier: MIT

import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { mkdtempSync, rmSync, chmodSync, statSync, existsSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { probeStateDir, StateDirNotWritableError } from '../../auth/store.js';

describe('probeStateDir', () => {
  let dir: string;

  beforeEach(() => {
    dir = mkdtempSync(join(tmpdir(), 'aiquila-probe-'));
  });

  afterEach(() => {
    try {
      chmodSync(dir, 0o700);
    } catch {
      /* ignore */
    }
    rmSync(dir, { recursive: true, force: true });
  });

  it('succeeds on a writable directory and leaves no sentinel behind', () => {
    expect(() => probeStateDir(dir)).not.toThrow();
    expect(existsSync(join(dir, '.write-probe'))).toBe(false);
  });

  it('creates the directory if missing', () => {
    const nested = join(dir, 'does', 'not', 'exist');
    expect(() => probeStateDir(nested)).not.toThrow();
    expect(statSync(nested).isDirectory()).toBe(true);
  });

  it('throws StateDirNotWritableError when the directory is read-only', () => {
    if (process.getuid?.() === 0) {
      // root bypasses permission checks — skip
      return;
    }
    chmodSync(dir, 0o500);
    try {
      probeStateDir(dir);
      expect.fail('expected probeStateDir to throw');
    } catch (err) {
      expect(err).toBeInstanceOf(StateDirNotWritableError);
      const e = err as StateDirNotWritableError;
      expect(e.dir).toBe(dir);
      expect(['EACCES', 'EPERM']).toContain(e.cause.code);
      expect(e.message).toContain('chown -R node:node');
    }
  });
});
