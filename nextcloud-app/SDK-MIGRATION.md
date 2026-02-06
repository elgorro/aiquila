# Anthropic PHP SDK Migration Guide

## Overview

AIquila is migrating from manual HTTP requests to the official **Anthropic PHP SDK** for better error handling, type safety, and feature support.

## Quick Start (TL;DR)

```bash
# 1. Install Composer in Docker (requires sudo password)
sudo docker exec -it -u root aiquila-nextcloud bash -c 'curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer && composer --version'

# 2. Install Anthropic SDK
sudo docker exec -u www-data aiquila-nextcloud bash -c 'cd /var/www/html/custom_apps/aiquila && composer install --no-dev'

# 3. Verify installation
sudo docker exec -u www-data aiquila-nextcloud bash -c 'cd /var/www/html/custom_apps/aiquila && ls -la vendor/anthropic-ai'
```

## Installation

### Prerequisites

- PHP 8.1 or higher ✅ (Nextcloud 31/32 compatible)
- Composer package manager

### Step 1: Install Composer (if not already installed)

#### On Nextcloud Docker Container

**Note:** This requires sudo/root access on your host system.

```bash
# Enter container as root (requires sudo password)
sudo docker exec -it -u root aiquila-nextcloud bash

# Install Composer inside the container
curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Verify installation
composer --version

# Exit container
exit
```

#### On Host System

**Arch Linux:**
```bash
sudo pacman -S composer
```

**Debian/Ubuntu:**
```bash
sudo apt install composer
```

**macOS:**
```bash
brew install composer
```

### Step 2: Install Anthropic SDK

```bash
cd /path/to/aiquila/nextcloud-app
composer install --no-dev
```

Or in Docker:
```bash
docker exec -u www-data aiquila-nextcloud bash -c 'cd /var/www/html/custom_apps/aiquila && composer install --no-dev'
```

This will install:
- `anthropic-ai/sdk` (^0.4.0)
- Required PSR dependencies (psr/http-client, psr/http-message)
- HTTP discovery package (php-http/discovery)

### Step 3: Copy vendor Directory (Production Deployment)

When deploying to production, include the `vendor/` directory:

```bash
# After composer install, the structure should be:
nextcloud-app/
├── composer.json
├── composer.lock
├── vendor/              ← SDK and dependencies
│   ├── anthropic-ai/
│   ├── psr/
│   └── autoload.php
└── lib/
    └── Service/
        ├── ClaudeService.php        ← Old (HTTP client)
        └── ClaudeSDKService.php     ← New (Anthropic SDK)
```

## Migration Status

### ✅ Completed

1. **composer.json** - Added SDK dependency
2. **ClaudeSDKService.php** - New service using official SDK
3. **Streaming support** - `askStream()` method for real-time responses

### 🔄 In Progress

4. **SDK Installation** - Need to install Composer first
5. **Controller Migration** - Update controllers to use new service

### ⏳ Pending

6. **Full Integration** - Replace old ClaudeService
7. **Testing** - Verify all functionality works
8. **Documentation** - Update README and API docs

## New Features with SDK

### 1. Better Error Handling

**Before (HTTP Client):**
```php
catch (\Exception $e) {
    return ['error' => $e->getMessage()];
}
```

**After (SDK):**
```php
catch (AuthenticationException $e) {
    return ['error' => 'Invalid API key...'];
} catch (RateLimitException $e) {
    return ['error' => 'Rate limit exceeded...'];
} catch (APIConnectionException $e) {
    return ['error' => 'Connection failed...'];
}
```

### 2. Type Safety

**Before:**
```php
$data = json_decode($response->getBody(), true);
$text = $data['content'][0]['text'] ?? ''; // Fragile
```

**After:**
```php
$response = $client->messages()->create([...]);
$text = $response->content[0]->text; // Type-safe
```

### 3. Streaming Support (NEW!)

```php
// Stream real-time responses
foreach ($service->askStream('Your question', '', 'user123') as $chunk) {
    echo $chunk; // Output as it arrives
    flush();
}
```

### 4. Automatic Retries

The SDK automatically retries:
- Network failures
- Timeouts (408)
- Rate limits (429) with exponential backoff
- Server errors (5xx)

Default: 2 retries with smart backoff.

### 5. Logging

The SDK service includes comprehensive logging:
- Debug: Request parameters
- Info: Successful responses with token usage
- Error: Specific error types with details
- Warning: Rate limits

## Usage Comparison

### Old Service (ClaudeService)

```php
use OCA\AIquila\Service\ClaudeService;

$claudeService = new ClaudeService($config, $clientService);
$result = $claudeService->ask('Hello Claude', '', 'admin');

if (isset($result['error'])) {
    // Generic error handling
    echo $result['error'];
} else {
    echo $result['response'];
}
```

### New Service (ClaudeSDKService)

```php
use OCA\AIquila\Service\ClaudeSDKService;

$claudeService = new ClaudeSDKService($config, $logger);
$result = $claudeService->ask('Hello Claude', '', 'admin');

if (isset($result['error'])) {
    // Specific, actionable errors
    echo $result['error'];
} else {
    echo $result['response'];
}

// OR use streaming for better UX:
foreach ($claudeService->askStream('Hello Claude', '', 'admin') as $chunk) {
    echo $chunk;
}
```

## Backwards Compatibility

The old `ClaudeService` is **kept** during migration to ensure:
- No breaking changes
- Gradual rollout
- Ability to rollback if needed

Both services implement the same interface, so controllers can switch easily.

## Testing

### Test SDK Service

```bash
# Via OCC command (once SDK is installed)
php occ aiquila:test --prompt "Test SDK implementation"

# Check logs
tail -f /var/www/html/data/nextcloud.log | grep "AIquila SDK"
```

### Expected Log Output

```
AIquila SDK: Sending request {"model":"claude-sonnet-4-5-20250929","max_tokens":4096}
AIquila SDK: Successful response {"stop_reason":"end_turn","usage":{"input_tokens":12,"output_tokens":25}}
```

## Deployment Checklist

- [ ] Install Composer on server/container
- [ ] Run `composer install --no-dev`
- [ ] Verify `vendor/` directory exists
- [ ] Test with OCC command
- [ ] Update controllers one by one
- [ ] Monitor logs for errors
- [ ] Remove old `ClaudeService` when stable

## Troubleshooting

### Composer Not Found

```bash
# Install Composer first
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
```

### SDK Class Not Found

```bash
# Regenerate autoload files
composer dump-autoload
```

### Permission Errors

```bash
# Fix ownership
chown -R www-data:www-data /path/to/nextcloud/apps/aiquila/vendor
```

## Benefits Summary

| Aspect | Old (HTTP) | New (SDK) |
|--------|-----------|-----------|
| **Code Lines** | ~40 per request | ~15 per request |
| **Error Detail** | Generic | Specific exceptions |
| **Type Safety** | No | Yes (IDE autocomplete) |
| **Retry Logic** | Manual | Automatic |
| **Streaming** | Not supported | Built-in |
| **Maintenance** | Your responsibility | Anthropic maintains |
| **API Updates** | Manual tracking | Automatic via SDK |

## Resources

- [Anthropic PHP SDK](https://github.com/anthropics/anthropic-sdk-php)
- [API Documentation](https://docs.anthropic.com/en/api)
- [Composer Documentation](https://getcomposer.org/doc/)
