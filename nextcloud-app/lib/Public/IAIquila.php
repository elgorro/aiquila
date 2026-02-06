<?php

namespace OCA\AIquila\Public;

/**
 * Public AIquila API Interface
 *
 * This interface can be used by other Nextcloud apps to integrate
 * Claude AI functionality.
 *
 * Example usage from another app:
 *
 * $aiquila = \OC::$server->get(\OCA\AIquila\Public\IAIquila::class);
 * if ($aiquila->isConfigured()) {
 *     $result = $aiquila->ask('Summarize this text', 'Text content here', 'user123');
 *     if (isset($result['response'])) {
 *         // Use the response
 *     }
 * }
 */
interface IAIquila {
    /**
     * Ask Claude AI a question with optional context
     *
     * @param string $prompt The question to ask Claude
     * @param string $context Optional context (e.g., file content, background info)
     * @param string|null $userId User ID (for user-specific API key, null for admin key)
     * @return array Returns ['response' => string] on success or ['error' => string] on failure
     */
    public function ask(string $prompt, string $context = '', ?string $userId = null): array;

    /**
     * Summarize content using Claude AI
     *
     * @param string $content Content to summarize
     * @param string|null $userId User ID (for user-specific API key, null for admin key)
     * @return array Returns ['response' => string] on success or ['error' => string] on failure
     */
    public function summarize(string $content, ?string $userId = null): array;

    /**
     * Analyze a Nextcloud file with Claude AI
     *
     * @param string $filePath Nextcloud file path (e.g., '/Documents/report.pdf')
     * @param string $prompt What to ask about the file
     * @param string|null $userId User ID who owns/can access the file
     * @return array Returns ['response' => string] on success or ['error' => string] on failure
     */
    public function analyzeFile(string $filePath, string $prompt, ?string $userId = null): array;

    /**
     * Check if AIquila is configured and ready to use
     *
     * @param string|null $userId Optional user ID to check for user-specific configuration
     * @return bool True if an API key is configured (either user or admin level)
     */
    public function isConfigured(?string $userId = null): bool;

    /**
     * Get current AIquila configuration status
     *
     * @return array Configuration information:
     *  [
     *      'configured' => bool,
     *      'model' => string,
     *      'max_tokens' => int,
     *      'timeout' => int
     *  ]
     */
    public function getStatus(): array;

    /**
     * Process a Claude request asynchronously (for long-running operations)
     *
     * Useful for large documents or complex analysis that might timeout.
     * User will receive a notification when complete.
     *
     * @param string $prompt The prompt to send to Claude
     * @param string $context Optional context
     * @param string $userId User ID to notify on completion
     * @param bool $notify Whether to send notification (default: true)
     * @return array Returns ['status' => 'queued', 'message' => string] or error
     */
    public function askAsync(string $prompt, string $context, string $userId, bool $notify = true): array;
}
