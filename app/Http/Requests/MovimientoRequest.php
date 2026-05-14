<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MovimientoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'fecha' => 'required|date',
            'valor_debito' => 'nullable|numeric|min:0',
            'valor_credito' => 'nullable|numeric|min:0',
            'proveedor_id' => 'nullable|integer|exists:proveedores,id',
            'colaborador_id' => 'nullable|integer|exists:colaboradores,id',
            'servicio_id' => 'nullable|integer|exists:servicios,id',
            'observaciones' => 'nullable|string',
        ];

        if ($this->isMethod('PUT')) {
            $rules['fecha'] = 'nullable|date';
        }

        return $rules;
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $debito = (float) ($this->input('valor_debito', 0));
            $credito = (float) ($this->input('valor_credito', 0));

            if ($debito <= 0 && $credito <= 0) {
                $validator->errors()->add('valor_debito', 'Debe especificar un valor de débito o crédito mayor a cero.');
                $validator->errors()->add('valor_credito', 'Debe especificar un valor de débito o crédito mayor a cero.');
            }

            if ($debito > 0 && $credito > 0) {
                $validator->errors()->add('valor_debito', 'No puede especificar débito y crédito simultáneamente.');
                $validator->errors()->add('valor_credito', 'No puede especificar débito y crédito simultáneamente.');
            }
        });
    }

    public function messages(): array
    {
        return [
            'fecha.required' => 'La fecha es obligatoria.',
            'fecha.date' => 'La fecha no es válida.',
            'proveedor_id.exists' => 'El proveedor seleccionado no existe.',
            'colaborador_id.exists' => 'El colaborador seleccionado no existe.',
            'servicio_id.exists' => 'El servicio seleccionado no existe.',
        ];
    }
}
