<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Next-run resolution for a five-field cron expression, in the schedule's own
 * timezone (R7). A Lagos bank's "07:00 on the first of the month" is 07:00 in
 * Lagos whichever region the server sits in, so the walk happens in the
 * schedule's zone and the answer comes back in UTC for storage.
 *
 * Deliberately narrow: minute, hour, day-of-month, month and day-of-week,
 * each a `*`, a number, a comma list, a `*\/n` step or an `a-b` range. That
 * covers every reporting cadence a compliance calendar has, and anything
 * beyond it is rejected rather than half-supported.
 */
final class CronSchedule
{
    /** Cadences the schedule form offers directly. */
    public const PRESETS = [
        '0 7 * * 1' => 'Every Monday at 07:00',
        '0 7 1 * *' => 'First of the month at 07:00',
        '0 7 1 1,4,7,10 *' => 'First day of each quarter at 07:00',
        '0 7 * * *' => 'Every day at 07:00',
        '0 18 * * 5' => 'Every Friday at 18:00',
        '0 7 1 1 *' => 'Annually on 1 January at 07:00',
    ];

    /** Days to walk before giving up — a little over four years. */
    private const HORIZON_DAYS = 1500;

    public static function isValid(string $expression): bool
    {
        $fields = preg_split('/\s+/', trim($expression)) ?: [];

        if (count($fields) !== 5) {
            return false;
        }

        foreach ($fields as $index => $field) {
            [$min, $max] = self::bounds($index);

            if (self::values($field, $min, $max) === null) {
                return false;
            }
        }

        return true;
    }

    public static function nextRun(string $expression, string $timezone = 'UTC', ?Carbon $from = null): ?Carbon
    {
        if (! self::isValid($expression)) {
            return null;
        }

        $fields = preg_split('/\s+/', trim($expression));

        $minutes = self::values($fields[0], 0, 59);
        $hours = self::values($fields[1], 0, 23);
        $days = self::values($fields[2], 1, 31);
        $months = self::values($fields[3], 1, 12);
        $weekdays = self::values($fields[4], 0, 6);

        $after = ($from ? $from->copy() : Carbon::now())->setTimezone($timezone)->startOfMinute();

        // A day is a match if day-of-month matches OR day-of-week matches,
        // except when both fields are restricted, in which case cron's own
        // rule is the union — which is what this reproduces.
        $dayRestricted = $fields[2] !== '*';
        $weekdayRestricted = $fields[4] !== '*';

        // Walk by day, not by minute: an annual schedule is at most 366 day
        // checks and a handful of time comparisons, never half a million.
        for ($offset = 0; $offset <= self::HORIZON_DAYS; $offset++) {
            $day = $after->copy()->addDays($offset)->startOfDay();

            if (! in_array($day->month, $months, true)) {
                continue;
            }

            $dayMatches = match (true) {
                $dayRestricted && $weekdayRestricted => in_array($day->day, $days, true)
                    || in_array($day->dayOfWeek, $weekdays, true),
                $dayRestricted => in_array($day->day, $days, true),
                $weekdayRestricted => in_array($day->dayOfWeek, $weekdays, true),
                default => true,
            };

            if (! $dayMatches) {
                continue;
            }

            foreach ($hours as $hour) {
                foreach ($minutes as $minute) {
                    $candidate = $day->copy()->setTime($hour, $minute);

                    if ($candidate->greaterThan($after)) {
                        return $candidate->utc();
                    }
                }
            }
        }

        return null;
    }

    /** @return array{0: int, 1: int} */
    private static function bounds(int $index): array
    {
        return match ($index) {
            0 => [0, 59],
            1 => [0, 23],
            2 => [1, 31],
            3 => [1, 12],
            default => [0, 6],
        };
    }

    /** @return array<int, int>|null null when the field is not understood */
    private static function values(string $field, int $min, int $max): ?array
    {
        $values = [];

        foreach (explode(',', $field) as $part) {
            $step = 1;

            if (str_contains($part, '/')) {
                [$part, $stepText] = explode('/', $part, 2);

                if (! ctype_digit($stepText) || (int) $stepText < 1) {
                    return null;
                }

                $step = (int) $stepText;
            }

            if ($part === '*') {
                $from = $min;
                $to = $max;
            } elseif (str_contains($part, '-')) {
                [$fromText, $toText] = explode('-', $part, 2);

                if (! ctype_digit($fromText) || ! ctype_digit($toText)) {
                    return null;
                }

                $from = (int) $fromText;
                $to = (int) $toText;
            } elseif (ctype_digit($part)) {
                $from = $to = (int) $part;
            } else {
                return null;
            }

            if ($from < $min || $to > $max || $from > $to) {
                return null;
            }

            for ($value = $from; $value <= $to; $value += $step) {
                $values[] = $value;
            }
        }

        $values = array_values(array_unique($values));
        sort($values);

        return $values === [] ? null : $values;
    }
}
