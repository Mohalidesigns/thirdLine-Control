<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SsoConfigurationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage sso');
    }

    public function rules(): array
    {
        $protocol = $this->input('protocol');

        return [
            'protocol' => ['required', Rule::in(['saml2', 'oidc'])],
            'display_name' => ['required', 'string', 'max:255'],

            'entity_id' => [Rule::requiredIf($protocol === 'saml2'), 'nullable', 'string', 'max:255'],
            'sso_url' => [Rule::requiredIf($protocol === 'saml2'), 'nullable', 'url', 'max:255'],
            'slo_url' => ['nullable', 'url', 'max:255'],
            'x509_cert' => [Rule::requiredIf($protocol === 'saml2'), 'nullable', 'string'],

            'oidc_issuer' => [Rule::requiredIf($protocol === 'oidc'), 'nullable', 'url', 'max:255'],
            'oidc_client_id' => [Rule::requiredIf($protocol === 'oidc'), 'nullable', 'string', 'max:255'],
            'oidc_client_secret' => [Rule::requiredIf($protocol === 'oidc' && $this->isMethod('post')), 'nullable', 'string'],
            'oidc_discovery_url' => ['nullable', 'url', 'max:255'],

            'attribute_map' => ['nullable', 'array'],
            'attribute_map.email' => ['nullable', 'string', 'max:100'],
            'attribute_map.name' => ['nullable', 'string', 'max:100'],
            'attribute_map.groups' => ['nullable', 'string', 'max:100'],
            'attribute_map.role_map' => ['nullable', 'array'],
            'attribute_map.role_map.*' => ['string', Rule::exists('roles', 'name')],

            'jit_provisioning' => ['required', 'boolean'],
            'default_role_id' => ['nullable', 'integer', Rule::exists('roles', 'id')],
            'allowed_email_domains' => ['nullable', 'array'],
            'allowed_email_domains.*' => ['string', 'max:100'],
            'is_enabled' => ['required', 'boolean'],
        ];
    }
}
