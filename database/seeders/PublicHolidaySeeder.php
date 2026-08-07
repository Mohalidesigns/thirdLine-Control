<?php

namespace Database\Seeders;

use App\Models\PublicHoliday;
use Illuminate\Database\Seeder;

/**
 * Public holidays are data (R1) so a filing deadline never lands on a day
 * the regulator is shut.
 *
 * Only holidays with a deterministic date are seeded:
 *   - fixed-date holidays, stored once as recurring rows;
 *   - Easter-derived holidays, computed per year.
 *
 * Islamic holidays (Eid al-Fitr, Eid al-Adha, Maulud) follow the lunar
 * calendar and are declared by the Federal Government each year, as are
 * ad-hoc holidays and the "following Monday" substitutions. Those must be
 * added per year by an administrator — the platform will not guess a date
 * a compliance deadline depends on.
 */
class PublicHolidaySeeder extends Seeder
{
    /** [month, day, name] — same date every year. */
    private const NG_FIXED = [
        [1, 1, "New Year's Day"],
        [5, 1, 'Workers’ Day'],
        [6, 12, 'Democracy Day'],
        [10, 1, 'Independence Day'],
        [12, 25, 'Christmas Day'],
        [12, 26, 'Boxing Day'],
    ];

    /** Years for which Easter-derived holidays are materialised. */
    private const EASTER_YEARS_AHEAD = 6;

    public function run(): void
    {
        foreach (self::NG_FIXED as [$month, $day, $name]) {
            PublicHoliday::withoutGlobalScopes()->updateOrCreate(
                [
                    'tenant_id' => null,
                    'jurisdiction' => 'NG',
                    'holiday_date' => sprintf('2000-%02d-%02d', $month, $day),
                    'name' => $name,
                ],
                ['is_recurring' => true, 'source' => 'Public Holidays Act (Nigeria)'],
            );
        }

        $startYear = (int) now()->year;

        for ($year = $startYear - 1; $year <= $startYear + self::EASTER_YEARS_AHEAD; $year++) {
            $easter = $this->easterSunday($year);

            foreach ([
                ['Good Friday', (clone $easter)->modify('-2 days')],
                ['Easter Monday', (clone $easter)->modify('+1 day')],
            ] as [$name, $date]) {
                PublicHoliday::withoutGlobalScopes()->updateOrCreate(
                    [
                        'tenant_id' => null,
                        'jurisdiction' => 'NG',
                        'holiday_date' => $date->format('Y-m-d'),
                        'name' => $name,
                    ],
                    ['is_recurring' => false, 'source' => 'Public Holidays Act (Nigeria) — Easter-derived'],
                );
            }
        }
    }

    /** Anonymous Gregorian algorithm — no calendar extension required. */
    private function easterSunday(int $year): \DateTimeImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
    }
}
