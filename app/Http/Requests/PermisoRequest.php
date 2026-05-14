<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PermisoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'rol_id' => 'required|exists:roles,id',
            'vista' => 'required|string|max:100',
        ];
    }

    public function messages(): array
    {
        return [
            'rol_id.required' => 'El rol es obligatorio.',
            'rol_id.exists' => 'El rol seleccionado no existe.',
            'vista.required' => 'La vista es obligatoria.',
            'vista.max' => 'La vista no debe exceder 100 caracteres.',
        ];
    }
}
