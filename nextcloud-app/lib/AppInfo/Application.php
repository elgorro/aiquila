<?php

namespace OCA\AIquila\AppInfo;

use OCA\AIquila\Public\IAIquila;
use OCA\AIquila\Service\AIquilaService;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Util;

class Application extends App implements IBootstrap {
    public const APP_ID = 'aiquila';

    public function __construct(array $params = []) {
        parent::__construct(self::APP_ID, $params);

        // Load Composer autoloader for Anthropic SDK
        $vendorAutoload = __DIR__ . '/../../vendor/autoload.php';
        if (file_exists($vendorAutoload)) {
            require_once $vendorAutoload;
        }
    }

    public function register(IRegistrationContext $context): void {
        // Register AIquila service for use by other apps
        $context->registerService(IAIquila::class, function ($c) {
            return $c->get(AIquilaService::class);
        });

        // Register Claude Text Processing Providers for native Nextcloud Assistant integration
        // Wrapped in a check so the app degrades gracefully on NC versions where this API was removed (NC33+)
        if (interface_exists(\OCP\TextProcessing\IProvider::class)) {
            $context->registerTextProcessingProvider(\OCA\AIquila\TextProcessing\ClaudeProvider::class);
            $context->registerTextProcessingProvider(\OCA\AIquila\TextProcessing\ClaudeSummaryProvider::class);
            $context->registerTextProcessingProvider(\OCA\AIquila\TextProcessing\ClaudeHeadlineProvider::class);
            $context->registerTextProcessingProvider(\OCA\AIquila\TextProcessing\ClaudeTopicsProvider::class);
        }

        // Register Claude Vision TaskProcessing Provider (NC 29+) for image-to-text tasks
        // Wrapped in a check so the app degrades gracefully on older NC versions
        if (interface_exists(\OCP\TaskProcessing\IProvider::class)) {
            $context->registerTaskProcessingProvider(\OCA\AIquila\TaskProcessing\ClaudeImageToTextProvider::class);
        }
    }

    public function boot(IBootContext $context): void {
        // Will be used to load app's main script once we build it
    }
}
