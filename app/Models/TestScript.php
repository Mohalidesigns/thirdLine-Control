<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasRichText;
use App\Models\Concerns\HasVersions;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class TestScript extends Model
{
    use Auditable, BelongsToTenant, HasFactory, HasRichText, HasVersions, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'control_id', 'version_no', 'title', 'objective',
        'sampling_guidance', 'status', 'created_by', 'approved_by', 'approved_at',
    ];

    /** Editor.js-backed fields — see HasRichText. */
    protected array $richText = ['objective', 'sampling_guidance'];

    protected $casts = [
        'version_no' => 'integer',
        'approved_at' => 'datetime',
    ];

    public function control(): BelongsTo
    {
        return $this->belongsTo(Control::class);
    }

    public function checkItems(): HasMany
    {
        return $this->hasMany(CheckItem::class)->orderBy('sequence');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
