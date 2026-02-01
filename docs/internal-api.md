# AIquila Internal API

AIquila provides a public API that other Nextcloud apps can use to integrate Claude AI functionality.

## Overview

The AIquila service can be used from:
- Other Nextcloud apps
- Background jobs and workflows
- OCC commands
- Nextcloud Talk bots
- Any PHP code running within Nextcloud

## Getting Started

### Basic Usage

```php
<?php
// Get the AIquila service
$aiquila = \OC::$server->get(\OCA\AIquila\Public\IAIquila::class);

// Check if configured
if ($aiquila->isConfigured()) {
    // Ask Claude a question
    $result = $aiquila->ask(
        'What is the capital of France?',
        '',  // optional context
        'admin'  // user ID
    );

    if (isset($result['response'])) {
        echo $result['response'];  // "The capital of France is Paris."
    } else {
        echo 'Error: ' . $result['error'];
    }
}
```

## API Reference

### `ask(string $prompt, string $context = '', ?string $userId = null): array`

Ask Claude AI a question with optional context.

**Parameters:**
- `$prompt` (string) - The question to ask Claude
- `$context` (string, optional) - Additional context (e.g., file content, background info)
- `$userId` (string|null, optional) - User ID for user-specific API key, null for admin key

**Returns:**
- `array` - Returns `['response' => string]` on success or `['error' => string]` on failure

**Example:**
```php
$result = $aiquila->ask(
    'Summarize this document',
    file_get_contents('/path/to/document.txt'),
    'user123'
);
```

### `summarize(string $content, ?string $userId = null): array`

Summarize content using Claude AI.

**Parameters:**
- `$content` (string) - Content to summarize
- `$userId` (string|null, optional) - User ID for user-specific API key

**Returns:**
- `array` - Returns `['response' => string]` on success or `['error' => string]` on failure

**Example:**
```php
$longText = "...very long document...";
$result = $aiquila->summarize($longText, 'user123');
```

### `analyzeFile(string $filePath, string $prompt, ?string $userId = null): array`

Analyze a Nextcloud file with Claude AI.

**Parameters:**
- `$filePath` (string) - Nextcloud file path (e.g., `/Documents/report.pdf`)
- `$prompt` (string) - What to ask about the file
- `$userId` (string|null, optional) - User ID who owns/can access the file

**Returns:**
- `array` - Returns `['response' => string]` on success or `['error' => string]` on failure

**Example:**
```php
$result = $aiquila->analyzeFile(
    '/Documents/Q4-Report.pdf',
    'What are the key findings in this report?',
    'user123'
);
```

**Note:** File analysis is currently not fully implemented. Use `ask()` with file content for now.

### `isConfigured(?string $userId = null): bool`

Check if AIquila is configured and ready to use.

**Parameters:**
- `$userId` (string|null, optional) - User ID to check for user-specific configuration

**Returns:**
- `bool` - True if an API key is configured (either user or admin level)

**Example:**
```php
if ($aiquila->isConfigured('user123')) {
    // API is ready to use
} else {
    // Show configuration instructions
}
```

### `getStatus(): array`

Get current AIquila configuration status.

**Returns:**
- `array` - Configuration information:
  ```php
  [
      'configured' => bool,      // Whether an API key is set
      'model' => string,         // Claude model (e.g., 'claude-sonnet-4-20250514')
      'max_tokens' => int,       // Maximum tokens (1-100000)
      'timeout' => int           // API timeout in seconds (10-1800)
  ]
  ```

**Example:**
```php
$status = $aiquila->getStatus();
echo "Model: {$status['model']}\n";
echo "Max Tokens: {$status['max_tokens']}\n";
```

### `askAsync(string $prompt, string $context, string $userId, bool $notify = true): array`

Process a Claude request asynchronously (for long-running operations).

Useful for large documents or complex analysis that might timeout. User will receive a notification when complete.

**Parameters:**
- `$prompt` (string) - The prompt to send to Claude
- `$context` (string) - Optional context
- `$userId` (string) - User ID to notify on completion
- `$notify` (bool, optional) - Whether to send notification (default: true)

**Returns:**
- `array` - Returns `['status' => 'queued', 'message' => string]` or error

**Example:**
```php
$result = $aiquila->askAsync(
    'Analyze this large dataset',
    file_get_contents('/path/to/large-file.csv'),
    'user123',
    true  // send notification
);
```

**Note:** Currently runs synchronously. Background job support coming soon.

## Use Cases

### 1. Document Analysis in Files App

```php
// In your app's file action handler
$aiquila = \OC::$server->get(\OCA\AIquila\Public\IAIquila::class);

$fileContent = $this->readFile($filePath);
$result = $aiquila->ask(
    'Summarize the main points of this document',
    $fileContent,
    $userId
);

// Display result to user
return new JSONResponse($result);
```

### 2. Nextcloud Talk Bot

```php
// In a Talk bot message handler
$aiquila = \OC::$server->get(\OCA\AIquila\Public\IAIquila::class);

if ($aiquila->isConfigured()) {
    $response = $aiquila->ask($userMessage, '', $userId);
    $this->sendTalkMessage($response['response']);
}
```

### 3. Workflow Integration

```php
// In a workflow app
$aiquila = \OC::$server->get(\OCA\AIquila\Public\IAIquila::class);

// Analyze uploaded document
$result = $aiquila->summarize($documentContent, $userId);

// Tag document based on summary
if (isset($result['response'])) {
    $this->tagDocument($documentId, $result['response']);
}
```

### 4. Background Job

```php
<?php
namespace OCA\MyApp\BackgroundJob;

use OCA\AIquila\Public\IAIquila;
use OCP\BackgroundJob\QueuedJob;

class AnalyzeDocumentJob extends QueuedJob {
    protected function run($argument) {
        $aiquila = \OC::$server->get(IAIquila::class);

        $result = $aiquila->ask(
            'Analyze this document: ' . $argument['prompt'],
            $argument['content'],
            $argument['userId']
        );

        // Store or process result
    }
}
```

## Configuration

### Admin Configuration

Administrators can configure AIquila via:

**Web UI:**
- Settings → Administration → AIquila

**OCC Command:**
```bash
php occ aiquila:configure --api-key "sk-ant-..." \
  --model "claude-sonnet-4-20250514" \
  --max-tokens 8192 \
  --timeout 60
```

### User Configuration

Users can set their own API keys:
- Settings → Personal → AIquila

This allows users to use their own Claude API keys for requests.

## Error Handling

Always check for errors in the response:

```php
$result = $aiquila->ask($prompt, $context, $userId);

if (isset($result['error'])) {
    // Handle error
    \OCP\Util::writeLog('myapp', 'AIquila error: ' . $result['error'], \OCP\Util::ERROR);

    if ($result['error'] === 'No API key configured') {
        // Prompt user to configure API key
    } else if (strpos($result['error'], 'Rate limit') !== false) {
        // Handle rate limit
    } else {
        // Generic error handling
    }
} else {
    // Use response
    $response = $result['response'];
}
```

## Rate Limiting

AIquila implements rate limiting:
- **10 requests per minute** per user
- Returns `429` status code when exceeded
- Error message: "Rate limit exceeded. Maximum 10 requests per minute."

Handle rate limits gracefully in your app:

```php
if (isset($result['error']) && strpos($result['error'], 'Rate limit') !== false) {
    // Wait and retry, or show user-friendly message
    sleep(60);  // Wait 1 minute
    $result = $aiquila->ask($prompt, $context, $userId);
}
```

## Content Size Limits

- Maximum content size: **5MB** (5,242,880 bytes)
- Includes prompt + context combined
- Error: "Content too large. Maximum size is 5MB"

For large files, consider:
1. Chunking content
2. Using async processing
3. Extracting key sections only

## Security Considerations

1. **API Keys:**
   - Admin keys are stored in Nextcloud config
   - User keys are encrypted in database
   - Never expose API keys in responses

2. **User Context:**
   - Always pass `$userId` when processing user data
   - Respects user-specific API keys
   - Logs requests with user context

3. **Input Validation:**
   - Content length validated automatically
   - Rate limiting prevents abuse
   - All inputs sanitized before sending to Claude

## Logging

AIquila logs all requests:

```bash
# View logs
sudo -u www-data tail -f /var/www/nextcloud/data/nextcloud.log | grep AIquila

# Or via OCC
php occ log:watch | grep AIquila
```

Log levels:
- `INFO` - Successful requests
- `ERROR` - API errors, exceptions
- `DEBUG` - Detailed request/response data (when debug mode enabled)

## Testing

Test the API in your app:

```php
// Check if available
$aiquila = \OC::$server->query(\OCA\AIquila\Public\IAIquila::class);
if ($aiquila === null) {
    throw new \Exception('AIquila app not installed');
}

// Check if configured
if (!$aiquila->isConfigured()) {
    throw new \Exception('AIquila not configured');
}

// Simple test
$result = $aiquila->ask('Test: respond with OK', '', 'admin');
assert(isset($result['response']));
```

## Support

For issues or questions:
- GitHub: https://github.com/yourrepo/aiquila
- Nextcloud Forum: https://help.nextcloud.com
- Documentation: https://github.com/yourrepo/aiquila/tree/main/docs

## Changelog

### v0.1.1
- Added public IAIquila interface
- Added AIquilaService implementation
- Support for ask(), summarize(), analyzeFile()
- Configuration checking with isConfigured()
- Status reporting with getStatus()
- Async processing support (experimental)
