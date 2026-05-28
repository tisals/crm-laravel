<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CiudadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'cod_municipio' => 'required|string|max:10',
            'nombre' => 'required|string|max:255',
            'departamento' => 'required|string|max:100',
        ];

        if ($this->isMethod('PUT') || $this->isMethod('PATCH')) {
            $rules = [
                'cod_municipio' => 'sometimes|string|max:10',
                'nombre' => 'sometimes|string|max:255',
                'departamento' => 'sometimes|string|max:100',
            ];
        }

        return $rules;
    }
}
