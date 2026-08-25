<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CR-03 §D.1: one staged checklist line, carrying what the workbook
 * said and what the importer resolved it to. `unresolved` is the row
 * state that blocks a commit — the old behaviour of quietly defaulting
 * an unrecognised frequency to Monthly is exactly what this prevents.
 */
class ControlFunctionImportRow extends Model
{
    use HasFactory;

    public const RESOLUTIONS = ['added', 'unchanged', 'changed', 'removed', 'unresolved'];

    protected $fillable = [
        'control_function_import_id', 'row_no', 'sheet', 'source_ref',
        'unit_raw', 'function_raw', 'checklist_raw', 'frequency_raw', 'frequency_id',
        'resolution', 'message', 'control_id', 'check_item_id',
    ];

    protected $casts = ['row_no' => 'integer'];

    public function import(): BelongsTo
    {
        return $this->belongsTo(ControlFunctionImport::class, 'control_function_import_id');
    }

    public function frequency(): BelongsTo
    {
        return $this->belongsTo(ControlFrequency::class, 'frequency_id');
    }

    public function scopeUnresolved(Builder $query): Builder
    {
        return $query->where('resolution', 'unresolved');
    }
}
