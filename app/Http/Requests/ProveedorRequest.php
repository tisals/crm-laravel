<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProveedorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $proveedorId = $this->route('id');

        return [
            'tipo_id' => 'nullable|string|max:20',
            'identificacion' => 'required|string|max:50|unique:proveedores,identificacion,' . $proveedorId,
            'nombres' => 'nullable|string|max:150',
            'apellidos' => 'nullable|string|max:150',
            'profesion' => 'nullable|string|max:150',
            'especialidad' => 'nullable|string|max:150',
            'iva' => 'nullable|numeric|min:0|max:100',
            'retenciones' => 'nullable|numeric|min:0|max:100',
            'ciudad_cod' => 'nullable|string|max:10|exists:ciudades,cod_municipio',
            'fecha_registro' => 'nullable|date',
            'estado' => 'nullable|string|max:50',
        ];
    }

    public function messages(): array
    {
        return [
            'identificacion.required' => 'La identificación es obligatoria.',
            'identificacion.unique' => 'Esta identificación ya está registrada.',
            'ciudad_cod.exists' => 'La ciudad seleccionada no existe.',
            'iva.numeric' => 'El IVA debe ser un valor numérico.',
            'iva.min' => 'El IVA no puede ser negativo.',
            'iva.max' => 'El IVA no puede exceder 100.',
            'retenciones.numeric' => 'Las retenciones deben ser un valor numérico.',
        ];
    }
}
