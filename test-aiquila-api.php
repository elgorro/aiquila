#!/usr/bin/env php
<?php
/**
 * Test script for AIquila Internal API
 *
 * This script tests the internal AIquila service by analyzing
 * the Nextcloud README.md file.
 *
 * Usage:
 *   docker cp test-aiquila-api.php aiquila-nextcloud:/var/www/html/
 *   docker exec -u www-data aiquila-nextcloud php /var/www/html/test-aiquila-api.php
 */

// Bootstrap Nextcloud
require_once __DIR__ . '/lib/base.php';

echo "===========================================\n";
echo "AIquila Internal API Test\n";
echo "===========================================\n\n";

try {
    // Get the AIquila service
    echo "1. Getting AIquila service...\n";
    $aiquila = \OC::$server->get(\OCA\AIquila\Public\IAIquila::class);
    echo "   ✓ Service loaded successfully\n\n";

    // Check if configured
    echo "2. Checking configuration...\n";
    if (!$aiquila->isConfigured()) {
        echo "   ✗ AIquila is not configured!\n";
        echo "   Please configure an API key first:\n";
        echo "   php occ aiquila:configure --api-key \"sk-ant-...\"\n\n";
        exit(1);
    }
    echo "   ✓ AIquila is configured\n\n";

    // Get status
    echo "3. Getting status...\n";
    $status = $aiquila->getStatus();
    echo "   Configured: " . ($status['configured'] ? 'Yes' : 'No') . "\n";
    echo "   Model: {$status['model']}\n";
    echo "   Max Tokens: {$status['max_tokens']}\n";
    echo "   Timeout: {$status['timeout']}s\n\n";

    // Read test README
    echo "4. Reading test file...\n";
    $readmePath = '/tmp/test-readme.md';
    if (!file_exists($readmePath)) {
        echo "   ✗ Test file not found at {$readmePath}\n";
        exit(1);
    }
    $content = file_get_contents($readmePath);
    $contentSize = strlen($content);
    echo "   ✓ Read {$contentSize} bytes\n\n";

    // Test 1: Simple ask
    echo "5. Test: Simple question...\n";
    $result1 = $aiquila->ask(
        'What is Nextcloud in one sentence?',
        '',
        'admin'
    );

    if (isset($result1['error'])) {
        echo "   ✗ Error: {$result1['error']}\n\n";
    } else {
        echo "   ✓ Response received:\n";
        echo "   " . wordwrap($result1['response'], 70, "\n   ") . "\n\n";
    }

    // Test 2: Summarize README
    echo "6. Test: Summarize README.md...\n";
    $result2 = $aiquila->summarize(
        substr($content, 0, 5000),  // First 5000 chars to avoid hitting limits
        'admin'
    );

    if (isset($result2['error'])) {
        echo "   ✗ Error: {$result2['error']}\n\n";
    } else {
        echo "   ✓ Summary received:\n";
        echo "   " . wordwrap($result2['response'], 70, "\n   ") . "\n\n";
    }

    // Test 3: Ask about README with context
    echo "7. Test: Ask question about README...\n";
    $result3 = $aiquila->ask(
        'What are the main features mentioned in this README?',
        substr($content, 0, 3000),  // Provide context
        'admin'
    );

    if (isset($result3['error'])) {
        echo "   ✗ Error: {$result3['error']}\n\n";
    } else {
        echo "   ✓ Response received:\n";
        echo "   " . wordwrap($result3['response'], 70, "\n   ") . "\n\n";
    }

    echo "===========================================\n";
    echo "All tests completed!\n";
    echo "===========================================\n";

} catch (\Exception $e) {
    echo "\n✗ Exception: " . $e->getMessage() . "\n";
    echo "Stack trace:\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
