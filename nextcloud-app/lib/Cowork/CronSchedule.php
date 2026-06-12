<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\AIquila\Cowork;

/**
 * Minimal standard 5-field cron evaluator (minute hour day-of-month month
 * day-of-week). Supports '*', lists (1,2), ranges (1-5), and steps (* / 15,
 * 0-30/10). No special strings (@daily etc.) — the UI emits explicit fields.
 *
 * Used to compute the next run timestamp for a coworker. All computation is in
 * UTC; schedules are interpreted accordingly.
 */
class CronSchedule {

    /** @var array{0: list<int>, 1: list<int>, 2: list<int>, 3: list<int>, 4: list<int>} */
    private array $fields;

    public function __construct(string $expression) {
        $parts = preg_split('/\s+/', trim($expression)) ?: [];
        if (count($parts) !== 5) {
            throw new \InvalidArgumentException("Cron expression must have 5 fields, got: $expression");
        }
        $this->fields = [
            $this->parseField($parts[0], 0, 59),
            $this->parseField($parts[1], 0, 23),
            $this->parseField($parts[2], 1, 31),
            $this->parseField($parts[3], 1, 12),
            $this->parseField($parts[4], 0, 7),
        ];
    }

    /** Validate without retaining the instance. */
    public static function isValid(string $expression): bool {
        try {
            new self($expression);
            return true;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    /**
     * First matching timestamp strictly after $after (unix seconds), or null if
     * none within a one-year horizon (impossible schedule).
     */
    public function getNextRunTime(int $after): ?int {
        // Advance to the start of the next minute.
        $ts = ($after - ($after % 60)) + 60;
        $horizon = $after + 366 * 86400;
        for (; $ts <= $horizon; $ts += 60) {
            if ($this->matches($ts)) {
                return $ts;
            }
        }
        return null;
    }

    private function matches(int $ts): bool {
        $min = (int)gmdate('i', $ts);
        $hour = (int)gmdate('G', $ts);
        $dom = (int)gmdate('j', $ts);
        $mon = (int)gmdate('n', $ts);
        $dow = (int)gmdate('w', $ts); // 0=Sunday

        $domField = $this->fields[2];
        $dowField = $this->fields[4];
        // Normalise Sunday (7 -> 0) for matching.
        $dowMatch = in_array($dow, $dowField, true) || in_array($dow === 0 ? 7 : $dow, $dowField, true);

        return in_array($min, $this->fields[0], true)
            && in_array($hour, $this->fields[1], true)
            && in_array($dom, $domField, true)
            && in_array($mon, $this->fields[3], true)
            && $dowMatch;
    }

    /**
     * @return list<int>
     */
    private function parseField(string $field, int $min, int $max): array {
        $values = [];
        foreach (explode(',', $field) as $token) {
            $step = 1;
            $range = $token;
            if (str_contains($token, '/')) {
                [$range, $stepStr] = explode('/', $token, 2);
                $step = (int)$stepStr;
                if ($step < 1) {
                    throw new \InvalidArgumentException("Invalid step in cron field: $token");
                }
            }

            if ($range === '*') {
                $start = $min;
                $end = $max;
            } elseif (str_contains($range, '-')) {
                [$a, $b] = explode('-', $range, 2);
                $start = (int)$a;
                $end = (int)$b;
            } else {
                $start = $end = (int)$range;
            }

            if ($start < $min || $end > $max || $start > $end) {
                throw new \InvalidArgumentException("Cron field value out of range: $token");
            }
            for ($i = $start; $i <= $end; $i += $step) {
                $values[$i] = $i;
            }
        }
        if ($values === []) {
            throw new \InvalidArgumentException("Empty cron field: $field");
        }
        ksort($values);
        return array_values($values);
    }
}
