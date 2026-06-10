<?php

namespace Modules\CRM\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEtapaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nombre' => 'sometimes|string|max:255',
            'habilitado' => 'boolean|nullable',
        ];
    }

    public function messages(): array
    {
        return [
            'nombre.string' => 'El nombre debe ser un texto válido.',
        ];
    }
}
