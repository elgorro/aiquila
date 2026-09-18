<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\SetupCheck;

use OCA\AIquila\Http\SseStreaming;
use OCP\Http\Client\IClientService;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;

/**
 * Detects the one failure mode that makes streaming chat look broken without
 * anything reporting an error: something between PHP and the browser holds the
 * response until it is complete, so the whole reply appears at once after a
 * long silence.
 *
 * Reverse proxies and compression filters both do this, and neither logs it, so
 * the only reliable test is to send a stream and time it. This check requests
 * the app's probe endpoint over the loopback and measures when the ticks land.
 */
class StreamingNotBuffered implements ISetupCheck {
    use SseStreaming;

    /**
     * How much of the probe's own pacing has to survive the trip. The probe
     * emits four ticks 150 ms apart, so an unbuffered response spans ~450 ms;
     * a buffered one arrives in a single read.
     */
    private const MIN_SPREAD_SECONDS = 0.2;

    private const TIMEOUT_SECONDS = 15;

    public function __construct(
        private IClientService $clientService,
        private IURLGenerator $urlGenerator,
        private IL10N $l10n,
    ) {
    }

    public function getName(): string {
        return $this->l10n->t('AIquila streaming responses');
    }

    public function getCategory(): string {
        return 'network';
    }

    public function run(): SetupResult {
        $url = $this->probeUrl();
        if ($url === null) {
            return SetupResult::info(
                $this->l10n->t('Could not test streaming: this instance has no resolvable URL.')
            );
        }

        try {
            $response = $this->clientService->newClient()->get($url, [
                'headers' => ['Accept' => 'text/event-stream', 'Accept-Encoding' => 'gzip'],
                'stream' => true,
                'timeout' => self::TIMEOUT_SECONDS,
                'nextcloud' => ['allow_local_address' => true],
            ] + $this->sseTransportOptions());
        } catch (\Throwable $e) {
            // The instance cannot reach itself over HTTP — a common and
            // legitimate setup. That says nothing about buffering.
            return SetupResult::info(
                $this->l10n->t('Could not test streaming: %s', [$e->getMessage()])
            );
        }

        $encoding = strtolower(trim(implode(',', (array)$response->getHeader('Content-Encoding'))));
        if ($encoding !== '' && $encoding !== 'identity') {
            return SetupResult::warning($this->l10n->t(
                'Streaming responses are being compressed (Content-Encoding: %s). '
                . 'Compression buffers the response, so chat replies will appear all at once '
                . 'instead of as they are written. Exclude text/event-stream from compression '
                . 'in your web server or reverse proxy.',
                [$encoding]
            ));
        }

        $stream = $response->getBody();
        if (is_string($stream) || !is_resource($stream)) {
            return SetupResult::info(
                $this->l10n->t('Could not test streaming: the HTTP client did not return a readable stream.')
            );
        }

        $this->tuneSseStream($stream);
        $start = microtime(true);
        $firstTick = null;
        $lastTick = null;
        while (!feof($stream)) {
            $chunk = fread($stream, 8192);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $now = microtime(true);
            if ($firstTick === null) {
                $firstTick = $now;
            }
            $lastTick = $now;
            if ($now - $start > self::TIMEOUT_SECONDS) {
                break;
            }
        }
        fclose($stream);

        if ($firstTick === null) {
            return SetupResult::info($this->l10n->t('Could not test streaming: the probe returned no data.'));
        }

        $spread = $lastTick - $firstTick;
        if ($spread < self::MIN_SPREAD_SECONDS) {
            return SetupResult::warning($this->l10n->t(
                'Streaming responses are being buffered: the whole test stream arrived in one piece. '
                . 'Chat replies will appear all at once after a pause instead of as they are written. '
                . 'Check your reverse proxy for response buffering and for compression of text/event-stream.'
            ));
        }

        return SetupResult::success($this->l10n->t('Streaming responses reach the browser incrementally.'));
    }

    /**
     * Absolute URL of the probe endpoint.
     *
     * getAbsoluteURL() is not enough on its own: run from occ there is no
     * request to take a host from, and it hands back a bare path. The origin
     * comes from getBaseUrl() instead, which honours overwrite.cli.url, while
     * the path comes from the router so a web root is included exactly once.
     */
    private function probeUrl(): ?string {
        $base = parse_url($this->urlGenerator->getBaseUrl());
        if (!is_array($base) || empty($base['scheme']) || empty($base['host'])) {
            return null;
        }
        $origin = $base['scheme'] . '://' . $base['host'];
        if (!empty($base['port'])) {
            $origin .= ':' . $base['port'];
        }

        return $origin . $this->urlGenerator->linkToRoute('aiquila.streamProbe.probe');
    }
}
