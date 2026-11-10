<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Controller;

use OCA\AIquila\Db\CoworkerMapper;
use OCA\AIquila\Db\CoworkerRun;
use OCA\AIquila\Service\CoworkerTriggerService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * The trigger URL of a coworker, for other systems: an orchestrator, a
 * Webhook listeners webhook, a CI job.
 *
 * The caller has no Nextcloud session. It proves itself with the coworker's
 * trigger secret as a bearer token (RFC 6750 §2.1, the header form only: a
 * secret in the query string would end up in access logs). An unknown coworker
 * and a wrong secret get the same 401, so the endpoint does not tell which
 * coworker IDs exist, and both count as a brute-force attempt.
 *
 * The request body is not read. A trigger only says "run now"; what the run
 * works on is the coworker's own input folder, so a caller cannot put text in
 * front of the model or point the coworker at other files.
 */
class CoworkerTriggerController extends Controller {
    use ErrorResponseTrait;

    private const THROTTLE_ACTION = 'aiquilaCoworkerTrigger';

    public function __construct(
        string $appName,
        IRequest $request,
        private readonly CoworkerMapper $mapper,
        private readonly CoworkerTriggerService $triggers,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Start a coworker through its trigger URL
     *
     * Authenticate with `Authorization: Bearer <secret>`, the secret shown
     * when the trigger URL was created. The run starts within a minute, as a
     * background job; the response does not wait for it.
     *
     * @param int $id Coworker ID
     *
     * 202: A run is due
     * 401: Missing or wrong secret
     * 409: The coworker cannot run now: it is paused or disabled, or triggers are not allowed for its account
     * 429: Too many triggered runs in the last hour
     *
     * @return JSONResponse<Http::STATUS_ACCEPTED, array{coworkerId: int, dueAt: int}, array{}>|JSONResponse<Http::STATUS_UNAUTHORIZED|Http::STATUS_CONFLICT|Http::STATUS_TOO_MANY_REQUESTS, array{error: string, errorId: string}, array{}>
     */
    #[PublicPage]
    #[NoCSRFRequired]
    #[BruteForceProtection(action: self::THROTTLE_ACTION)]
    #[AnonRateLimit(limit: 30, period: 60)]
    #[OpenAPI]
    public function trigger(int $id): JSONResponse {
        $token = self::bearerToken($this->request->getHeader('authorization'));
        try {
            $coworker = $this->mapper->findById($id);
        } catch (\Throwable) {
            $coworker = null;
        }
        if ($coworker === null || $token === null || !CoworkerTriggerService::tokenMatches($coworker, $token)) {
            $response = $this->clientError(401, 'Unauthorized');
            // RFC 6750 §3: a protected resource names the scheme it expects.
            $response->addHeader('WWW-Authenticate', 'Bearer realm="AIquila coworker trigger"');
            $response->throttle(['action' => self::THROTTLE_ACTION]);
            return $response;
        }

        if (!$this->triggers->request($coworker, CoworkerRun::STARTED_BY_URL)) {
            if (!$coworker->getIsActive() || $coworker->getPaused()) {
                return $this->clientError(409, 'The coworker is paused or disabled');
            }
            if (!$this->triggers->isAllowedFor($coworker)) {
                return $this->clientError(409, 'Coworker triggers are not allowed for this coworker');
            }
            $response = $this->clientError(429, 'Too many triggered runs in the last hour');
            $response->addHeader('Retry-After', '600');
            return $response;
        }

        return new JSONResponse(
            ['coworkerId' => $coworker->getId(), 'dueAt' => (int)$coworker->getTriggerDueAt()],
            Http::STATUS_ACCEPTED,
        );
    }

    /** The credentials of `Authorization: Bearer <token>` (RFC 6750 §2.1), or null. */
    public static function bearerToken(string $header): ?string {
        if (preg_match('/^Bearer +([A-Za-z0-9\-._~+\/]+=*) *$/i', $header, $m) !== 1) {
            return null;
        }
        return $m[1];
    }
}
