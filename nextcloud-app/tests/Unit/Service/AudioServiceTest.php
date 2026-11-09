<?php

namespace OCA\AIquila\Tests\Unit\Service;

use OCA\AIquila\Service\AudioService;
use OCA\AIquila\Service\Provider\LLMProviderInterface;
use OCP\Files\File;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class AudioServiceTest extends TestCase {

    private function file() {
        $file = $this->createStub(File::class);
        $file->method('getName')->willReturn('memo.mp3');
        $file->method('fopen')->willReturnCallback(static function (): mixed {
            $handle = fopen('php://memory', 'w+b');
            fwrite($handle, 'ID3 bytes');
            rewind($handle);
            return $handle;
        });
        $file->method('getMimetype')->willReturn('audio/mpeg');
        $file->method('getSize')->willReturn(1024);
        return $file;
    }

    private function provider() {
        $provider = $this->createStub(LLMProviderInterface::class);
        $provider->method('getId')->willReturn('mistral');
        $provider->method('getLabel')->willReturn('Mistral');
        return $provider;
    }

    public function testSubRipNumbersCuesAndUsesCommaMilliseconds(): void {
        $this->assertSame(
            "1\n00:00:01,250 --> 00:00:02,000\nOne\n\n2\n00:00:02,000 --> 00:00:03,999\nTwo\n",
            AudioService::subtitles([
                ['start' => 1.25, 'end' => 2.0, 'text' => 'One'],
                ['start' => 2.0, 'end' => 3.9994, 'text' => 'Two'],
            ]),
        );
    }

    public function testWebVttHasAHeaderAndDotMilliseconds(): void {
        $this->assertSame(
            "WEBVTT\n\n00:01:05.500 --> 00:01:06.000\nHi\n",
            AudioService::subtitles([['start' => 65.5, 'end' => 66.0, 'text' => 'Hi']], AudioService::SUBTITLES_VTT),
        );
    }

    public function testHoursAreNotCappedAndMillisecondsRoundUpIntoSeconds(): void {
        $this->assertSame(
            "1\n25:00:00,000 --> 25:00:01,000\nLate\n",
            AudioService::subtitles([['start' => 90000.0, 'end' => 90000.9996, 'text' => 'Late']]),
        );
    }

    public function testBlankSegmentsAreSkippedWithoutLeavingAGapInTheNumbering(): void {
        $this->assertSame(
            "1\n00:00:00,000 --> 00:00:01,000\nA\n\n2\n00:00:02,000 --> 00:00:03,000\nB\n",
            AudioService::subtitles([
                ['start' => 0.0, 'end' => 1.0, 'text' => 'A'],
                ['start' => 1.0, 'end' => 2.0, 'text' => '   '],
                ['start' => 2.0, 'end' => 3.0, 'text' => 'B'],
            ]),
        );
    }

    public function testTranscriptionErrorsAreLoggedAndThrown(): void {
        $provider = $this->provider();
        $provider->method('transcribeAudio')->willReturn(['error' => 'Rate limited']);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('Rate limited');
        (new AudioService($logger))->transcribe($provider, $this->file(), 'alice');
    }

    public function testSpeechWithoutAudioIsAnError(): void {
        $provider = $this->provider();
        $provider->method('synthesizeSpeech')->willReturn(['audio' => '', 'mimeType' => 'audio/mpeg']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('Mistral returned no audio.');
        (new AudioService(new NullLogger()))->speak($provider, 'Hello', 'alice');
    }

    public function testSegmentsRequireTimings(): void {
        $provider = $this->provider();
        $provider->method('transcribeAudio')->willReturn(['response' => 'Hello']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('Mistral returned no timestamps');
        (new AudioService(new NullLogger()))->segments($provider, $this->file(), 'alice');
    }

    public function testTheRecordingIsHandedOverAsAStreamAndClosedAfterwards(): void {
        $handle = fopen('php://memory', 'w+b');
        fwrite($handle, 'ID3 bytes');
        rewind($handle);
        $file = $this->createStub(File::class);
        $file->method('getName')->willReturn('memo.mp3');
        $file->method('fopen')->willReturn($handle);
        $file->method('getMimetype')->willReturn('audio/mpeg');
        $file->method('getSize')->willReturn(9);
        $received = null;
        $provider = $this->provider();
        $provider->method('transcribeAudio')->willReturnCallback(
            function (mixed $audio) use (&$received): array {
                $received = is_resource($audio) ? stream_get_contents($audio) : $audio;
                return ['response' => 'Hello'];
            }
        );

        $result = (new AudioService(new NullLogger()))->transcribe($provider, $file, 'alice');

        $this->assertSame('ID3 bytes', $received);
        $this->assertSame('Hello', $result['text']);
        $this->assertFalse(is_resource($handle), 'the file handle is closed once the provider is done');
    }

    public function testAnUnreadableRecordingFailsBeforeTheProvider(): void {
        $file = $this->createStub(File::class);
        $file->method('getName')->willReturn('memo.mp3');
        $file->method('fopen')->willThrowException(new \RuntimeException('locked'));
        $file->method('getMimetype')->willReturn('audio/mpeg');
        $file->method('getSize')->willReturn(9);
        $provider = $this->createMock(LLMProviderInterface::class);
        $provider->expects($this->never())->method('transcribeAudio');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('The audio file could not be read.');
        (new AudioService(new NullLogger()))->transcribe($provider, $file, 'alice');
    }

    public function testProgressIsMappedOntoTheTasksOwnStretch(): void {
        $reported = [];
        $onProgress = AudioService::progressBetween(function (float $p) use (&$reported): bool {
            $reported[] = $p;
            return true;
        }, 0.1, 0.4);

        foreach ([0.0, 0.5, 1.0, 1.5] as $fraction) {
            $onProgress($fraction);
        }

        $this->assertEqualsWithDelta([0.1, 0.25, 0.4, 0.4], $reported, 1e-9);
    }
}
