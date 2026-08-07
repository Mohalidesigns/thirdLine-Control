<?php

namespace Database\Seeders;

use App\Models\ExchangeRate;
use Illuminate\Database\Seeder;

class ExchangeRateSeeder extends Seeder
{
    /**
     * Indicative manual reference rates (units of NGN per one unit of the
     * base currency). Demo data only — production tenants load real rates;
     * the automated rate provider lands in Phase 12.
     */
    private const NGN_PER_UNIT = [
        'USD' => '1500.0000000000',
        'EUR' => '1640.0000000000',
        'GBP' => '1905.0000000000',
        'ZAR' => '82.5000000000',
        'GHS' => '101.0000000000',
        'KES' => '11.6000000000',
    ];

    public function run(): void
    {
        $effectiveDate = now()->startOfMonth()->toDateString();

        foreach (self::NGN_PER_UNIT as $currency => $rate) {
            $this->upsert($currency, 'NGN', $rate, $effectiveDate);
            $this->upsert('NGN', $currency, bcdiv('1', $rate, 10), $effectiveDate);
        }

        // USD crosses derived from the NGN legs, so the set is consistent.
        foreach (self::NGN_PER_UNIT as $currency => $ngnPerUnit) {
            if ($currency === 'USD') {
                continue;
            }
            $usdPerUnit = bcdiv($ngnPerUnit, self::NGN_PER_UNIT['USD'], 10);
            $this->upsert($currency, 'USD', $usdPerUnit, $effectiveDate);
            $this->upsert('USD', $currency, bcdiv('1', $usdPerUnit, 10), $effectiveDate);
        }
    }

    private function upsert(string $base, string $quote, string $rate, string $effectiveDate): void
    {
        ExchangeRate::updateOrCreate(
            [
                'tenant_id' => null,
                'base_currency' => $base,
                'quote_currency' => $quote,
                'effective_date' => $effectiveDate,
            ],
            ['rate' => $rate, 'source' => 'manual'],
        );
    }
}
