<?php

namespace Modules\CRM\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReorderEtapasRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ordered_ids' => 'required|array',
            'ordered_ids.*' => 'integer',
        ];
    }

    public function messages(): array
    {
        return [
            'ordered_ids.required' => 'La lista de IDs ordenados es obligatoria.',
            'ordered_ids.array' => 'La lista de IDs debe ser un array.',
            'ordered_ids.*.integer' => 'Cada ID debe ser un número entero.',
        ];
    }
}
