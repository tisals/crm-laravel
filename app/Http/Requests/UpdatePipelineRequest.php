<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePipelineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nombre' => 'sometimes|string|max:255',
            'codigo' => 'sometimes|string|max:50|unique:pipelines,codigo,'.$this->route('id'),
            'habilitado' => 'boolean|nullable',
        ];
    }

    public function messages(): array
    {
        return [
            'codigo.unique' => 'El código ya está en uso por otro pipeline.',
        ];
    }
}
