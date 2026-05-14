<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DetalleServicioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'producto_id' => 'nullable|integer|exists:productos,id',
            'observacion' => 'nullable|string',
            'cantidad' => 'required|numeric|min:0',
            'precio' => 'required|numeric|min:0',
            'descuento' => 'nullable|numeric|min:0',
        ];

        if ($this->isMethod('PUT')) {
            $rules['cantidad'] = 'nullable|numeric|min:0';
            $rules['precio'] = 'nullable|numeric|min:0';
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'cantidad.required' => 'La cantidad es obligatoria.',
            'cantidad.numeric' => 'La cantidad debe ser un valor numérico.',
            'precio.required' => 'El precio es obligatorio.',
            'precio.numeric' => 'El precio debe ser un valor numérico.',
            'producto_id.exists' => 'El producto seleccionado no existe.',
        ];
    }
}
