import js from '@eslint/js';
import tseslint from 'typescript-eslint';

export default tseslint.config(
  js.configs.recommended,
  ...tseslint.configs.recommended,
  {
    rules: {
      // Production code is typed: an `any` that is genuinely needed carries an
      // eslint-disable comment saying so (see Tool in src/tools/types.ts).
      '@typescript-eslint/no-explicit-any': 'error',
      '@typescript-eslint/no-unused-vars': [
        'error',
        { argsIgnorePattern: '^_', varsIgnorePattern: '^_' },
      ],
      'no-console': ['warn', { allow: ['error', 'warn'] }],
    },
  },
  {
    // Tests are not type-checked (tsconfig excludes them), and casting a mock
    // to `any` is how they hand a partial fetch / request / response to the
    // code under test. The rule would only flag that.
    files: ['src/__tests__/**'],
    rules: {
      '@typescript-eslint/no-explicit-any': 'off',
    },
  },
  {
    ignores: ['dist/', 'node_modules/'],
  }
);
