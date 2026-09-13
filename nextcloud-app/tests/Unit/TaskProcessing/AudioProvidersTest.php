<?php

namespace OCA\AIquila\Tests\Unit\TaskProcessing;

use OCA\AIquila\Service\AudioLimits;
use OCA\AIquila\Service\Provider\LLMProviderFactory;
use OCA\AIquila\Service\Provider\LLMProviderInterface;
use OCA\AIquila\Service\Provider\ProviderSettingsSchema;
use OCA\AIquila\TaskProcessing\AudioToAudioChatProvider;
use OCA\AIquila\TaskProcessing\AudioToTextProvider;
use OCA\AIquila\TaskProcessing\ProviderResolver;
use OCA\AIquila\TaskProcessing\TextToImageProvider;
use OCA\AIquila\TaskProcessing\TextToSpeechProvider;
use OCP\Files\File;
use OCP\TaskProcessing\TaskTypes\AudioToAudioChat;
use OCP\TaskProcessing\TaskTypes\AudioToText;
use OCP\TaskProcessing\TaskTypes\TextToImage;
use OCP\TaskProcessing\TaskTypes\TextToSpeech;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class AudioProvidersTest extends TestCase {
    private const MP3 = "ID3\x03 raw bytes";
    private const WAV_AUDIO = "RIFF generated audio";

    private $factory;
    private ProviderResolver $resolver;

    protected function setUp(): void {
        $this->factory = $this->createMock(LLMProviderFactory::class);
        $this->resolver = new ProviderResolver($this->factory);
    }

    /**
     * The framework hands File nodes to process(), never raw bytes — every
     * Audio-typed input slot is resolved by Manager::fillInputFileData().
     */
    private function audioFile(string $name = 'memo.mp3', string $mime = 'audio/mpeg', int $size = 2048) {
        $file = $this->createMock(File::class);
        $file->method('getId')->willReturn(7);
        $file->method('getName')->willReturn($name);
        $file->method('getContent')->willReturn(self::MP3);
        $file->method('getMimetype')->willReturn($mime);
        $file->method('getSize')->willReturn($size);
        return $file;
    }

    /** @param array<string, bool> $capabilities */
    private function provider(array $capabilities, string $id = 'mistral', string $label = 'Mistral') {
        $provider = $this->createMock(LLMProviderInterface::class);
        $provider->method('getId')->willReturn($id);
        $provider->method('getLabel')->willReturn($label);
        $provider->method('getCapabilities')->willReturn(ProviderSettingsSchema::capabilities($capabilities));
        $provider->method('isConfigured')->willReturn(true);
        return $provider;
    }

    private function serve($provider): void {
        $this->factory->method('getProviderForUser')->willReturn($provider);
        $this->factory->method('getProviderIdsForUser')->willReturn(['mistral']);
        $this->factory->method('getProviderById')->willReturnMap([['mistral', $provider]]);
    }

    private function audioToText(): AudioToTextProvider {
        return new AudioToTextProvider($this->resolver, new NullLogger());
    }

    private function textToSpeech(): TextToSpeechProvider {
        return new TextToSpeechProvider($this->resolver, new NullLogger());
    }

    private function textToImage(): TextToImageProvider {
        return new TextToImageProvider($this->resolver, new NullLogger());
    }

    private function voiceChat(): AudioToAudioChatProvider {
        return new AudioToAudioChatProvider($this->resolver, new NullLogger());
    }

    private static function noop(): callable {
        return static fn (float $progress): bool => true;
    }

    // ── Identity ────────────────────────────────────────────────────────────

    public function testProviderIdsAndTaskTypesAreTheOnesNextcloudRegisters(): void {
        $this->assertSame('aiquila:audio2text', $this->audioToText()->getId());
        $this->assertSame(AudioToText::ID, $this->audioToText()->getTaskTypeId());
        $this->assertSame('core:audio2text', $this->audioToText()->getTaskTypeId());

        $this->assertSame('aiquila:text2speech', $this->textToSpeech()->getId());
        $this->assertSame(TextToSpeech::ID, $this->textToSpeech()->getTaskTypeId());
        $this->assertSame('core:text2speech', $this->textToSpeech()->getTaskTypeId());

        $this->assertSame('aiquila:text2image', $this->textToImage()->getId());
        $this->assertSame(TextToImage::ID, $this->textToImage()->getTaskTypeId());
        $this->assertSame('core:text2image', $this->textToImage()->getTaskTypeId());

        $this->assertSame('aiquila:audio2audio:chat', $this->voiceChat()->getId());
        $this->assertSame(AudioToAudioChat::ID, $this->voiceChat()->getTaskTypeId());
        $this->assertSame('core:audio2audio:chat', $this->voiceChat()->getTaskTypeId());
    }

    public function testEveryModalityProviderOffersAProviderOverride(): void {
        $this->assertArrayHasKey('provider', $this->audioToText()->getOptionalInputShape());
        $this->assertArrayHasKey('provider', $this->textToSpeech()->getOptionalInputShape());
        $this->assertArrayHasKey('provider', $this->textToImage()->getOptionalInputShape());
        $this->assertArrayHasKey('provider', $this->voiceChat()->getOptionalInputShape());
    }

    // ── Transcription ───────────────────────────────────────────────────────

    public function testTranscriptionPassesTheFileThroughAndReturnsTheText(): void {
        $provider = $this->provider(['audio_in' => true]);
        $provider->expects($this->once())
            ->method('transcribeAudio')
            ->with(self::MP3, 'audio/mpeg', 'memo.mp3', 'alice', ['language' => 'de'])
            ->willReturn(['response' => 'Guten Morgen.']);
        $this->serve($provider);

        $result = $this->audioToText()->process(
            'alice',
            ['input' => $this->audioFile(), 'language' => 'de'],
            self::noop(),
        );

        $this->assertSame(['output' => 'Guten Morgen.'], $result);
    }

    public function testTranscriptionOmitsTheLanguageOptionWhenNoneWasGiven(): void {
        $provider = $this->provider(['audio_in' => true]);
        $provider->expects($this->once())
            ->method('transcribeAudio')
            ->with(self::MP3, 'audio/mpeg', 'memo.mp3', 'alice', [])
            ->willReturn(['response' => 'Hello.']);
        $this->serve($provider);

        $this->audioToText()->process('alice', ['input' => $this->audioFile()], self::noop());
    }

    /**
     * Transcription endpoints read the container from the filename, so a
     * recording stored without a usable extension gets one from its MIME type.
     */
    public function testTranscriptionNamesTheUploadFromTheMimeTypeWhenTheFileHasNoExtension(): void {
        $provider = $this->provider(['audio_in' => true]);
        $provider->expects($this->once())
            ->method('transcribeAudio')
            ->with(self::MP3, 'audio/ogg', 'voice-message.ogg', null, [])
            ->willReturn(['response' => 'Hi.']);
        $this->serve($provider);

        $this->audioToText()->process(
            null,
            ['input' => $this->audioFile('voice-message', 'audio/ogg')],
            self::noop(),
        );
    }

    public function testTranscriptionRejectsAnUnsupportedContainerBeforeCallingTheProvider(): void {
        $provider = $this->provider(['audio_in' => true]);
        $provider->expects($this->never())->method('transcribeAudio');
        $this->serve($provider);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unsupported audio format: audio/aiff');
        $this->audioToText()->process('alice', ['input' => $this->audioFile('memo.aiff', 'audio/aiff')], self::noop());
    }

    public function testTranscriptionRejectsAnOversizedRecording(): void {
        $this->serve($this->provider(['audio_in' => true]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The audio file is too large. Maximum is 100 MB.');
        $this->audioToText()->process(
            'alice',
            ['input' => $this->audioFile('memo.mp3', 'audio/mpeg', AudioLimits::MAX_BYTES + 1)],
            self::noop(),
        );
    }

    public function testTranscriptionWithoutAnAudioFileFails(): void {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No audio file provided');
        $this->audioToText()->process('alice', ['input' => 'not a file'], self::noop());
    }

    public function testTranscriptionSurfacesTheProviderError(): void {
        $provider = $this->provider(['audio_in' => true]);
        $provider->method('transcribeAudio')->willReturn(['error' => 'Rate limit exceeded.']);
        $this->serve($provider);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Rate limit exceeded.');
        $this->audioToText()->process('alice', ['input' => $this->audioFile()], self::noop());
    }

    public function testTranscriptionRefusesAProviderWithoutTheCapability(): void {
        $provider = $this->provider(['vision' => true], 'anthropic', 'Claude (Anthropic)');
        $provider->expects($this->never())->method('transcribeAudio');
        $this->factory->method('getProviderForUser')->willReturn($provider);
        $this->factory->method('getProviderIdsForUser')->willReturn(['anthropic']);
        $this->factory->method('getProviderById')->willReturnMap([['anthropic', $provider]]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Claude (Anthropic) cannot transcribe audio');
        $this->audioToText()->process('alice', ['input' => $this->audioFile()], self::noop());
    }

    // ── Speech ──────────────────────────────────────────────────────────────

    /** The task type names the slot `speech`, not `output`, and it carries bytes. */
    public function testSpeechIsReturnedAsRawBytesUnderTheSpeechKey(): void {
        $provider = $this->provider(['audio_out' => true]);
        $provider->expects($this->once())
            ->method('synthesizeSpeech')
            ->with('Read this out.', 'alice', ['voice' => 'alloy'])
            ->willReturn(['audio' => self::WAV_AUDIO, 'mimeType' => 'audio/mpeg']);
        $this->serve($provider);

        $result = $this->textToSpeech()->process(
            'alice',
            ['input' => 'Read this out.', 'voice' => 'alloy'],
            self::noop(),
        );

        $this->assertSame(['speech' => self::WAV_AUDIO], $result);
    }

    public function testSpeechRejectsBlankInput(): void {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No input text provided');
        $this->textToSpeech()->process('alice', ['input' => '   '], self::noop());
    }

    // ── Image generation ────────────────────────────────────────────────────

    public function testImageGenerationClampsTheRequestedCount(): void {
        $provider = $this->provider(['image_out' => true]);
        $provider->expects($this->once())
            ->method('generateImages')
            ->with('an orange cat', TextToImageProvider::MAX_IMAGES, 'alice')
            ->willReturn(['images' => ['png-one'], 'mimeType' => 'image/png']);
        $this->serve($provider);

        $result = $this->textToImage()->process(
            'alice',
            ['input' => 'an orange cat', 'numberOfImages' => 99],
            self::noop(),
        );

        $this->assertSame(['images' => ['png-one']], $result);
    }

    public function testImageGenerationFallsBackToOneForANonNumericCount(): void {
        $provider = $this->provider(['image_out' => true]);
        $provider->expects($this->once())
            ->method('generateImages')
            ->with('a blue door', 1, 'alice')
            ->willReturn(['images' => ['png-one'], 'mimeType' => 'image/png']);
        $this->serve($provider);

        $this->textToImage()->process('alice', ['input' => 'a blue door', 'numberOfImages' => 'lots'], self::noop());
    }

    public function testImageGenerationFailsWhenNothingCameBack(): void {
        $provider = $this->provider(['image_out' => true]);
        $provider->method('generateImages')->willReturn(['images' => [], 'mimeType' => 'image/png']);
        $this->serve($provider);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No images were generated.');
        $this->textToImage()->process('alice', ['input' => 'a blue door'], self::noop());
    }

    // ── Voice chat ──────────────────────────────────────────────────────────

    public function testVoiceChatChainsTranscriptionChatAndSpeech(): void {
        $provider = $this->provider(['audio_in' => true, 'audio_out' => true]);
        $provider->expects($this->once())
            ->method('transcribeAudio')
            ->willReturn(['response' => 'What is the weather?']);
        $provider->expects($this->once())
            ->method('chat')
            ->with(
                [
                    ['role' => 'user', 'content' => 'Hello'],
                    ['role' => 'assistant', 'content' => 'Hi there'],
                    ['role' => 'user', 'content' => 'What is the weather?'],
                ],
                'Be brief.',
                'alice',
            )
            ->willReturn(['response' => 'Sunny.']);
        $provider->expects($this->once())
            ->method('synthesizeSpeech')
            ->with('Sunny.', 'alice')
            ->willReturn(['audio' => self::WAV_AUDIO, 'mimeType' => 'audio/mpeg']);
        $this->serve($provider);

        $result = $this->voiceChat()->process(
            'alice',
            [
                'input' => $this->audioFile(),
                'system_prompt' => 'Be brief.',
                'history' => ['Hello', 'Hi there'],
            ],
            self::noop(),
        );

        $this->assertSame([
            'input_transcript' => 'What is the weather?',
            'output' => self::WAV_AUDIO,
            'output_transcript' => 'Sunny.',
        ], $result);
    }

    public function testVoiceChatStopsWhenNothingWasTranscribed(): void {
        $provider = $this->provider(['audio_in' => true, 'audio_out' => true]);
        $provider->method('transcribeAudio')->willReturn(['response' => '  ']);
        $provider->expects($this->never())->method('chat');
        $this->serve($provider);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Nothing could be transcribed from this recording.');
        $this->voiceChat()->process('alice', ['input' => $this->audioFile()], self::noop());
    }

    public function testVoiceChatRefusesAProviderThatCanOnlyTranscribe(): void {
        $provider = $this->provider(['audio_in' => true], 'local', 'Local model');
        $provider->expects($this->never())->method('transcribeAudio');
        $this->factory->method('getProviderForUser')->willReturn($provider);
        $this->factory->method('getProviderIdsForUser')->willReturn(['local']);
        $this->factory->method('getProviderById')->willReturnMap([['local', $provider]]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Local model cannot generate speech');
        $this->voiceChat()->process('alice', ['input' => $this->audioFile()], self::noop());
    }
}
