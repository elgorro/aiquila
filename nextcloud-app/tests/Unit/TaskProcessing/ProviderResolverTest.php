<?php

namespace OCA\AIquila\Tests\Unit\TaskProcessing;

use OCA\AIquila\Service\Provider\LLMProviderFactory;
use OCA\AIquila\Service\Provider\LLMProviderInterface;
use OCA\AIquila\Service\Provider\NoPermittedProviderException;
use OCA\AIquila\Service\Provider\ProviderSettingsSchema;
use OCA\AIquila\TaskProcessing\ProviderResolver;
use PHPUnit\Framework\TestCase;

class ProviderResolverTest extends TestCase {
    private $factory;
    private ProviderResolver $resolver;

    protected function setUp(): void {
        $this->factory = $this->createMock(LLMProviderFactory::class);
        $this->resolver = new ProviderResolver($this->factory);
    }

    private function provider(string $id, string $label, bool $vision, bool $configured = true) {
        return $this->capable($id, $label, ['vision' => $vision], $configured);
    }

    /** @param array<string, bool> $capabilities */
    private function capable(string $id, string $label, array $capabilities, bool $configured = true) {
        $provider = $this->createMock(LLMProviderInterface::class);
        $provider->method('getId')->willReturn($id);
        $provider->method('getLabel')->willReturn($label);
        $provider->method('getCapabilities')->willReturn(ProviderSettingsSchema::capabilities($capabilities));
        $provider->method('isConfigured')->willReturn($configured);
        return $provider;
    }

    public function testResolveDelegatesToTheFactory(): void {
        $local = $this->provider('local', 'Local model', false);
        $this->factory->expects($this->once())
            ->method('getProviderForUser')
            ->with('alice', null)
            ->willReturn($local);

        $this->assertSame($local, $this->resolver->resolve('alice'));
    }

    public function testResolvePassesTheRequestedIdThrough(): void {
        $mistral = $this->provider('mistral', 'Mistral', true);
        $this->factory->method('getProviderForUser')->with('alice', 'mistral')->willReturn($mistral);

        $this->assertSame($mistral, $this->resolver->resolve('alice', 'mistral'));
    }

    public function testNoPermittedProviderBecomesARuntimeException(): void {
        $this->factory->method('getProviderForUser')
            ->willThrowException(new NoPermittedProviderException('alice'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(NoPermittedProviderException::USER_MESSAGE);
        $this->resolver->resolve('alice');
    }

    public function testRequestedIdIgnoresMissingAndEmptyValues(): void {
        $this->assertNull($this->resolver->requestedId([]));
        $this->assertNull($this->resolver->requestedId(['provider' => '']));
        $this->assertNull($this->resolver->requestedId(['provider' => ['mistral']]));
        $this->assertSame('mistral', $this->resolver->requestedId(['provider' => 'mistral']));
    }

    public function testVisionCapableProviderIsReturnedUnchanged(): void {
        $anthropic = $this->provider('anthropic', 'Claude (Anthropic)', true);
        $this->factory->method('getProviderForUser')->willReturn($anthropic);

        $this->assertSame($anthropic, $this->resolver->resolveVisionCapable('alice'));
    }

    public function testNonVisionProviderFailsAndNamesTheAlternatives(): void {
        $local = $this->provider('local', 'Local model', false);
        $this->factory->method('getProviderForUser')->willReturn($local);
        $this->factory->method('getProviderIdsForUser')->willReturn(['anthropic', 'deepseek', 'local']);
        $this->factory->method('getProviderById')->willReturnMap([
            ['anthropic', $this->provider('anthropic', 'Claude (Anthropic)', true)],
            ['deepseek', $this->provider('deepseek', 'DeepSeek', false)],
            ['local', $local],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Local model cannot process images. Pick a vision-capable provider in your AIquila settings — available: Claude (Anthropic).');
        $this->resolver->resolveVisionCapable('alice');
    }

    public function testUnconfiguredVisionProvidersAreNotOffered(): void {
        $local = $this->provider('local', 'Local model', false);
        $this->factory->method('getProviderForUser')->willReturn($local);
        $this->factory->method('getProviderIdsForUser')->willReturn(['anthropic', 'local']);
        $this->factory->method('getProviderById')->willReturnMap([
            ['anthropic', $this->provider('anthropic', 'Claude (Anthropic)', true, false)],
            ['local', $local],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no vision-capable AI provider is available for your account');
        $this->resolver->resolveVisionCapable('alice');
    }

    // ── Non-text modality guards ────────────────────────────────────────────

    public function testAudioCapableProviderIsReturnedUnchanged(): void {
        $mistral = $this->capable('mistral', 'Mistral', ['audio_in' => true]);
        $this->factory->method('getProviderForUser')->willReturn($mistral);

        $this->assertSame($mistral, $this->resolver->resolveAudioCapable('alice'));
    }

    public function testProviderWithoutTranscriptionFailsAndNamesTheAlternatives(): void {
        $anthropic = $this->capable('anthropic', 'Claude (Anthropic)', ['vision' => true]);
        $this->factory->method('getProviderForUser')->willReturn($anthropic);
        $this->factory->method('getProviderIdsForUser')->willReturn(['anthropic', 'mistral']);
        $this->factory->method('getProviderById')->willReturnMap([
            ['anthropic', $anthropic],
            ['mistral', $this->capable('mistral', 'Mistral', ['audio_in' => true])],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Claude (Anthropic) cannot transcribe audio. Pick a transcription-capable provider in your AIquila settings — available: Mistral.');
        $this->resolver->resolveAudioCapable('alice');
    }

    public function testProviderWithoutSpeechFailsAndNamesTheAlternatives(): void {
        $anthropic = $this->capable('anthropic', 'Claude (Anthropic)', []);
        $this->factory->method('getProviderForUser')->willReturn($anthropic);
        $this->factory->method('getProviderIdsForUser')->willReturn(['anthropic', 'mistral']);
        $this->factory->method('getProviderById')->willReturnMap([
            ['anthropic', $anthropic],
            ['mistral', $this->capable('mistral', 'Mistral', ['audio_out' => true])],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Claude (Anthropic) cannot generate speech. Pick a speech-capable provider in your AIquila settings — available: Mistral.');
        $this->resolver->resolveSpeechCapable('alice');
    }

    public function testProviderWithoutImageGenerationFailsWithNoAlternatives(): void {
        $local = $this->capable('local', 'Local model', []);
        $this->factory->method('getProviderForUser')->willReturn($local);
        $this->factory->method('getProviderIdsForUser')->willReturn(['local']);
        $this->factory->method('getProviderById')->willReturnMap([['local', $local]]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Local model cannot generate images, and no image-generating AI provider is available for your account. Ask your administrator.');
        $this->resolver->resolveImageGenCapable('alice');
    }

    public function testVoiceChatNeedsBothHalvesAndReportsTheMissingOneFirst(): void {
        // Transcription but no speech: the message names the half the run would
        // reach second, not both.
        $half = $this->capable('local', 'Local model', ['audio_in' => true]);
        $this->factory->method('getProviderForUser')->willReturn($half);
        $this->factory->method('getProviderIdsForUser')->willReturn(['local']);
        $this->factory->method('getProviderById')->willReturnMap([['local', $half]]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Local model cannot generate speech, and no speech-capable AI provider is available for your account. Ask your administrator.');
        $this->resolver->resolveVoiceChatCapable('alice');
    }

    public function testVoiceChatAcceptsAProviderWithBothHalves(): void {
        $mistral = $this->capable('mistral', 'Mistral', ['audio_in' => true, 'audio_out' => true]);
        $this->factory->method('getProviderForUser')->willReturn($mistral);

        $this->assertSame($mistral, $this->resolver->resolveVoiceChatCapable('alice'));
    }
}
