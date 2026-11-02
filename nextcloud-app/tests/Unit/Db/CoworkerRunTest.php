<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Tests\Unit\Db;

use OCA\AIquila\Cowork\UserPath;
use OCA\AIquila\Db\CoworkerRun;
use OCP\Files\File;
use PHPUnit\Framework\TestCase;

class CoworkerRunTest extends TestCase {
    public function testLogEntriesKeepTheirShapeAndOrder(): void {
        $run = new CoworkerRun();
        $run->appendLog(CoworkerRun::LOG_INFO, 'Tagged: beach, sunset', '/Photos/a.jpg', 1761300001);
        $run->appendLog(CoworkerRun::LOG_WARN, 'Skipped: too large', '/Photos/b.tif', 1761300002);
        $run->appendLog(CoworkerRun::LOG_ERROR, 'Run failed', null, 1761300003);

        $this->assertSame([
            ['ts' => 1761300001, 'level' => 'info', 'message' => 'Tagged: beach, sunset', 'item' => '/Photos/a.jpg'],
            ['ts' => 1761300002, 'level' => 'warn', 'message' => 'Skipped: too large', 'item' => '/Photos/b.tif'],
            ['ts' => 1761300003, 'level' => 'error', 'message' => 'Run failed', 'item' => null],
        ], $run->getDecodedLog());
    }

    public function testLogKeepsOnlyTheNewestEntries(): void {
        $run = new CoworkerRun();
        for ($i = 1; $i <= CoworkerRun::LOG_LIMIT + 3; $i++) {
            $run->appendLog(CoworkerRun::LOG_INFO, 'item ' . $i, null, $i);
        }

        $log = $run->getDecodedLog();
        $this->assertCount(CoworkerRun::LOG_LIMIT, $log);
        $this->assertSame('item 4', $log[0]['message']);
        $this->assertSame('item ' . (CoworkerRun::LOG_LIMIT + 3), end($log)['message']);
    }

    public function testLongMessagesAreCut(): void {
        $run = new CoworkerRun();
        $run->appendLog(CoworkerRun::LOG_ERROR, str_repeat('x', 2000));

        $message = $run->getDecodedLog()[0]['message'];
        $this->assertSame(500, mb_strlen($message));
        $this->assertStringEndsWith('…', $message);
    }

    public function testAnUnreadableLogReadsAsEmpty(): void {
        $run = new CoworkerRun();
        $run->setLog('not json');
        $this->assertSame([], $run->getDecodedLog());

        $run->setLog('[{"ts":1},{"ts":2,"level":"info","message":"ok"}]');
        $this->assertSame([['ts' => 2, 'level' => 'info', 'message' => 'ok', 'item' => null]], $run->getDecodedLog());
    }

    /** The run list stays cheap: only the single-run view carries the log. */
    public function testOnlyTheDetailViewCarriesTheLog(): void {
        $run = new CoworkerRun();
        $run->appendLog(CoworkerRun::LOG_INFO, 'Done', '/a.txt', 5);

        $this->assertArrayNotHasKey('log', $run->jsonSerialize());
        $this->assertSame([['ts' => 5, 'level' => 'info', 'message' => 'Done', 'item' => '/a.txt']], $run->jsonSerializeDetail()['log']);
    }

    public function testUserPathStripsTheUsersFilesPrefix(): void {
        $inHome = $this->createStub(File::class);
        $inHome->method('getPath')->willReturn('/alice/files/Photos/a.jpg');
        $this->assertSame('/Photos/a.jpg', UserPath::of($inHome));

        $elsewhere = $this->createStub(File::class);
        $elsewhere->method('getPath')->willReturn('/alice/files_trashbin/x');
        $this->assertSame('/alice/files_trashbin/x', UserPath::of($elsewhere));
    }
}
