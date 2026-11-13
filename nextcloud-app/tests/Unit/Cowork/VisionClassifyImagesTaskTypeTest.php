<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Tests\Unit\Cowork;

use OCA\AIquila\Cowork\VisionClassifyImagesTaskType;
use OCA\AIquila\Db\Coworker;
use OCA\AIquila\Db\CoworkerRun;
use OCA\AIquila\Service\ImageOptimizer;
use OCA\AIquila\Service\Provider\LLMProviderFactory;
use OCA\AIquila\Service\Provider\LLMProviderInterface;
use OCA\AIquila\Service\UsageStatService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class VisionClassifyImagesTaskTypeTest extends TestCase {
    private function image(int $id, string $name): File {
        $file = $this->createStub(File::class);
        $file->method('getId')->willReturn($id);
        $file->method('getName')->willReturn($name);
        $file->method('getPath')->willReturn('/alice/files/Photos/' . $name);
        $file->method('getMimetype')->willReturn('image/jpeg');
        $file->method('getContent')->willReturn('bytes');
        return $file;
    }

    /** One log line per image: the labels applied, or why it failed. */
    public function testEachImageLeavesALogLine(): void {
        $folder = $this->createStub(Folder::class);
        $folder->method('getDirectoryListing')->willReturn([$this->image(1, 'a.jpg'), $this->image(2, 'b.jpg')]);
        $userFolder = $this->createStub(Folder::class);
        $userFolder->method('get')->willReturn($folder);
        $root = $this->createStub(IRootFolder::class);
        $root->method('getUserFolder')->willReturn($userFolder);

        $optimizer = $this->createStub(ImageOptimizer::class);
        $optimizer->method('prepare')->willReturn(['base64' => 'eA==', 'mimeType' => 'image/jpeg']);

        $provider = $this->createStub(LLMProviderInterface::class);
        $provider->method('getId')->willReturn('anthropic');
        $provider->method('askWithImage')->willReturnOnConsecutiveCalls(
            ['response' => 'Beach, sunset'],
            ['error' => 'Image could not be read'],
        );
        $factory = $this->createStub(LLMProviderFactory::class);
        $factory->method('getProviderForUser')->willReturn($provider);

        $tag = $this->createStub(ISystemTag::class);
        $tag->method('getId')->willReturn('9');
        $tags = $this->createStub(ISystemTagManager::class);
        $tags->method('getTag')->willReturn($tag);

        $task = new VisionClassifyImagesTaskType(
            $root,
            $optimizer,
            $factory,
            $this->createStub(LoggerInterface::class),
            $tags,
            $this->createStub(ISystemTagObjectMapper::class),
            $this->createStub(UsageStatService::class),
        );

        $coworker = new Coworker();
        $coworker->setUserId('alice');
        $coworker->setInputPath('/Photos');
        $coworker->setOptions('{}');
        $run = new CoworkerRun();

        $result = $task->run($coworker, $run, static function (): void {
        });

        // The failed image is counted as failed, not as processed.
        $this->assertSame(2, $result['itemsTotal']);
        $this->assertSame(1, $result['itemsProcessed']);
        $this->assertSame(1, $result['itemsFailed'] ?? null);

        $log = $run->getDecodedLog();
        $this->assertSame(['info', 'error'], array_column($log, 'level'));
        $this->assertSame(['/Photos/a.jpg', '/Photos/b.jpg'], array_column($log, 'item'));
        $this->assertSame('beach, sunset', $log[0]['message']);
        $this->assertSame('Image could not be read', $log[1]['message']);
    }
}
