<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CR-03 §C.1: one raw spelling from the source workbook mapped to a
 * frequency. `normalised` is what the resolver looks up — the workbook
 * writes "Quaterly", "bi-annually" and "Trade  Control" with trailing
 * doubled spaces, and none of that should reach PHP as a special case.
 */
class FrequencyAlias extends Model
{
    use HasFactory;

    protected $fillable = ['tenant_id', 'control_frequency_id', 'raw', 'normalised'];

    public function frequency(): BelongsTo
    {
        return $this->belongsTo(ControlFrequency::class, 'control_frequency_id');
    }

    /** The single place a raw frequency string becomes a lookup key. */
    public static function normalise(?string $raw): string
    {
        $value = str_replace(["\xc2\xa0", "\r", "\n", "\t"], ' ', (string) $raw);

        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value)));
    }
}
