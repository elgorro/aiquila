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
        // 34+; improve, subtitles, audio translation and multimodal chat with
        // tools are 35+. Where the task
        // type is not registered the provider is never offered.
        ReformatParagraphsProvider::class,
        ImproveProvider::class,
        SubtitlesProvider::class,
        AudioToAudioTranslateProvider::class,
        MultimodalChatWithToolsProvider::class,
    ];

    /**
     * Nextcloud task types AIquila deliberately does not serve, with the reason.
     *
     * Every task type the OCP package declares is either served by a provider
     * above or listed here; RegisteredProvidersTest fails on a type that is
     * neither, so a new Nextcloud major cannot add one unnoticed.
     *
     * @var array<string, string> task type id => reason
     */
    public const NOT_SERVED = [
        'core:contextagent:interaction' => 'Context Agent types run an agent loop over Nextcloud tools; the context_agent app owns that loop.',
        'core:contextagent:audio-interaction' => 'Context Agent types run an agent loop over Nextcloud tools; the context_agent app owns that loop.',
        'core:contextagent:multimodal-interaction' => 'Context Agent types run an agent loop over Nextcloud tools; the context_agent app owns that loop.',
    ];
}
