<?php

declare(strict_types=1);

namespace OCA\AIquila\Tests\Unit\Controller;

use OCA\AIquila\Controller\UsageExportController;
use OCA\AIquila\Http\DownloadStreamResponse;
use OCA\AIquila\Service\UsageExport\UsageExportFilter;
use OCA\AIquila\Service\UsageExport\UsageExportService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Who can export whose usage, and what the download looks like.
 */
class UsageExportControllerTest extends TestCase {
    private const NOW = 1795651200; // 2026-11-26

    /** @var list<array{UsageExportFilter, list<string>, bool}> */
    private array $streamed = [];

    private function controller(?UsageExportService $service = null): UsageExportController {
        $time = $this->createStub(ITimeFactory::class);
        $time->method('getTime')->willReturn(self::NOW);
        return new UsageExportController(
            'aiquila',
            $this->createStub(IRequest::class),
            $service ?? $this->service(),
            $time,
            'alice',
            $this->createStub(LoggerInterface::class),
        );
    }

    private function service(): UsageExportService {
        $service = $this->createStub(UsageExportService::class);
        $service->method('stream')->willReturnCallback(function (UsageExportFilter $f, array $userIds, bool $includeUser): \Generator {
            $this->streamed[] = [$f, $userIds, $includeUser];
            return (static function () {
                yield 'chunk';
            })();
        });
        $service->method('allUserIds')->willReturn(['', 'alice', 'bob']);
        $service->method('groupUserIds')->willReturnCallback(static fn (string $gid): ?array => $gid === 'finance' ? ['bob', 'carol'] : null);
        return $service;
    }

    /**
     * The response's own headers. getHeaders() reaches into the server
     * container for the CSP ones, which a unit test does not have.
     *
     * @return array<string, string>
     */
    private static function headers(object $response): array {
        return (new \ReflectionProperty(\OCP\AppFramework\Http\Response::class, 'headers'))->getValue($response);
    }

    /**
     * The admin export relies on the framework's admin check, which a
     * non-admin fails with 403. It must never carry #[NoAdminRequired].
     */
    public function testOnlyThePersonalExportSkipsTheAdminCheck(): void {
        $class = new \ReflectionClass(UsageExportController::class);
        $this->assertNotEmpty($class->getMethod('export')->getAttributes(NoAdminRequired::class));
        $this->assertEmpty($class->getMethod('adminExport')->getAttributes(NoAdminRequired::class));
        foreach (['export', 'adminExport'] as $method) {
            $this->assertEmpty($class->getMethod($method)->getAttributes(NoCSRFRequired::class), $method);
            $this->assertNotEmpty($class->getMethod($method)->getAttributes(UserRateLimit::class), $method);
        }
    }

    /** There is no parameter through which the personal export could name another user. */
    public function testThePersonalExportTakesNoUserOrGroup(): void {
        $names = array_map(static fn (\ReflectionParameter $p): string => $p->getName(),
            (new \ReflectionMethod(UsageExportController::class, 'export'))->getParameters());
        $this->assertNotContains('user', $names);
        $this->assertNotContains('group', $names);
    }

    public function testThePersonalExportIsTheCallersRowsWithoutAUserColumn(): void {
        $response = $this->controller()->export('csv', 'day', '2026-01-01', '2026-01-31');

        $this->assertInstanceOf(DownloadStreamResponse::class, $response);
        $this->assertSame(200, $response->getStatus());
        [$filter, $userIds, $includeUser] = $this->streamed[0];
        $this->assertSame(['alice'], $userIds);
        $this->assertFalse($includeUser);
        $this->assertSame('day', $filter->granularity);

        $headers = self::headers($response);
        $this->assertSame('text/csv; charset=utf-8; header=present', $headers['Content-Type']);
        $this->assertSame('attachment; filename="aiquila-usage-day-2026-11-26.csv"', $headers['Content-Disposition']);
        $this->assertSame('no-store', $headers['Cache-Control']);
    }

    public function testJsonIsServedAsJson(): void {
        $response = $this->controller()->export('json');

        $this->assertSame('application/json; charset=utf-8', self::headers($response)['Content-Type']);
    }

    public function testInvalidParametersAre400BeforeAnythingStreams(): void {
        $response = $this->controller()->export('xlsx');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(400, $response->getStatus());
        $this->assertStringContainsString('format', $response->getData()['error']);
        $this->assertSame([], $this->streamed);

        $this->assertSame(400, $this->controller()->adminExport('csv', 'request', 'yesterday')->getStatus());
    }

    public function testTheAdminExportCoversEveryUserWithUsage(): void {
        $this->controller()->adminExport();

        $this->assertSame(['', 'alice', 'bob'], $this->streamed[0][1]);
        $this->assertTrue($this->streamed[0][2]);
    }

    public function testTheAdminExportFiltersByUser(): void {
        $this->controller()->adminExport(user: 'bob');

        $this->assertSame(['bob'], $this->streamed[0][1]);
        $this->assertTrue($this->streamed[0][2]);
    }

    public function testTheAdminExportFiltersByGroup(): void {
        $this->controller()->adminExport(group: 'finance');

        $this->assertSame(['bob', 'carol'], $this->streamed[0][1]);
    }

    public function testAnUnknownGroupIs404(): void {
        $response = $this->controller()->adminExport(group: 'nope');

        $this->assertSame(404, $response->getStatus());
        $this->assertSame([], $this->streamed);
    }

    public function testUserAndGroupTogetherAre400(): void {
        $response = $this->controller()->adminExport(user: 'bob', group: 'finance');

        $this->assertSame(400, $response->getStatus());
        $this->assertSame([], $this->streamed);
    }

    public function testEmptyUserAndGroupMeanEveryone(): void {
        $this->controller()->adminExport(user: '', group: '');

        $this->assertSame(['', 'alice', 'bob'], $this->streamed[0][1]);
    }
}
