# CI/CD Workflows

AIquila uses GitHub Actions for continuous integration and deployment.

## Workflows Overview

| Workflow | Trigger | Purpose |
|----------|---------|---------|
| `test.yml` | Push/PR to main | Run tests |
| `lint.yml` | Push/PR to main | Code quality checks |
| `mcp-release.yml` | Version change in `mcp-server/` | Auto-release MCP server |
| `nc-release.yml` | Version change in `nextcloud-app/` | Auto-release & publish NC app |

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

## Automated Release Workflows

AIquila uses automated release workflows that trigger when you bump the version number and push to `main`.

### MCP Server Release (`mcp-release.yml`)

**Triggers:** Automatically when version changes in `mcp-server/package.json` on push to `main`

**What it does:**
1. Detects version change in `package.json`
2. Runs tests and builds the project
3. Creates tag `mcp-vX.X.X`
4. Packages: `dist/`, `package.json`, `README.md`
5. Creates GitHub release with `aiquila-mcp-vX.X.X.tar.gz`

**How to release:**
```bash
# 1. Update version in mcp-server/package.json
vim mcp-server/package.json  # Change version to 0.1.1

# 2. Commit and push to main
git add mcp-server/package.json
git commit -m "Bump MCP server to v0.1.1"
git push origin main

# 3. Workflow runs automatically and creates:
#    - Tag: mcp-v0.1.1
#    - GitHub Release with tarball
```

### Nextcloud App Release (`nc-release.yml`)

**Triggers:** Automatically when version changes in `nextcloud-app/appinfo/info.xml` on push to `main`

**What it does:**
1. Detects version change in `info.xml`
2. Runs composer tests
3. Builds frontend (`npm run build`)
4. Creates tag `nc-vX.X.X`
5. Packages app (excludes: tests/, vendor/, node_modules/, src/)
6. Signs package (if `NC_SIGN_KEY` secret exists)
7. Creates GitHub release with `aiquila.tar.gz` and signature
8. **Waits for manual approval** (via GitHub environment protection)
9. Publishes to Nextcloud App Store

**How to release:**
```bash
# 1. Update version in BOTH files
vim nextcloud-app/appinfo/info.xml     # Change <version>0.1.1</version>
vim nextcloud-app/package.json         # Change version to 0.1.1

# 2. Update CHANGELOG.md
vim nextcloud-app/CHANGELOG.md

# 3. Commit and push to main
git add nextcloud-app/
git commit -m "Bump Nextcloud app to v0.1.1"
git push origin main

# 4. Workflow runs automatically:
#    - Creates tag: nc-v0.1.1
#    - Creates GitHub Release
#    - Waits for your approval to publish to app store

# 5. Approve app store publishing:
#    Go to: https://github.com/YOUR-REPO/actions
#    Click on the workflow run
#    Click "Review deployments" and approve "nextcloud-appstore"
```

### Setup Required GitHub Secrets

Navigate to: **Repository Settings → Secrets and variables → Actions → New repository secret**

#### For Nextcloud App Signing (Required)

1. **`NC_SIGN_KEY`** - Your private key content
   ```bash
   # Copy your private key content
   cat ~/.nextcloud/certificates/aiquila.key
   # Paste entire content (including BEGIN/END lines) into secret
   ```

2. **`NC_SIGN_CERT`** - Your certificate content (if needed)
   ```bash
   cat ~/.nextcloud/certificates/aiquila.crt
   ```

#### For Nextcloud App Store Publishing (Required for auto-publish)

3. **`NEXTCLOUD_APPSTORE_USERNAME`** - Your app store username

4. **`NEXTCLOUD_APPSTORE_TOKEN`** - Your app store API token
   - Get from: [apps.nextcloud.com](https://apps.nextcloud.com) → Account Settings → API Token

### Setup Manual Approval Gate

To enable the manual approval before publishing to app store:

1. Go to **Repository Settings → Environments**
2. Create new environment: `nextcloud-appstore`
3. Enable "Required reviewers"
4. Add yourself as a required reviewer
5. Save

Now the workflow will wait for your approval before publishing to the app store!

### Version Requirements

**Keep versions in sync:**
- MCP Server: Update `mcp-server/package.json`
- Nextcloud App: Update BOTH:
  - `nextcloud-app/appinfo/info.xml` (e.g., `<version>0.1.1</version>`)
  - `nextcloud-app/package.json` (e.g., `"version": "0.1.1"`)

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

- Ensure version actually changed in the correct file:
  - MCP: `mcp-server/package.json`
  - Nextcloud: `nextcloud-app/appinfo/info.xml`
- Check you pushed to `main` branch
- Check workflow runs in Actions tab
- Ensure changes are in the correct directory path
- Workflow ignores changes to `*.md` files and `tests/` directories

### App Store Publishing Fails

- Verify secrets are set: `NC_SIGN_KEY`, `NEXTCLOUD_APPSTORE_USERNAME`, `NEXTCLOUD_APPSTORE_TOKEN`
- Check signature is valid (download .tar.gz and .sig, verify locally)
- Ensure app is registered at apps.nextcloud.com
- Check app store API response in workflow logs

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
