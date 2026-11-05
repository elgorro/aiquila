// SPDX-License-Identifier: MIT

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const mockFetchOCS = vi.fn();
vi.mock('../client/ocs.js', () => ({
  fetchOCS: (...args: unknown[]) => mockFetchOCS(...args),
}));

vi.mock('../logger.js', () => ({
  logger: { info: vi.fn(), warn: vi.fn(), error: vi.fn(), debug: vi.fn() },
}));

import {
  _resetCapabilitiesCache,
  checkGatedParams,
  getCapabilities,
  nextcloudMajor,
  talkFeature,
} from '../client/capabilities.js';
import { runAs, SignInRequiredError, type RequestIdentity } from '../nc-context.js';

const NC35 = {
  ocs: {
    meta: { status: 'ok', statuscode: 200, message: 'OK' },
    data: {
      version: { major: 35, minor: 0, micro: 2, string: '35.0.2' },
      capabilities: { spreed: { features: ['conversation-presets', 'message-expiration'] } },
    },
  },
};

const user = (userId: string): RequestIdentity => ({
  kind: 'user',
  user: { userId, loginName: userId, appPassword: 'app-password', grantId: `grant-${userId}` },
});

const gates = {
  preset: talkFeature('conversation-presets', 'Talk 24 (Nextcloud 34)'),
  classified: talkFeature('classified-conversations', 'Talk 25 (Nextcloud 35)'),
};

describe('capabilities', () => {
  beforeEach(() => {
    _resetCapabilitiesCache();
    mockFetchOCS.mockReset();
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  describe('getCapabilities', () => {
    it('reads the capabilities once for an account', async () => {
      mockFetchOCS.mockResolvedValue(NC35);

      const first = await getCapabilities();
      const second = await getCapabilities();

      expect(first).toEqual(NC35.ocs.data);
      expect(second).toEqual(NC35.ocs.data);
      expect(mockFetchOCS).toHaveBeenCalledTimes(1);
      expect(mockFetchOCS).toHaveBeenCalledWith('/ocs/v2.php/cloud/capabilities');
    });

    it('shares one request between concurrent callers', async () => {
      mockFetchOCS.mockResolvedValue(NC35);

      await Promise.all([getCapabilities(), getCapabilities(), getCapabilities()]);

      expect(mockFetchOCS).toHaveBeenCalledTimes(1);
    });

    it('keeps accounts apart', async () => {
      mockFetchOCS.mockResolvedValue(NC35);

      await runAs(user('alice'), getCapabilities);
      await runAs(user('bob'), getCapabilities);
      await runAs(user('alice'), getCapabilities);

      expect(mockFetchOCS).toHaveBeenCalledTimes(2);
    });

    it('reads again once a success has expired', async () => {
      vi.useFakeTimers();
      mockFetchOCS.mockResolvedValue(NC35);

      await getCapabilities();
      vi.advanceTimersByTime(10 * 60 * 1000 + 1);
      await getCapabilities();

      expect(mockFetchOCS).toHaveBeenCalledTimes(2);
    });

    it('returns null when the probe fails, and retries after a minute', async () => {
      vi.useFakeTimers();
      mockFetchOCS.mockRejectedValueOnce(new Error('OCS 503'));
      mockFetchOCS.mockResolvedValue(NC35);

      expect(await getCapabilities()).toBeNull();
      expect(await getCapabilities()).toBeNull();
      expect(mockFetchOCS).toHaveBeenCalledTimes(1);

      vi.advanceTimersByTime(60 * 1000 + 1);

      expect(await getCapabilities()).toEqual(NC35.ocs.data);
    });

    it('does not ask Nextcloud for a request without a token', async () => {
      await expect(runAs({ kind: 'anonymous' }, getCapabilities)).rejects.toBeInstanceOf(
        SignInRequiredError
      );
      expect(mockFetchOCS).not.toHaveBeenCalled();
    });
  });

  describe('gates', () => {
    it('talkFeature fails when Talk is not there at all', () => {
      expect(gates.preset.isMet({ capabilities: {} })).toBe(false);
    });

    it('nextcloudMajor compares the major version', () => {
      const caps = NC35.ocs.data;
      expect(nextcloudMajor(35).isMet(caps)).toBe(true);
      expect(nextcloudMajor(36).isMet(caps)).toBe(false);
      expect(nextcloudMajor(35).isMet({ capabilities: {} })).toBe(false);
    });
  });

  describe('checkGatedParams', () => {
    it('makes no request when no gated parameter is passed', async () => {
      expect(await checkGatedParams({ roomName: 'x', preset: undefined }, gates)).toBeNull();
      expect(mockFetchOCS).not.toHaveBeenCalled();
    });

    it('lets a supported parameter through', async () => {
      mockFetchOCS.mockResolvedValue(NC35);

      expect(await checkGatedParams({ preset: 'channel' }, gates)).toBeNull();
    });

    it('checks a parameter set to a falsy value', async () => {
      mockFetchOCS.mockResolvedValue(NC35);

      expect(await checkGatedParams({ classified: false }, gates)).toMatch(/classified requires/);
    });

    it('names what an unsupported parameter requires', async () => {
      mockFetchOCS.mockResolvedValue(NC35);

      const refused = await checkGatedParams({ preset: 'channel', classified: true }, gates);

      expect(refused).toBe(
        'Not supported by this Nextcloud server, so nothing was sent: ' +
          'classified requires Talk 25 (Nextcloud 35).'
      );
    });

    it('refuses a gated parameter when the probe failed', async () => {
      mockFetchOCS.mockRejectedValue(new Error('OCS 503'));

      const refused = await checkGatedParams({ preset: 'channel' }, gates);

      expect(refused).toMatch(
        /Could not read .* capabilities, so nothing was sent\. Unchecked: preset\./
      );
    });
  });
});
