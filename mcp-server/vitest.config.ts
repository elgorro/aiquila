import { defineConfig } from 'vitest/config';

// Test discovery stays on vitest's defaults; this file only configures
// coverage (`npm run test:coverage`). The statements threshold is a ratchet,
// not a target: CI fails when coverage drops below it, and a PR that raises
// coverage raises it. Policy: docs/dev/ci-cd.md#coverage.
export default defineConfig({
  test: {
    coverage: {
      provider: 'v8',
      include: ['src/**/*.ts'],
      exclude: ['src/__tests__/**'],
      reporter: ['text-summary', 'lcov', 'json-summary'],
      reportsDirectory: 'coverage',
      thresholds: {
        statements: 88.1,
      },
    },
  },
});
