<?php

namespace OCA\AIquila\Tests\Unit;

use OCA\AIquila\Db\Message;
use OCA\AIquila\Db\MessageMapper;
use OCA\AIquila\Service\RuntimeContextService;
use OCP\IDateTimeZone;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RuntimeContextServiceTest extends TestCase {
    // 2026-10-31 23:30 UTC — already 1 November in Berlin.
    private const NOW = 1793489400;

    private function service(MessageMapper $mapper, string $zone = 'Europe/Berlin'): RuntimeContextService {
        $tz = $this->createStub(IDateTimeZone::class);
        $tz->method('getTimeZone')->willReturn(new \DateTimeZone($zone));
        return new RuntimeContextService($mapper, $tz, $this->createStub(LoggerInterface::class));
    }

    public function testNoteUsesTheUsersTimeZone(): void {
        $this->assertSame(
            'Today is Sunday, 1 November 2026. The user\'s time zone is Europe/Berlin.',
            $this->service($this->createStub(MessageMapper::class))->note('alice', self::NOW),
        );
        $this->assertStringContainsString(
            'Saturday, 31 October 2026',
            $this->service($this->createStub(MessageMapper::class), 'America/New_York')->note('alice', self::NOW),
        );
    }

    public function testRecordsTheNoteOnTheFirstTurn(): void {
        $mapper = $this->createMock(MessageMapper::class);
        $mapper->method('findLatestSystem')->willReturn(null);
        $mapper->expects($this->once())->method('insert')->with($this->callback(
            fn (Message $m): bool => $m->getRole() === 'system'
                && $m->getConversationId() === 7
                && str_starts_with($m->getContent(), 'Today is Sunday')
        ))->willReturnArgument(0);

        $this->service($mapper)->recordIfChanged(7, 'alice', self::NOW);
    }

    public function testSkipsAnUnchangedNote(): void {
        $latest = new Message();
        $latest->setContent('Today is Sunday, 1 November 2026. The user\'s time zone is Europe/Berlin.');
        $mapper = $this->createMock(MessageMapper::class);
        $mapper->method('findLatestSystem')->willReturn($latest);
        $mapper->expects($this->never())->method('insert');

        $this->service($mapper)->recordIfChanged(7, 'alice', self::NOW);
    }

    public function testFailureNeverBlocksTheTurn(): void {
        $mapper = $this->createMock(MessageMapper::class);
        $mapper->expects($this->once())->method('findLatestSystem')->willThrowException(new \RuntimeException('db down'));

        $this->service($mapper)->recordIfChanged(7, 'alice', self::NOW);
    }
}
