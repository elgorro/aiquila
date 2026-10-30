// SPDX-License-Identifier: MIT

import { describe, it, expect, afterEach } from 'vitest';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';
import { requireIdentityContext, runAs, SignInRequiredError } from '../nc-context.js';
import { basicAuth, getNextcloudConfig } from '../tools/types.js';

const saved = process.env;
afterEach(() => {
  process.env = saved;
  requireIdentityContext(false);
});

function env() {
  process.env = {
    ...saved,
    NEXTCLOUD_URL: 'https://cloud.example.com/',
    NEXTCLOUD_USER: 'service',
    NEXTCLOUD_PASSWORD: 'service-pw',
  };
}

const alice = {
  kind: 'user' as const,
  user: { userId: 'alice', loginName: 'alice@example.com', appPassword: 'app-pw', grantId: 'g' },
};

describe('getNextcloudConfig', () => {
  it('uses the environment account outside a request context (stdio)', () => {
    env();
    expect(getNextcloudConfig()).toEqual({
      url: 'https://cloud.example.com',
      user: 'service',
      loginName: 'service',
      password: 'service-pw',
    });
  });

  it('acts as the signed-in user: the uid for paths, the login name for auth', () => {
    env();
    const config = runAs(alice, () => getNextcloudConfig());
    expect(config).toEqual({
      url: 'https://cloud.example.com',
      user: 'alice',
      loginName: 'alice@example.com',
      password: 'app-pw',
    });
    expect(Buffer.from(basicAuth(config), 'base64').toString()).toBe('alice@example.com:app-pw');
  });

  it('follows the request through awaits', async () => {
    env();
    const user = await runAs(alice, async () => {
      await new Promise((r) => setTimeout(r, 1));
      return getNextcloudConfig().user;
    });
    expect(user).toBe('alice');
  });

  it('never gives an anonymous request the environment account', () => {
    env();
    expect(() => runAs({ kind: 'anonymous' }, () => getNextcloudConfig())).toThrow(
      SignInRequiredError
    );
  });

  it('with OAuth on, refuses to reach Nextcloud outside a request context', () => {
    env();
    requireIdentityContext();
    expect(() => getNextcloudConfig()).toThrow(/outside a request identity context/);
    expect(runAs({ kind: 'service' }, () => getNextcloudConfig()).user).toBe('service');
  });
});

describe('regression guard', () => {
  // Credentials come from getNextcloudConfig() only, which knows whose request
  // is being served. A module reading NEXTCLOUD_USER / NEXTCLOUD_PASSWORD
  // itself would act as the service account for every signed-in user.
  it('nothing outside tools/types.ts reads the environment account', () => {
    const root = join(__dirname, '..');
    const offenders: string[] = [];
    const walk = (dir: string) => {
      for (const name of readdirSync(dir)) {
        const path = join(dir, name);
        if (statSync(path).isDirectory()) {
          if (name !== '__tests__') walk(path);
        } else if (name.endsWith('.ts')) {
          const rel = relative(root, path);
          if (rel === join('tools', 'types.ts')) continue;
          if (/process\.env\.NEXTCLOUD_(USER|PASSWORD)\b/.test(readFileSync(path, 'utf8'))) {
            offenders.push(rel);
          }
        }
      }
    };
    walk(root);
    expect(offenders).toEqual([]);
  });
});
