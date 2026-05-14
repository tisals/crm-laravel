<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EtiquetaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $etiquetaId = $this->route('id');

        return [
            'nombre' => 'required|string|max:100|unique:etiquetas,nombre' . ($etiquetaId ? ",{$etiquetaId}" : ''),
            'estado' => 'nullable|string|max:20',
        ];
    }

    public function messages(): array
    {
        return [
            'nombre.required' => 'El nombre es obligatorio.',
            'nombre.unique' => 'El nombre ya está registrado.',
            'nombre.max' => 'El nombre no debe exceder 100 caracteres.',
        ];
    }
}
