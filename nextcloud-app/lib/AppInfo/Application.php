<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\AIquila\AppInfo;

use OCA\AIquila\Cowork\CoworkerTaskRegistry;
use OCA\AIquila\Cowork\DocsSummarizeFolderTaskType;
use OCA\AIquila\Cowork\DocsTranslateFolderTaskType;
use OCA\AIquila\Cowork\VisionClassifyImagesTaskType;
use OCA\AIquila\Public\IAIquila;
use OCA\AIquila\Public\ICoworkManager;
use OCA\AIquila\Service\AIquilaService;
use OCA\AIquila\Service\CoworkManager;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use Psr\Container\ContainerInterface;

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

        // Public Cowork management API for other Nextcloud apps
        $context->registerService(ICoworkManager::class, function ($c) {
            return $c->get(CoworkManager::class);
        });


        // Expose app capabilities via /ocs/v2.php/cloud/capabilities
        $context->registerCapability(\OCA\AIquila\Capabilities\AIquilaCapability::class);

        // Dashboard widgets — conversations, coworkers, and token usage
        $context->registerDashboardWidget(\OCA\AIquila\Dashboard\ConversationsWidget::class);
        $context->registerDashboardWidget(\OCA\AIquila\Dashboard\CoworkersWidget::class);
        $context->registerDashboardWidget(\OCA\AIquila\Dashboard\UsageWidget::class);
        $context->registerDashboardWidget(\OCA\AIquila\Dashboard\CoworkerOutputWidget::class);

        // Cowork task-type registry — the set of jobs coworkers can run
        $context->registerService(CoworkerTaskRegistry::class, function (ContainerInterface $c) {
            $visionClassify = $c->get(VisionClassifyImagesTaskType::class);
            assert($visionClassify instanceof VisionClassifyImagesTaskType);
            $docsSummarize = $c->get(DocsSummarizeFolderTaskType::class);
            assert($docsSummarize instanceof DocsSummarizeFolderTaskType);
            $docsTranslate = $c->get(DocsTranslateFolderTaskType::class);
            assert($docsTranslate instanceof DocsTranslateFolderTaskType);

            return new CoworkerTaskRegistry([$visionClassify, $docsSummarize, $docsTranslate]);
        });

        // Register AIquila TaskProcessing Providers for Nextcloud Assistant integration
        // Vision providers
        $context->registerTaskProcessingProvider(\OCA\AIquila\TaskProcessing\ImageToTextProvider::class);
        $context->registerTaskProcessingProvider(\OCA\AIquila\TaskProcessing\AnalyzeImagesProvider::class);

        // Audio and image-generation providers. Only providers declaring the
        // matching capability can serve these; the resolver fails with a message
        // naming the alternatives when the user's provider cannot.
        $context->registerTaskProcessingProvider(\OCA\AIquila\TaskProcessing\AudioToTextProvider::class);
        $context->registerTaskProcessingProvider(\OCA\AIquila\TaskProcessing\TextToSpeechProvider::class);
        $context->registerTaskProcessingProvider(\OCA\AIquila\TaskProcessing\TextToImageProvider::class);
        $context->registerTaskProcessingProvider(\OCA\AIquila\TaskProcessing\AudioToAudioChatProvider::class);

        // Text-to-text providers
        $context->registerTaskProcessingProvider(\OCA\AIquila\TaskProcessing\TextToTextProvider::class);
        $context->registerTaskProcessingProvider(\OCA\AIquila\TaskProcessing\SummaryProvider::class);
        $context->registerTaskProcessingProvider(\OCA\AIquila\TaskProcessing\HeadlineProvider::class);
        $context->registerTaskProcessingProvider(\OCA\AIquila\TaskProcessing\TopicsProvider::class);
        $context->registerTaskProcessingProvider(\OCA\AIquila\TaskProcessing\TranslateProvider::class);
        $context->registerTaskProcessingProvider(\OCA\AIquila\TaskProcessing\ProofreadProvider::class);
        $context->registerTaskProcessingProvider(\OCA\AIquila\TaskProcessing\ChangeToneProvider::class);
        $context->registerTaskProcessingProvider(\OCA\AIquila\TaskProcessing\SimplificationProvider::class);
        $context->registerTaskProcessingProvider(\OCA\AIquila\TaskProcessing\ReformulationProvider::class);
        $context->registerTaskProcessingProvider(\OCA\AIquila\TaskProcessing\FormalizationProvider::class);
        $context->registerTaskProcessingProvider(\OCA\AIquila\TaskProcessing\ChatProvider::class);
        $context->registerTaskProcessingProvider(\OCA\AIquila\TaskProcessing\ContextWriteProvider::class);
        $context->registerTaskProcessingProvider(\OCA\AIquila\TaskProcessing\GenerateEmojiProvider::class);

        // Nextcloud 34+ only; on 33 the task type is not registered and this
        // provider is never offered.
        $context->registerTaskProcessingProvider(\OCA\AIquila\TaskProcessing\ReformatParagraphsProvider::class);

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

        // Feed conversations into Context Chat (optional app) for the Assistant.
        // The event only fires when Context Chat is installed, so this is inert otherwise.
        $context->registerEventListener(
            \OCP\ContextChat\Events\ContentProviderRegisterEvent::class,
            \OCA\AIquila\Listener\ContextChatProviderListener::class
        );
    }

    public function boot(IBootContext $context): void {
        // Will be used to load app's main script once we build it
    }
}
