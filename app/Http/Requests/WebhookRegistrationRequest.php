<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class WebhookRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'organization_name' => 'required|string|max:255',
            'contact_name' => 'required|string|max:255',
            'contact_email' => 'required|email|max:150',
            'plan_type' => 'nullable|string|max:100',
            'service_name' => 'nullable|string|max:255',
            'source' => 'nullable|string|max:100',
            'diagnostico_data' => 'nullable|array',
        ];
    }

    public function messages(): array
    {
        return [
            'organization_name.required' => 'El nombre de la organización es obligatorio.',
            'contact_name.required' => 'El nombre del contacto es obligatorio.',
            'contact_email.required' => 'El email del contacto es obligatorio.',
            'contact_email.email' => 'El email debe ser válido.',
        ];
    }
}
