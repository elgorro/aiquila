<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Tests\Unit\Controller;

use OCA\AIquila\Controller\SequenceController;
use OCA\AIquila\Db\SequenceMapper;
use OCA\AIquila\Service\SequenceCatalog;
use OCA\AIquila\Service\SequencePolicy;
use OCA\AIquila\Service\SequenceService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SequenceControllerTest extends TestCase {
    private function controller(SequenceMapper $mapper, ?SequenceService $service = null, ?IRequest $request = null): SequenceController {
        return new SequenceController(
            'aiquila',
            $request ?? $this->createStub(IRequest::class),
            $mapper,
            $service ?? $this->createStub(SequenceService::class),
            $this->createStub(SequencePolicy::class),
            new SequenceCatalog(),
            'alice',
            $this->createStub(LoggerInterface::class),
        );
    }

    /**
     * Admin routes rely on the framework's admin check, which applies to every
     * method without #[NoAdminRequired]. That attribute must never reach them.
     */
    public function testOnlyUserRoutesSkipTheAdminCheck(): void {
        $class = new \ReflectionClass(SequenceController::class);
        foreach (['index', 'catalog', 'effective', 'create', 'update', 'destroy', 'setBuiltin'] as $method) {
            $this->assertNotEmpty($class->getMethod($method)->getAttributes(NoAdminRequired::class), $method);
        }
        foreach (['adminIndex', 'adminCreate', 'adminUpdate', 'adminDestroy', 'adminPolicy'] as $method) {
            $this->assertEmpty($class->getMethod($method)->getAttributes(NoAdminRequired::class), $method);
        }
    }

    public function testUserCannotChangeAnOrganizationSequence(): void {
        // findByIdAndUser only matches user-scope rows the caller owns.
        $mapper = $this->createMock(SequenceMapper::class);
        $mapper->method('findByIdAndUser')->willThrowException(new DoesNotExistException(''));
        $mapper->expects($this->never())->method('findInstanceById');
        $mapper->expects($this->never())->method('delete');

        $this->assertSame(404, $this->controller($mapper)->update(9, title: 'Hijacked')->getStatus());
        $this->assertSame(404, $this->controller($mapper)->destroy(9)->getStatus());
    }

    public function testInvalidInputIs400WithTheReason(): void {
        $service = $this->createStub(SequenceService::class);
        $service->method('create')->willThrowException(new \InvalidArgumentException('A sequence needs at least one step'));

        $response = $this->controller($this->createStub(SequenceMapper::class), $service)->create('x', 'X', []);

        $this->assertSame(400, $response->getStatus());
        $this->assertSame('A sequence needs at least one step', $response->getData()['error']);
    }

    public function testEffectiveAnswers304WhenTheTagMatches(): void {
        $body = ['sequences' => [], 'disabledBuiltins' => [], 'policy' => ['allowPersonal' => true, 'maxPerUser' => 50, 'maxSteps' => 20, 'maxPinned' => 10]];
        $service = $this->createStub(SequenceService::class);
        $service->method('effective')->willReturn($body);
        $etag = SequenceService::etag($body);

        $fresh = $this->controller($this->createStub(SequenceMapper::class), $service)->effective();
        $this->assertInstanceOf(JSONResponse::class, $fresh);
        $this->assertSame(200, $fresh->getStatus());
        // Response::setETag() takes the opaque tag and adds the quotes on output.
        $this->assertSame($etag, $fresh->getETag());

        $request = $this->createStub(IRequest::class);
        $request->method('getHeader')->willReturnCallback(static fn (string $name): string => $name === 'if-none-match' ? '"' . $etag . '"' : '');
        $cached = $this->controller($this->createStub(SequenceMapper::class), $service, $request)->effective();
        $this->assertSame(304, $cached->getStatus());
        $this->assertNotInstanceOf(JSONResponse::class, $cached);
        // RFC 9110 §15.4.5: a 304 carries the ETag a 200 would have.
        $this->assertSame($etag, $cached->getETag());
    }
}
