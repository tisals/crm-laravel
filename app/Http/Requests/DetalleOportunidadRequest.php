<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DetalleOportunidadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'producto_id' => 'required|integer|exists:productos,id',
            'concepto' => 'nullable|string|max:255',
            'medida' => 'nullable|string|max:10|in:Und,Hrs,Srv',
            'cantidad' => 'required|numeric|min:0.01',
            'vr_unitario' => 'required|numeric|min:0',
        ];

        if ($this->isMethod('PUT')) {
            $rules['producto_id'] = 'nullable|integer|exists:productos,id';
            $rules['cantidad'] = 'nullable|numeric|min:0.01';
            $rules['vr_unitario'] = 'nullable|numeric|min:0';
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'producto_id.required' => 'El producto es obligatorio.',
            'producto_id.exists' => 'El producto seleccionado no existe.',
            'cantidad.required' => 'La cantidad es obligatoria.',
            'cantidad.min' => 'La cantidad debe ser mayor a 0.',
            'vr_unitario.required' => 'El valor unitario es obligatorio.',
            'medida.in' => 'La medida debe ser Und, Hrs o Srv.',
        ];
    }
}
