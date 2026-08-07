<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class TenantBranding extends Model
{
    use Auditable, BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'logo_path',
        'logo_dark_path',
        'favicon_path',
        'primary_colour',
        'accent_colour',
        'login_background_path',
        'product_name',
        'support_email',
        'support_phone',
        'report_header_html',
        'report_footer_html',
        'email_from_name',
    ];

    /**
     * Frontend payload: public URLs and colours. AEGIS tokens remain the
     * defaults — null means "no override".
     */
    public function toSharedProps(): array
    {
        $url = fn (?string $path) => $path ? Storage::disk('public')->url($path) : null;

        return [
            'logo_url' => $url($this->logo_path),
            'logo_dark_url' => $url($this->logo_dark_path),
            'favicon_url' => $url($this->favicon_path),
            'login_background_url' => $url($this->login_background_path),
            'primary_colour' => $this->primary_colour,
            'accent_colour' => $this->accent_colour,
            'product_name' => $this->product_name,
            'support_email' => $this->support_email,
            'support_phone' => $this->support_phone,
        ];
    }
}
