<?php

declare(strict_types=1);

namespace OCA\AIquila\Tests\Unit\Service;

use OCA\AIquila\Db\CoworkerRun;
use OCA\AIquila\Service\AppSettings;
use OCA\AIquila\Service\BatchRunNotifications;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class BatchRunNotificationsTest extends TestCase {
    /** @var array<string, string> user setting => value */
    private array $settings = [];
    /** @var list<array{subject: string, params: array, object: array, user: string}> */
    private array $sent = [];
    private $userFolder;
    private BatchRunNotifications $notifications;
    /** @var \WeakMap<INotification, array> what was set on each notification */
    private \WeakMap $recorded;

    protected function setUp(): void {
        $this->recorded = new \WeakMap();
        $config = $this->createStub(AppSettings::class);
        $config->method('getUserValue')->willReturnCallback(
            fn (string $user, string $app, string $key, string $default = ''): string => $this->settings[$key] ?? $default
        );

        $manager = $this->createStub(INotificationManager::class);
        $manager->method('createNotification')->willReturnCallback(fn () => $this->recordingNotification());
        $manager->method('notify')->willReturnCallback(function (INotification $n): void {
            $this->sent[] = $this->recorded[$n];
        });

        $this->userFolder = $this->createStub(Folder::class);
        $root = $this->createStub(IRootFolder::class);
        $root->method('getUserFolder')->willReturn($this->userFolder);
        $this->notifications = new BatchRunNotifications($manager, $config, $root, $this->createStub(LoggerInterface::class));
    }

    /** A notification stub that remembers what was set on it. */
    private function recordingNotification(): INotification {
        $n = $this->createStub(INotification::class);
        $this->recorded[$n] = ['subject' => '', 'params' => [], 'object' => [], 'user' => ''];
        $n->method('setApp')->willReturnSelf();
        $n->method('setDateTime')->willReturnSelf();
        $n->method('setUser')->willReturnCallback(function (string $u) use ($n) {
            $this->record($n, 'user', $u);
            return $n;
        });
        $n->method('setObject')->willReturnCallback(function (string $type, string $id) use ($n) {
            $this->record($n, 'object', [$type, $id]);
            return $n;
        });
        $n->method('setSubject')->willReturnCallback(function (string $subject, array $params) use ($n) {
            $this->record($n, 'subject', $subject);
            $this->record($n, 'params', $params);
            return $n;
        });
        return $n;
    }

    private function record(INotification $n, string $key, mixed $value): void {
        $entry = $this->recorded[$n];
        $entry[$key] = $value;
        $this->recorded[$n] = $entry;
    }

    /** @param list<string> $outputs */
    private function batchRun(string $status, array $outputs = [], ?string $error = null): CoworkerRun {
        $run = new CoworkerRun();
        $run->setId(42);
        $run->setUserId('alice');
        $run->setStatus($status);
        $run->setTaskType('summarize');
        $run->setItemsTotal(5);
        $run->setItemsProcessed(3);
        $run->setItemsFailed(2);
        $run->setItemsSkipped(4);
        $run->setError($error);
        $run->setFinishedAt(1_800_000_000);
        $run->setDecodedOutputs($outputs);
        return $run;
    }

    public function testAFinishedRunIsAnnouncedOnlyWhenTheUserOptedIn(): void {
        // Off by default, like task success notifications.
        $this->notifications->runFinished($this->batchRun(CoworkerRun::STATUS_SUCCESS));
        $this->assertSame([], $this->sent);

        $this->settings['task_success_notifications'] = '1';
        $this->notifications->runFinished($this->batchRun(CoworkerRun::STATUS_SUCCESS));
        $this->assertCount(1, $this->sent);
        $this->assertSame(BatchRunNotifications::SUBJECT_SUCCESS, $this->sent[0]['subject']);
        $this->assertSame('alice', $this->sent[0]['user']);
        $this->assertSame(['batch_run', '42'], $this->sent[0]['object']);
        $this->assertSame([
            'runId' => 42,
            'operation' => 'summarize',
            'processed' => 3,
            'total' => 5,
            'failed' => 2,
            'skipped' => 4,
            'error' => '',
        ], $this->sent[0]['params']);
    }

    public function testAFailedRunIsAnnouncedUnlessTheUserOptedOut(): void {
        $this->notifications->runFinished($this->batchRun(CoworkerRun::STATUS_ERROR, [], 'API down'));
        $this->assertCount(1, $this->sent);
        $this->assertSame(BatchRunNotifications::SUBJECT_ERROR, $this->sent[0]['subject']);
        $this->assertSame('API down', $this->sent[0]['params']['error']);

        $this->settings['task_failure_notifications'] = '0';
        $this->notifications->runFinished($this->batchRun(CoworkerRun::STATUS_ERROR, [], 'API down'));
        $this->assertCount(1, $this->sent);
    }

    /** "All 2 item(s) failed" repeats the counts; the first failure says why. */
    public function testAFailedRunNamesItsFirstItemFailure(): void {
        $run = $this->batchRun(CoworkerRun::STATUS_ERROR, [], 'All 2 item(s) failed');
        $run->appendLog(CoworkerRun::LOG_WARN, 'Skipped: Empty', '/Docs/e.txt');
        $run->appendLog(CoworkerRun::LOG_ERROR, 'Errored: endpoint unreachable', '/Docs/a.txt');
        $run->appendLog(CoworkerRun::LOG_ERROR, 'Errored: endpoint unreachable', '/Docs/b.txt');
        $run->appendLog(CoworkerRun::LOG_ERROR, 'All 2 item(s) failed');

        $this->notifications->runFinished($run);

        $this->assertSame('a.txt: Errored: endpoint unreachable', $this->sent[0]['params']['error']);
    }

    public function testRunsThatHaveNotEndedOrWereCancelledAreNotAnnounced(): void {
        $this->settings['task_success_notifications'] = '1';
        foreach ([CoworkerRun::STATUS_QUEUED, CoworkerRun::STATUS_RUNNING, CoworkerRun::STATUS_PENDING, CoworkerRun::STATUS_CANCELLED] as $status) {
            $this->notifications->runFinished($this->batchRun($status));
        }
        $this->assertSame([], $this->sent);
    }

    public function testOutputsInOneFolderLinkToTheFolder(): void {
        $this->settings['task_success_notifications'] = '1';
        $this->notifications->runFinished($this->batchRun(CoworkerRun::STATUS_SUCCESS, ['/Docs/a.summary.md', '/Docs/b.summary.md']));

        $this->assertSame('/Docs', $this->sent[0]['params']['outputDir']);
        $this->assertArrayNotHasKey('outputFileId', $this->sent[0]['params']);
    }

    public function testOneOutputOrOutputsInSeveralFoldersLinkToTheFirstFile(): void {
        $this->settings['task_success_notifications'] = '1';
        $file = $this->createStub(File::class);
        $file->method('getId')->willReturn(777);
        $this->userFolder->method('get')->willReturnCallback(
            fn (string $path) => $path === '/A/a.summary.md' ? $file : throw new NotFoundException()
        );

        $this->notifications->runFinished($this->batchRun(CoworkerRun::STATUS_SUCCESS, ['/A/a.summary.md', '/B/b.summary.md']));
        $this->assertSame(['outputDir' => '/A', 'outputFileId' => 777], array_intersect_key($this->sent[0]['params'], ['outputDir' => 0, 'outputFileId' => 0]));

        // Gone since: the folder it was in.
        $this->notifications->runFinished($this->batchRun(CoworkerRun::STATUS_SUCCESS, ['/top.summary.md']));
        $this->assertSame('/', $this->sent[1]['params']['outputDir']);
        $this->assertArrayNotHasKey('outputFileId', $this->sent[1]['params']);
    }

    /** The run is already saved; a notification problem must not undo that. */
    public function testAFailureToNotifyIsLoggedNotThrown(): void {
        $manager = $this->createStub(INotificationManager::class);
        $manager->method('createNotification')->willThrowException(new \RuntimeException('no notifications app'));
        $config = $this->createStub(AppSettings::class);
        $config->method('getUserValue')->willReturn('1');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        $notifications = new BatchRunNotifications($manager, $config, $this->createStub(IRootFolder::class), $logger);
        $notifications->runFinished($this->batchRun(CoworkerRun::STATUS_SUCCESS));
    }
}
