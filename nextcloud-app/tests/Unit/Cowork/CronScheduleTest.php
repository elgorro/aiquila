<?php

declare(strict_types=1);

namespace OCA\AIquila\Tests\Unit\Cowork;

use OCA\AIquila\Cowork\CronSchedule;
use PHPUnit\Framework\TestCase;

class CronScheduleTest extends TestCase {

    public function testNightlyScheduleFindsNext0300Utc(): void {
        // 2026-06-12 00:00:00 UTC
        $base = gmmktime(0, 0, 0, 6, 12, 2026);
        $next = (new CronSchedule('0 3 * * *'))->getNextRunTime($base);
        $this->assertSame(gmmktime(3, 0, 0, 6, 12, 2026), $next);
    }

    public function testNextRunIsStrictlyAfterGivenTime(): void {
        // Exactly at a matching minute → returns the next day's match, not now.
        $exact = gmmktime(3, 0, 0, 6, 12, 2026);
        $next = (new CronSchedule('0 3 * * *'))->getNextRunTime($exact);
        $this->assertSame(gmmktime(3, 0, 0, 6, 13, 2026), $next);
    }

    public function testStepAndRangeFields(): void {
        $base = gmmktime(10, 7, 0, 6, 12, 2026);
        // Every 15 minutes → next is :15.
        $next = (new CronSchedule('*/15 * * * *'))->getNextRunTime($base);
        $this->assertSame(gmmktime(10, 15, 0, 6, 12, 2026), $next);
    }

    public function testDayOfWeekMatch(): void {
        // 2026-06-12 is a Friday (dow 5). Schedule for Monday (1) should skip ahead.
        $base = gmmktime(0, 0, 0, 6, 12, 2026);
        $next = (new CronSchedule('0 0 * * 1'))->getNextRunTime($base);
        // Next Monday is 2026-06-15.
        $this->assertSame(gmmktime(0, 0, 0, 6, 15, 2026), $next);
    }

    public function testInvalidExpressionThrows(): void {
        $this->expectException(\InvalidArgumentException::class);
        new CronSchedule('not a cron');
    }

    public function testFieldOutOfRangeThrows(): void {
        $this->expectException(\InvalidArgumentException::class);
        new CronSchedule('99 3 * * *');
    }

    public function testIsValid(): void {
        $this->assertTrue(CronSchedule::isValid('0 3 * * *'));
        $this->assertFalse(CronSchedule::isValid('0 3 * *'));
    }
}
