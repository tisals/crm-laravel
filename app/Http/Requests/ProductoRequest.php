<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProductoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nombre' => 'required|string|max:200',
            'linea_negocio' => 'nullable|string|max:100',
            'iva' => 'nullable|numeric|min:0|max:100',
            'estado' => 'nullable|string|max:20',
        ];
    }

    public function messages(): array
    {
        return [
            'nombre.required' => 'El nombre es obligatorio.',
            'nombre.max' => 'El nombre no debe exceder 200 caracteres.',
            'iva.numeric' => 'El IVA debe ser numérico.',
            'iva.min' => 'El IVA no puede ser menor a 0.',
            'iva.max' => 'El IVA no puede ser mayor a 100.',
        ];
    }
}
