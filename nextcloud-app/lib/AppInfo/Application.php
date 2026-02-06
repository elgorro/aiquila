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

    public function __construct() {
        parent::__construct(self::APP_ID);

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

        // Register Claude Text Processing Provider for native Nextcloud Assistant integration
        $context->registerTextProcessingProvider(\OCA\AIquila\TextProcessing\ClaudeProvider::class);
    }

    public function boot(IBootContext $context): void {
        // Will be used to load app's main script once we build it
    }
}
