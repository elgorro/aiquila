// SPDX-License-Identifier: MIT

import { describe, it, expect, vi, beforeEach } from 'vitest';

const mockFetchOCS = vi.fn();

vi.mock('../client/ocs.js', () => ({
  fetchOCS: (...args: unknown[]) => mockFetchOCS(...args),
}));

const sampleFile = {
  id: '42',
  timestamp: 1_700_000_000,
  name: 'report.pdf',
  directory: '/Documents',
  extension: 'pdf',
  mimeType: 'application/pdf',
  hasPreview: true,
  reason: 'recently-commented',
};

function ok(data: unknown) {
  return { ocs: { meta: { status: 'ok', statuscode: 200, message: 'OK' }, data } };
}

describe('Recommendation Tools', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    process.env.NEXTCLOUD_URL = 'https://cloud.example.com';
    process.env.NEXTCLOUD_USER = 'admin';
    process.env.NEXTCLOUD_PASSWORD = 'testpass';
  });

  describe('list_recommendations', () => {
    it('should call the recommendations endpoint', async () => {
      mockFetchOCS.mockResolvedValue(ok({ enabled: true, recommendations: [sampleFile] }));

      const { listRecommendationsTool } = await import('../tools/apps/recommendations.js');
      await listRecommendationsTool.handler();

      expect(mockFetchOCS).toHaveBeenCalledWith(
        '/ocs/v2.php/apps/recommendations/api/v1/recommendations'
      );
    });

    it('should format recommended files with path, reason and timestamp', async () => {
      mockFetchOCS.mockResolvedValue(ok({ enabled: true, recommendations: [sampleFile] }));

      const { listRecommendationsTool } = await import('../tools/apps/recommendations.js');
      const result = await listRecommendationsTool.handler();

      expect(result.content[0].text).toContain('Recommended files (1)');
      expect(result.content[0].text).toContain('/Documents/report.pdf');
      expect(result.content[0].text).toContain('reason: recently-commented');
      expect(result.content[0].text).toContain('2023-11-14T');
    });

    it('should report when recommendations are disabled', async () => {
      mockFetchOCS.mockResolvedValue(ok({ enabled: false }));

      const { listRecommendationsTool } = await import('../tools/apps/recommendations.js');
      const result = await listRecommendationsTool.handler();

      expect(result.content[0].text).toBe('Recommendations are disabled for this user.');
    });

    it('should handle enabled with no recommendations', async () => {
      mockFetchOCS.mockResolvedValue(ok({ enabled: true, recommendations: [] }));

      const { listRecommendationsTool } = await import('../tools/apps/recommendations.js');
      const result = await listRecommendationsTool.handler();

      expect(result.content[0].text).toBe('No recommended files.');
    });

    it('should report errors', async () => {
      mockFetchOCS.mockRejectedValue(new Error('boom'));

      const { listRecommendationsTool } = await import('../tools/apps/recommendations.js');
      const result = await listRecommendationsTool.handler();

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('boom');
    });
  });
});
