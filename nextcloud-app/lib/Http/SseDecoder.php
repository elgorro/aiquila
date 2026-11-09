<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Http;

/**
 * Incremental Server-Sent Events decoder.
 *
 * Bytes arrive in whatever pieces the transport hands over, so an event can be
 * split anywhere, a line ending included. feed() keeps the incomplete tail and
 * returns only the events a blank line has completed.
 *
 * Follows the event stream interpretation in the WHATWG HTML standard
 * (§9.2.6): lines end in CRLF, LF or CR; a line starting with a colon is a
 * comment; one space after the field's colon is dropped; repeated `data`
 * lines join with a newline; an event without data is not dispatched. `id`
 * and `retry` only matter to a reconnecting client, so they are ignored.
 */
final class SseDecoder {
    private string $buffer = '';
    private string $event = '';
    /** @var list<string> */
    private array $data = [];

    /**
     * @return list<array{event: string, data: string}> `event` is '' when the stream named none
     */
    public function feed(string $chunk): array {
        $this->buffer .= $chunk;
        $events = [];
        while (preg_match('/\r\n|\n|\r/', $this->buffer, $match, PREG_OFFSET_CAPTURE) === 1) {
            $end = $match[0][1];
            $separator = $match[0][0];
            // A CR at the very end may be the first half of a CRLF still in flight.
            if ($separator === "\r" && $end === strlen($this->buffer) - 1) {
                break;
            }
            $line = substr($this->buffer, 0, $end);
            $this->buffer = substr($this->buffer, $end + strlen($separator));
            $event = $this->line($line);
            if ($event !== null) {
                $events[] = $event;
            }
        }
        return $events;
    }

    /** @return array{event: string, data: string}|null */
    private function line(string $line): ?array {
        if ($line === '') {
            $event = $this->data === [] ? null : ['event' => $this->event, 'data' => implode("\n", $this->data)];
            $this->event = '';
            $this->data = [];
            return $event;
        }
        if ($line[0] === ':') {
            return null;
        }
        $colon = strpos($line, ':');
        $field = $colon === false ? $line : substr($line, 0, $colon);
        $value = $colon === false ? '' : substr($line, $colon + 1);
        if (str_starts_with($value, ' ')) {
            $value = substr($value, 1);
        }
        if ($field === 'event') {
            $this->event = $value;
        } elseif ($field === 'data') {
            $this->data[] = $value;
        }
        return null;
    }
}
