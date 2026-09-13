<?php

namespace OCA\AIquila\Tests\Unit\TaskProcessing;

use OCA\AIquila\Service\ImageOptimizer;
use OCA\AIquila\Service\Provider\LLMProviderFactory;
use OCA\AIquila\Service\Provider\LLMProviderInterface;
use OCA\AIquila\Service\Provider\ProviderSettingsSchema;
use OCA\AIquila\TaskProcessing\AnalyzeImagesProvider;
use OCA\AIquila\TaskProcessing\ImageToTextProvider;
use OCA\AIquila\TaskProcessing\ProviderResolver;
use OCP\Files\File;
use OCP\TaskProcessing\TaskTypes\AnalyzeImages;
use OCP\TaskProcessing\TaskTypes\ImageToTextOpticalCharacterRecognition;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class VisionProvidersTest extends TestCase {
    private const JPEG = "\xFF\xD8\xFF\xE0 raw bytes";

    private $factory;
    private $imageOptimizer;
    private ProviderResolver $resolver;

    protected function setUp(): void {
        $this->factory = $this->createMock(LLMProviderFactory::class);
        $this->resolver = new ProviderResolver($this->factory);

        // Not a supported mime as far as the optimizer is concerned, so prepare()
        // takes the plain base64_encode() path and no GD is needed.
        $this->imageOptimizer = $this->createMock(ImageOptimizer::class);
        $this->imageOptimizer->method('prepare')->willReturnCallback(
            static fn (string $raw, string $mime): array => ['base64' => base64_encode($raw), 'mimeType' => $mime]
        );
    }

    /**
     * The framework hands File nodes to process(), never raw bytes — every
     * File/Image-typed input slot is resolved by Manager::fillInputFileData().
     */
    private function imageFile(int $id = 42, string $content = self::JPEG, string $mime = 'image/jpeg') {
        $file = $this->createMock(File::class);
        $file->method('getId')->willReturn($id);
        $file->method('getContent')->willReturn($content);
        $file->method('getMimetype')->willReturn($mime);
        return $file;
    }

    private function visionProvider() {
        $provider = $this->createMock(LLMProviderInterface::class);
        $provider->method('getId')->willReturn('mistral');
        $provider->method('getLabel')->willReturn('Mistral');
        $provider->method('getCapabilities')->willReturn(ProviderSettingsSchema::capabilities(['vision' => true]));
        return $provider;
    }

    private function blindProvider() {
        $provider = $this->createMock(LLMProviderInterface::class);
        $provider->method('getId')->willReturn('deepseek');
        $provider->method('getLabel')->willReturn('DeepSeek');
        $provider->method('getCapabilities')->willReturn(ProviderSettingsSchema::capabilities(['vision' => false]));
        return $provider;
    }

    private function analyzeImages(): AnalyzeImagesProvider {
        return new AnalyzeImagesProvider($this->resolver, $this->imageOptimizer, new NullLogger());
    }

    private function imageToText(): ImageToTextProvider {
        return new ImageToTextProvider($this->resolver, $this->imageOptimizer, new NullLogger());
    }

    public function testProviderIdsAndTaskTypesAreTheOnesNextcloudRegisters(): void {
        $this->assertSame('aiquila:analyze_images', $this->analyzeImages()->getId());
        $this->assertSame(AnalyzeImages::ID, $this->analyzeImages()->getTaskTypeId());
        $this->assertSame('core:analyze-images', $this->analyzeImages()->getTaskTypeId());
        $this->assertSame('AIquila Vision', $this->analyzeImages()->getName());

        $this->assertSame('aiquila:image_to_text', $this->imageToText()->getId());
        $this->assertSame(ImageToTextOpticalCharacterRecognition::ID, $this->imageToText()->getTaskTypeId());
        $this->assertSame('core:image2text:ocr', $this->imageToText()->getTaskTypeId());
    }

    public function testBothVisionProvidersOfferAProviderOverride(): void {
        $this->assertArrayHasKey('provider', $this->analyzeImages()->getOptionalInputShape());
        $this->assertArrayHasKey('provider', $this->imageToText()->getOptionalInputShape());
    }

    public function testSingleImageUsesAskWithImageAndPassesTheFileId(): void {
        $provider = $this->visionProvider();
        $provider->expects($this->once())
            ->method('askWithImage')
            ->with('What is this?', base64_encode(self::JPEG), 'image/jpeg', 'alice', '42')
            ->willReturn(['response' => 'A cat']);
        $provider->expects($this->never())->method('askWithImages');
        $this->factory->method('getProviderForUser')->willReturn($provider);

        $result = $this->analyzeImages()->process('alice', [
            'input' => 'What is this?',
            'images' => [$this->imageFile()],
        ], static fn (float $p) => null);

        $this->assertSame(['output' => 'A cat'], $result);
    }

    public function testMultipleImagesUseAskWithImages(): void {
        $provider = $this->visionProvider();
        $provider->expects($this->once())
            ->method('askWithImages')
            ->with('What are these?', $this->countOf(2), 'alice', ['1', '2'])
            ->willReturn(['response' => 'Two cats']);
        $provider->expects($this->never())->method('askWithImage');
        $this->factory->method('getProviderForUser')->willReturn($provider);

        $result = $this->analyzeImages()->process('alice', [
            'input' => 'What are these?',
            'images' => [$this->imageFile(1), $this->imageFile(2)],
        ], static fn (float $p) => null);

        $this->assertSame(['output' => 'Two cats'], $result);
    }

    public function testProviderOverrideIsPassedToTheFactory(): void {
        $provider = $this->visionProvider();
        $provider->method('askWithImage')->willReturn(['response' => 'A cat']);
        $this->factory->expects($this->once())
            ->method('getProviderForUser')
            ->with('alice', 'mistral')
            ->willReturn($provider);

        $this->analyzeImages()->process('alice', [
            'input' => 'What is this?',
            'images' => [$this->imageFile()],
            'provider' => 'mistral',
        ], static fn (float $p) => null);
    }

    public function testAnalyzeImagesRefusesABlindProviderAndNamesAnAlternative(): void {
        $this->factory->method('getProviderForUser')->willReturn($this->blindProvider());
        $this->factory->method('getProviderIdsForUser')->willReturn(['mistral', 'deepseek']);
        $vision = $this->visionProvider();
        $vision->method('isConfigured')->willReturn(true);
        $this->factory->method('getProviderById')->willReturnMap([
            ['mistral', $vision],
            ['deepseek', $this->blindProvider()],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DeepSeek cannot process images.');
        $this->analyzeImages()->process('alice', [
            'input' => 'What is this?',
            'images' => [$this->imageFile()],
        ], static fn (float $p) => null);
    }

    public function testAnalyzeImagesRejectsInputThatIsNotAFile(): void {
        $this->factory->method('getProviderForUser')->willReturn($this->visionProvider());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Image 1 is not a file');
        $this->analyzeImages()->process('alice', [
            'input' => 'What is this?',
            'images' => [self::JPEG],
        ], static fn (float $p) => null);
    }

    public function testOcrReturnsOneTextPerFileInOrder(): void {
        $provider = $this->visionProvider();
        $provider->method('askWithImage')->willReturnOnConsecutiveCalls(
            ['response' => 'first page'],
            ['response' => 'second page'],
        );
        $this->factory->method('getProviderForUser')->willReturn($provider);

        $result = $this->imageToText()->process('alice', [
            'input' => [$this->imageFile(1), $this->imageFile(2)],
        ], static fn (float $p) => null);

        $this->assertSame(['output' => ['first page', 'second page']], $result);
    }

    public function testOcrDefaultsThePromptAndPassesTheFileId(): void {
        $default = $this->imageToText()->getOptionalInputShapeDefaults()['prompt'];
        $provider = $this->visionProvider();
        $provider->expects($this->once())
            ->method('askWithImage')
            ->with($default, base64_encode(self::JPEG), 'image/jpeg', 'alice', '7')
            ->willReturn(['response' => 'HELLO']);
        $this->factory->method('getProviderForUser')->willReturn($provider);

        $result = $this->imageToText()->process('alice', [
            'input' => [$this->imageFile(7)],
        ], static fn (float $p) => null);

        $this->assertSame(['output' => ['HELLO']], $result);
    }

    public function testOcrRefusesABlindProvider(): void {
        $this->factory->method('getProviderForUser')->willReturn($this->blindProvider());
        $this->factory->method('getProviderIdsForUser')->willReturn([]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no vision-capable AI provider is available');
        $this->imageToText()->process('alice', ['input' => [$this->imageFile()]], static fn (float $p) => null);
    }

    public function testOcrRejectsAnEmptyFileList(): void {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No images provided');
        $this->imageToText()->process('alice', ['input' => []], static fn (float $p) => null);
    }

    public function testTooManyImagesIsRejected(): void {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Too many images');
        $this->analyzeImages()->process('alice', [
            'input' => 'What are these?',
            'images' => array_fill(0, ImageOptimizer::MAX_IMAGES + 1, $this->imageFile()),
        ], static fn (float $p) => null);
    }
}
