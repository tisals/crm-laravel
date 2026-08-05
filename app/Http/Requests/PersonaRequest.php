<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PersonaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'identificacion_tipo' => 'nullable|string|max:10',
            'identificacion_numero' => 'nullable|string|max:20',
            'nombres' => 'required|string|max:100',
            'apellidos' => 'nullable|string|max:100',
            'email_principal' => 'nullable|email|max:150',
            'telefono_principal' => 'nullable|string|max:30',
            'direccion' => 'nullable|string|max:200',
            'ciudad' => 'nullable|string|max:100',
            'pais' => 'nullable|string|max:100',
        ];
    }

    public function messages(): array
    {
        return [
            'nombres.required' => 'Los nombres son obligatorios.',
            'email_principal.email' => 'El correo principal no es válido.',
        ];
    }
}
