<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ReportTemplate extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'name', 'report_type', 'sections', 'header_config',
        'footer_config', 'logo_path', 'is_default',
    ];

    protected $casts = [
        'sections' => 'array',
        'header_config' => 'array',
        'footer_config' => 'array',
        'is_default' => 'boolean',
    ];
}
