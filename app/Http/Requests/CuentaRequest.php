<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CuentaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'proveedor_id' => 'required|integer|exists:proveedores,id',
            'banco' => 'required|string|max:255',
            'numero_cuenta' => 'required|string|max:255',
            'tipo' => 'nullable|in:Ahorros,Corriente',
            'estado' => 'nullable|string|max:20',
        ];

        if ($this->isMethod('PUT')) {
            $rules['proveedor_id'] = 'nullable|integer|exists:proveedores,id';
            $rules['banco'] = 'nullable|string|max:255';
            $rules['numero_cuenta'] = 'nullable|string|max:255';
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'proveedor_id.required' => 'El proveedor es obligatorio.',
            'proveedor_id.exists' => 'El proveedor seleccionado no existe.',
            'banco.required' => 'El banco es obligatorio.',
            'numero_cuenta.required' => 'El número de cuenta es obligatorio.',
            'tipo.in' => 'El tipo debe ser Ahorros o Corriente.',
        ];
    }
}
