<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\AIquila\AppInfo;

use OCA\AIquila\Cowork\CoworkerTaskRegistry;
use OCA\AIquila\Cowork\VisionClassifyImagesTaskType;
use OCA\AIquila\Public\IAIquila;
use OCA\AIquila\Service\AIquilaService;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
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

        // Declarative Settings — admin page split into model / request params / search cards
        $context->registerDeclarativeSettings(\OCA\AIquila\Settings\AdminModelDeclarativeSettings::class);
        $context->registerDeclarativeSettings(\OCA\AIquila\Settings\AdminRequestParamsDeclarativeSettings::class);
        $context->registerDeclarativeSettings(\OCA\AIquila\Settings\AdminSearchDeclarativeSettings::class);
        $context->registerDeclarativeSettings(\OCA\AIquila\Settings\UserDeclarativeSettings::class);

        // Expose app capabilities via /ocs/v2.php/cloud/capabilities
        $context->registerCapability(\OCA\AIquila\Capabilities\AIquilaCapability::class);

        // Cowork task-type registry — the set of jobs coworkers can run
        $context->registerService(CoworkerTaskRegistry::class, function ($c) {
            return new CoworkerTaskRegistry([
                $c->get(VisionClassifyImagesTaskType::class),
            ]);
        });

        // Register Claude TaskProcessing Providers for Nextcloud Assistant integration
        // Vision providers
        $context->registerTaskProcessingProvider(\OCA\AIquila\TaskProcessing\ClaudeImageToTextProvider::class);
        $context->registerTaskProcessingProvider(\OCA\AIquila\TaskProcessing\ClaudeAnalyzeImagesProvider::class);

        // Text-to-text providers
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

        // Register notification formatter for AIquila task notifications
        $context->registerNotifierService(\OCA\AIquila\Notifier\AIquilaNotifier::class);

        // Listen for task processing completion events to notify users
        $context->registerEventListener(
            \OCP\TaskProcessing\Events\TaskSuccessfulEvent::class,
            \OCA\AIquila\Listener\TaskSuccessfulListener::class
        );
        $context->registerEventListener(
            \OCP\TaskProcessing\Events\TaskFailedEvent::class,
            \OCA\AIquila\Listener\TaskFailedListener::class
        );

        // Register setup checks for admin overview (Settings → Overview)
        $context->registerSetupCheck(\OCA\AIquila\SetupCheck\ApiKeyConfigured::class);
        $context->registerSetupCheck(\OCA\AIquila\SetupCheck\AnthropicApiReachable::class);
        $context->registerSetupCheck(\OCA\AIquila\SetupCheck\PhpExtensions::class);

        // Register Unified Search provider for AIquila chat conversations
        $context->registerSearchProvider(\OCA\AIquila\Search\AiquilaSearchProvider::class);
    }

    public function boot(IBootContext $context): void {
        // Will be used to load app's main script once we build it
    }
}
