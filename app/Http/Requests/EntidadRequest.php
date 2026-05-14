<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EntidadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $entidadId = $this->route('id');

        return [
            'tipo_persona' => 'required|in:Natural,Juridica',
            'tipo_id' => 'nullable|string|max:20',
            'identificacion' => 'required|string|max:50|unique:entidad,identificacion,' . $entidadId,
            'nombre' => 'required|string|max:255',
            'nombre_comercial' => 'nullable|string|max:255',
            'direccion' => 'nullable|string|max:255',
            'ciudad_cod' => 'nullable|string|max:10|exists:ciudades,cod_municipio',
            'dominio' => 'nullable|string|max:255',
            'rut' => 'nullable|string|max:255',
            'logo' => 'nullable|string|max:255',
            'estado' => 'nullable|string|max:50',
        ];
    }

    public function messages(): array
    {
        return [
            'tipo_persona.required' => 'El tipo de persona es obligatorio.',
            'tipo_persona.in' => 'El tipo de persona debe ser Natural o Juridica.',
            'identificacion.required' => 'La identificación es obligatoria.',
            'identificacion.unique' => 'Esta identificación ya está registrada.',
            'nombre.required' => 'El nombre es obligatorio.',
            'ciudad_cod.exists' => 'La ciudad seleccionada no existe.',
        ];
    }
}
