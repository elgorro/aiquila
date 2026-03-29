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

        // Expose app capabilities via /ocs/v2.php/cloud/capabilities
        $context->registerCapability(\OCA\AIquila\Capabilities\AIquilaCapability::class);

        // Register Claude Text Processing Providers for native Nextcloud Assistant integration
        // Wrapped in a check so the app degrades gracefully on NC versions where this API was removed (NC33+)
        if (interface_exists(\OCP\TextProcessing\IProvider::class)) {
            $context->registerTextProcessingProvider(\OCA\AIquila\TextProcessing\ClaudeProvider::class);
            $context->registerTextProcessingProvider(\OCA\AIquila\TextProcessing\ClaudeSummaryProvider::class);
            $context->registerTextProcessingProvider(\OCA\AIquila\TextProcessing\ClaudeHeadlineProvider::class);
            $context->registerTextProcessingProvider(\OCA\AIquila\TextProcessing\ClaudeTopicsProvider::class);
        }

        // Register Claude TaskProcessing Providers (NC 30+)
        // These integrate with the Nextcloud Assistant for vision, text generation, and more
        if (interface_exists(\OCP\TaskProcessing\ISynchronousProvider::class)) {
            // Vision providers
            $context->registerTaskProcessingProvider(\OCA\AIquila\TaskProcessing\ClaudeImageToTextProvider::class);
            $context->registerTaskProcessingProvider(\OCA\AIquila\TaskProcessing\ClaudeAnalyzeImagesProvider::class);

            // Text-to-text providers (successor to TextProcessing API)
            $context->registerTaskProcessingProvider(\OCA\AIquila\TaskProcessing\ClaudeTextToTextProvider::class);
            $context->registerTaskProcessingProvider(\OCA\AIquila\TaskProcessing\ClaudeSummaryProvider::class);
            $context->registerTaskProcessingProvider(\OCA\AIquila\TaskProcessing\ClaudeHeadlineProvider::class);
            $context->registerTaskProcessingProvider(\OCA\AIquila\TaskProcessing\ClaudeTopicsProvider::class);
            $context->registerTaskProcessingProvider(\OCA\AIquila\TaskProcessing\ClaudeTranslateProvider::class);
            $context->registerTaskProcessingProvider(\OCA\AIquila\TaskProcessing\ClaudeProofreadProvider::class);
            $context->registerTaskProcessingProvider(\OCA\AIquila\TaskProcessing\ClaudeChangeToneProvider::class);
            $context->registerTaskProcessingProvider(\OCA\AIquila\TaskProcessing\ClaudeSimplificationProvider::class);
            $context->registerTaskProcessingProvider(\OCA\AIquila\TaskProcessing\ClaudeReformulationProvider::class);
            $context->registerTaskProcessingProvider(\OCA\AIquila\TaskProcessing\ClaudeFormalizationProvider::class);
        }

        // Register Unified Search provider for AIquila chat conversations
        if (interface_exists(\OCP\Search\IProvider::class)) {
            $context->registerSearchProvider(\OCA\AIquila\Search\AiquilaSearchProvider::class);
        }
    }

    public function boot(IBootContext $context): void {
        // Will be used to load app's main script once we build it
    }
}
