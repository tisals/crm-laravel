<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LugarEntidadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'area_oficina' => 'required|string|max:100',
            'direccion' => 'nullable|string|max:255',
            'direccion_adicional' => 'nullable|string|max:255',
            'ciudad_cod' => 'nullable|string|max:10|exists:ciudades,cod_municipio',
            'contacto_id' => 'nullable|integer|exists:contacto,id',
            'estado' => 'nullable|string|max:50',
        ];
    }

    public function messages(): array
    {
        return [
            'ciudad_cod.exists' => 'La ciudad seleccionada no existe.',
            'contacto_id.exists' => 'El contacto seleccionado no existe.',
        ];
    }
}
