<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocumentFolder extends Model
{
    use Auditable, BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id', 'parent_id', 'name', 'description', 'sort_order', 'path',
    ];

    protected $casts = ['sort_order' => 'integer'];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(DocumentFolder::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(DocumentFolder::class, 'parent_id')->orderBy('sort_order')->orderBy('name');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'folder_id');
    }

    /** Rebuild the materialised path ("Policies / HR / Conduct"). */
    public function refreshPath(): void
    {
        $segments = [$this->name];
        $cursor = $this->parent;
        $guard = 0;

        while ($cursor && $guard++ < 20) {
            array_unshift($segments, $cursor->name);
            $cursor = $cursor->parent;
        }

        $this->update(['path' => implode(' / ', $segments)]);
    }
}
