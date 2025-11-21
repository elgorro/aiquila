# CI/CD Workflows

NextClaude uses GitHub Actions for continuous integration and deployment.

## Workflows Overview

| Workflow | Trigger | Purpose |
|----------|---------|---------|
| `test.yml` | Push/PR to main | Run tests |
| `lint.yml` | Push/PR to main | Code quality checks |
| `release.yml` | Tag `v*` | Create GitHub release |
| `nextcloud-release.yml` | Tag `v*` | Sign & package NC app |

## Test Workflow (`test.yml`)

Runs on every push and pull request to `main`.

**MCP Server:**
- Installs dependencies
- Type checks with TypeScript
- Runs Vitest tests
- Builds the project

**Nextcloud App:**
- Sets up PHP 8.2
- Installs Composer dependencies
- Runs PHPUnit tests

## Lint Workflow (`lint.yml`)

Ensures code quality on every push and PR.

**Checks:**
- ESLint for TypeScript errors
- Prettier for formatting consistency

**Fix locally before pushing:**
```bash
cd mcp-server
npm run lint:fix
npm run format
```

## Release Workflow (`release.yml`)

Triggered when you push a version tag.

**Creates:**
- `nextclaude-mcp-vX.X.X.tar.gz` - MCP server package
- `nextclaude-nc-vX.X.X.tar.gz` - Nextcloud app package

**How to release:**
```bash
# Update version in package.json and info.xml
git add -A
git commit -m "Release v0.1.0"
git tag v0.1.0
git push origin main --tags
```

## Nextcloud App Release (`nextcloud-release.yml`)

Creates a signed Nextcloud app package for the app store.

### Setup Signing (Optional)

1. Register at [apps.nextcloud.com](https://apps.nextcloud.com)
2. Request a signing certificate for your app
3. Add GitHub secrets:
   - `NC_SIGN_KEY` - Private key content
   - `NC_SIGN_CERT` - Certificate content

### Without Signing

The workflow still creates a valid tarball, but it won't be signed. Users will see a warning when installing unsigned apps.

## Local Development

### Running Tests Locally

**MCP Server:**
```bash
cd mcp-server
npm test           # Run once
npm run test:watch # Watch mode
```

**Nextcloud App:**
```bash
cd nextcloud-app
composer install
composer test
```

### Running Linters Locally

```bash
cd mcp-server
npm run lint       # Check for errors
npm run lint:fix   # Auto-fix errors
npm run format     # Format with Prettier
```

### Pre-commit Checklist

Before pushing:
1. `npm run lint` passes
2. `npm test` passes
3. `npx tsc --noEmit` passes (no type errors)

## Troubleshooting

### Tests Failing in CI

- Check Node.js version matches (20.x)
- Check PHP version matches (8.2)
- Run tests locally to reproduce

### Lint Errors

```bash
# See what would change
npx prettier --check src/

# Auto-fix
npm run lint:fix && npm run format
```

### Release Not Triggering

- Ensure tag follows `v*` pattern (e.g., `v0.1.0`)
- Check you pushed both commit and tag:
  ```bash
  git push origin main --tags
  ```

## Customization

### Adding New Test Jobs

Edit `.github/workflows/test.yml`:

```yaml
jobs:
  new-job:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      # Add your steps
```

### Changing Node.js Version

Update in all workflows:
```yaml
- name: Setup Node.js
  uses: actions/setup-node@v4
  with:
    node-version: '22'  # Change version here
```
