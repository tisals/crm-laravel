<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ContactoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $contactoId = $this->route('id');

        return [
            'entidad_id' => 'nullable|integer|exists:entidad,id',
            'nombres' => 'required|string|max:150',
            'apellidos' => 'required|string|max:150',
            'area' => 'nullable|string|max:100',
            'cargo' => 'nullable|string|max:100',
            'tel_contacto' => 'nullable|string|max:50',
            'movil' => 'nullable|string|max:50',
            'email_contacto' => 'nullable|email|max:255',
            'email_secundario' => 'nullable|email|max:255',
            'rol' => 'nullable|string|max:100',
            'etapa' => 'nullable|string|max:50',
            'estado' => 'nullable|string|max:50',
        ];
    }

    public function messages(): array
    {
        return [
            'nombres.required' => 'Los nombres son obligatorios.',
            'apellidos.required' => 'Los apellidos son obligatorios.',
            'email_contacto.email' => 'El correo electrónico no es válido.',
            'email_secundario.email' => 'El correo secundario no es válido.',
        ];
    }
}
