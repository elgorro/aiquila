# AIquila Nextcloud App Setup

Complete guide to installing and configuring the AIquila Nextcloud app.

## Prerequisites

- Nextcloud 32 or higher
- PHP 8.1 or higher with Composer
- Node.js 20 or higher (for building frontend)
- npm 10 or higher
- Claude API key from [console.anthropic.com](https://console.anthropic.com)

## What You Get

AIquila provides three main features:

1. **Chat Interface**: Interactive chat with Claude AI at `/apps/aiquila`
2. **Text Processing Provider**: Native integration with Nextcloud Assistant
3. **Public API**: RESTful endpoints for other apps to use Claude

## Installation

### 1. Install Dependencies

```bash
cd nextcloud-app

# Install PHP dependencies (Anthropic SDK)
composer install

# Install Node.js dependencies
npm install

# Build frontend
npm run build
```

### 2. Deploy the App

Choose one of these deployment methods:

**Option A: Copy to Nextcloud (Production)**
```bash
cp -r nextcloud-app /path/to/nextcloud/custom_apps/aiquila
```

**Option B: Symlink (Development)**
```bash
ln -s /path/to/aiquila/nextcloud-app /path/to/nextcloud/custom_apps/aiquila
```

**Option C: Docker Development**
```bash
# See docs/docker-setup.md for complete Docker development environment
```

### 3. Set Correct Permissions

The web server needs to read all app files:

```bash
cd /path/to/nextcloud/custom_apps/aiquila

# Quick fix: set all permissions recursively
find . -type f -exec chmod 644 {} \;
find . -type d -exec chmod 755 {} \;
```

**Important files that need 644 permissions:**
- `lib/**/*.php` - PHP classes
- `templates/*.php` - Templates
- `css/*.css` - Stylesheets
- `js/*.js` - JavaScript files
- `img/*.svg` - Icons

### 4. Enable the App

**Via command line (recommended):**
```bash
cd /path/to/nextcloud
sudo -u www-data php occ app:enable aiquila
```

**Via web interface:**
1. Go to **Settings → Apps**
2. Find "AIquila" in the disabled apps list
3. Click **Enable**

### 5. Configure API Key

**Admin Configuration** (applies to all users):

1. Navigate to **Settings → Administration → AIquila**
2. Enter your Claude API key from [console.anthropic.com](https://console.anthropic.com)
3. Configure settings (optional):
   - **Claude Model**: Default is `claude-sonnet-4-5-20250929` (latest Sonnet 4.5)
     - Other options: `claude-haiku-4-5-20251001`, `claude-opus-4-5-20251101`
   - **Max Tokens**: Response length limit (1-100,000, default: 4096)
   - **API Timeout**: Request timeout in seconds (10-1800, default: 30)
4. Click **Save**
5. Click **Test Configuration** to verify everything works

**User Configuration** (optional):

Users can override the admin API key with their own:

1. Go to **Settings → Personal → AIquila**
2. Enter your personal Claude API key
3. Click **Save** to override admin key
4. Click **Clear Key** to remove override and use admin key

## Features

### 1. Chat Interface

Access at **`/apps/aiquila`**

Features:
- Interactive conversation with Claude
- Message history during session
- Clean, responsive design
- Quick access to settings (⚙️ icon)

Usage:
1. Navigate to `/apps/aiquila`
2. Type your question in the text area
3. Press **Enter** or click **Ask Claude**
4. See Claude's response appear in the chat history

### 2. Nextcloud Assistant Integration

AIquila automatically registers as a Text Processing Provider, making it available throughout Nextcloud:

- Accessible via Nextcloud's native Assistant
- Works with any feature using text processing
- No additional configuration needed
- Seamlessly integrated into workflows

### 3. Public API

Other Nextcloud apps can programmatically use AIquila:

**Ask Claude:**
```http
POST /apps/aiquila/api/ask
Content-Type: application/json

{
  "prompt": "Your question here",
  "context": "Optional context to provide"
}
```

**Response:**
```json
{
  "response": "Claude's answer...",
  "model": "claude-sonnet-4-5-20250929",
  "usage": {
    "input_tokens": 15,
    "output_tokens": 120
  }
}
```

**Summarize Text:**
```http
POST /apps/aiquila/api/summarize
Content-Type: application/json

{
  "content": "Long text to summarize..."
}
```

**Response:**
```json
{
  "summary": "Concise summary...",
  "original_length": 5000,
  "summary_length": 150
}
```

See [internal-api.md](../internal-api.md) for complete API documentation.

## Verification

### Quick Tests

1. **Chat Interface**:
   - Go to `/apps/aiquila`
   - Ask "What is Nextcloud?"
   - Verify you get a response

2. **Admin Test**:
   - Go to **Settings → Administration → AIquila**
   - Click **Test Configuration**
   - Should see success message

3. **Assistant Integration**:
   - Use Nextcloud Assistant anywhere in the UI
   - Select "Claude (AIquila)" as the provider
   - Verify it responds to prompts

## Troubleshooting

### Common Issues

#### "Class does not exist" Errors

**Problem:** PHP can't find AIquila classes

**Solutions:**
```bash
# 1. Install Composer dependencies
cd /path/to/nextcloud/custom_apps/aiquila
composer install

# 2. Check vendor directory exists
ls -la vendor/

# 3. Verify autoloader was created
ls -la vendor/autoload.php

# 4. Fix file permissions
chmod 644 lib/**/*.php
find lib -type f -exec chmod 644 {} \;
```

#### JavaScript Not Loading

**Problem:** Page loads but interface doesn't appear

**Solutions:**
```bash
# 1. Build the frontend
cd /path/to/nextcloud/custom_apps/aiquila
npm run build

# 2. Verify build output
ls -la js/aiquila-main.js

# 3. Check browser console for errors
# Open developer tools (F12) and check Console tab

# 4. Fix JS file permissions
chmod 644 js/*.js
```

#### API Key Not Working

**Problem:** "Invalid API key" or authentication errors

**Solutions:**
1. Verify API key is valid at [console.anthropic.com](https://console.anthropic.com)
2. Check you copied the entire key (starts with `sk-ant-`)
3. Remove any extra spaces or newlines
4. Test configuration in admin settings
5. Check Nextcloud logs:
   ```bash
   tail -f /path/to/nextcloud/data/nextcloud.log
   ```

#### Network/Connection Errors

**Problem:** "Failed to connect" or timeout errors

**Solutions:**
1. Verify server can reach `api.anthropic.com`:
   ```bash
   curl -I https://api.anthropic.com
   ```
2. Check firewall rules allow HTTPS outbound
3. If using a proxy, configure PHP to use it
4. Increase timeout in admin settings (default: 30s)

#### Permission Errors

**Problem:** "Permission denied" when reading files

**Solution:**
```bash
# Fix all permissions at once
cd /path/to/nextcloud/custom_apps/aiquila
find . -type f -exec chmod 644 {} \;
find . -type d -exec chmod 755 {} \;

# Verify web server can read files
sudo -u www-data cat lib/AppInfo/Application.php
```

### Advanced Debugging

**Enable debug mode in Nextcloud:**

Edit `config/config.php`:
```php
'debug' => true,
'loglevel' => 0,
```

**Check Nextcloud logs:**
```bash
tail -f /path/to/nextcloud/data/nextcloud.log | grep -i aiquila
```

**Test Anthropic API directly:**
```bash
curl -X POST https://api.anthropic.com/v1/messages \
  -H "x-api-key: YOUR_API_KEY" \
  -H "anthropic-version: 2023-06-01" \
  -H "content-type: application/json" \
  -d '{
    "model": "claude-sonnet-4-5-20250929",
    "max_tokens": 100,
    "messages": [{"role": "user", "content": "Hello"}]
  }'
```

## Resources

Need help? Check out these resources:

- 📦 [GitHub Repository](https://github.com/elgorro/aiquila)
- 📖 [Documentation](https://github.com/elgorro/aiquila/tree/main/docs)
- 🐛 [Report Issues](https://github.com/elgorro/aiquila/issues)
- 💬 [Discussions](https://github.com/elgorro/aiquila/discussions)

## Next Steps

- [MCP Server Setup](mcp-installation.md) - Allow Claude Desktop to access your Nextcloud files
- [Internal API Guide](../internal-api.md) - Integrate AIquila into your own apps
- [Docker Development](../dev/docker-setup.md) - Set up complete development environment

## Getting Help

If you're still having issues:

1. Search existing [issues](https://github.com/elgorro/aiquila/issues)
2. Ask in [discussions](https://github.com/elgorro/aiquila/discussions)
3. Open a new issue with:
   - Nextcloud version (`Settings → Administration → Overview`)
   - PHP version (`php -v`)
   - Node.js version (`node -v`)
   - Complete error messages from logs
   - Steps to reproduce the problem
