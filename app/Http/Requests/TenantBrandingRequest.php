<?php

namespace App\Http\Requests;

use App\Rules\MeetsContrastRatio;
use Illuminate\Foundation\Http\FormRequest;

class TenantBrandingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage settings');
    }

    public function rules(): array
    {
        return [
            // Primary carries white text across the shell — contrast is a
            // hard gate. The accent is decorative (gold on navy) and only
            // needs to be a valid hex colour.
            'primary_colour' => ['nullable', 'string', new MeetsContrastRatio(4.5)],
            'accent_colour' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'product_name' => ['nullable', 'string', 'max:100'],
            'support_email' => ['nullable', 'email', 'max:255'],
            'support_phone' => ['nullable', 'string', 'max:50'],
            'report_header_html' => ['nullable', 'string', 'max:5000'],
            'report_footer_html' => ['nullable', 'string', 'max:5000'],
            'email_from_name' => ['nullable', 'string', 'max:100'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,svg,webp', 'max:1024'],
            'logo_dark' => ['nullable', 'image', 'mimes:png,jpg,jpeg,svg,webp', 'max:1024'],
            'favicon' => ['nullable', 'file', 'mimes:png,ico,svg', 'max:256'],
            'login_background' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ];
    }
}
