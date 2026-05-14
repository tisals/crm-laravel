<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UsuarioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $userId = $this->route('id');

        return [
            'nombre' => 'required|string|max:150',
            'email' => 'required|email|max:150|unique:usuarios,email' . ($userId ? ",{$userId}" : ''),
            'password' => $this->isMethod('POST') ? 'required|string|min:8' : 'nullable|string|min:8',
            'rol_id' => 'required|exists:roles,id',
            'estado' => 'nullable|string|max:20',
        ];
    }

    public function messages(): array
    {
        return [
            'nombre.required' => 'El nombre es obligatorio.',
            'email.required' => 'El email es obligatorio.',
            'email.email' => 'El email debe ser válido.',
            'email.unique' => 'El email ya está registrado.',
            'password.required' => 'La contraseña es obligatoria.',
            'password.min' => 'La contraseña debe tener al menos 8 caracteres.',
            'rol_id.required' => 'El rol es obligatorio.',
            'rol_id.exists' => 'El rol seleccionado no existe.',
        ];
    }
}
