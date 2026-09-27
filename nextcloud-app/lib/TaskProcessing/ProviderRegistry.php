<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\TaskProcessing;

use OCP\TaskProcessing\ISynchronousProvider;

/**
 * The TaskProcessing providers AIquila registers with Nextcloud.
 *
 * Application::register() registers exactly this list, and AIquilaCapability
 * derives the published task types from it, so adding a provider here is the
 * only edit either needs.
 */
final class ProviderRegistry {
    /** @var list<class-string<ISynchronousProvider>> */
    public const PROVIDERS = [
        // Vision providers
        ImageToTextProvider::class,
        AnalyzeImagesProvider::class,

        // Audio and image-generation providers. Only providers declaring the
        // matching capability can serve these; the resolver fails with a message
        // naming the alternatives when the user's provider cannot.
        AudioToTextProvider::class,
        TextToSpeechProvider::class,
        TextToImageProvider::class,
        AudioToAudioChatProvider::class,

        // Text-to-text providers
        TextToTextProvider::class,
        SummaryProvider::class,
        HeadlineProvider::class,
        TopicsProvider::class,
        TranslateProvider::class,
        ProofreadProvider::class,
        ChangeToneProvider::class,
        SimplificationProvider::class,
        ReformulationProvider::class,
        FormalizationProvider::class,
        ChatProvider::class,
        ChatWithToolsProvider::class,
        ContextWriteProvider::class,
        GenerateEmojiProvider::class,

        // Newer than the declared min-version: reformatparagraphs is Nextcloud
        // 34+; improve, subtitles and audio translation are 35+. Where the task
        // type is not registered the provider is never offered.
        ReformatParagraphsProvider::class,
        ImproveProvider::class,
        SubtitlesProvider::class,
        AudioToAudioTranslateProvider::class,
    ];
}
