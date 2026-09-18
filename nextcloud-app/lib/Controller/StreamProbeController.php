<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Controller;

use OCA\AIquila\Http\SSEResponse;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;

/**
 * A miniature Server-Sent Events endpoint whose only job is to be measured.
 *
 * The chat stream cannot double as a diagnostic: it needs a configured
 * provider, a conversation and an LLM round trip, and it bills the account.
 * This one emits a few empty ticks a fixed interval apart, so the StreamingNotBuffered
 * setup check can tell whether something between PHP and the client is holding
 * the response back — the failure that turns streaming chat into a long wait
 * followed by the whole reply at once.
 *
 * It is a public page because the setup check requests it over the loopback
 * without a session. It reveals nothing: the payload is a tick index and a
 * server timestamp.
 */
class StreamProbeController extends Controller {
    /** Number of ticks emitted per probe. */
    private const TICKS = 4;

    /** Delay between ticks, in microseconds. */
    private const TICK_INTERVAL_US = 150000;

    public function __construct(string $appName, IRequest $request) {
        parent::__construct($appName, $request);
    }

    #[PublicPage]
    #[NoCSRFRequired]
    #[AnonRateLimit(limit: 10, period: 60)]
    #[OpenAPI(scope: OpenAPI::SCOPE_IGNORE)]
    public function probe(): Response {
        return new SSEResponse($this->ticks());
    }

    /**
     * @return \Generator<int, array<string, mixed>>
     */
    private function ticks(): \Generator {
        for ($i = 0; $i < self::TICKS; $i++) {
            if ($i > 0) {
                usleep(self::TICK_INTERVAL_US);
            }
            yield ['type' => 'tick', 'index' => $i, 'sentAt' => microtime(true)];
        }
    }
}
