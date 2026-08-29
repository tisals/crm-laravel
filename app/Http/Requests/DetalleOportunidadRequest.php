<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\CRM\Models\DetalleOportunidad;

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
            'concepto' => 'nullable|string|max:5000',
            'descripcion' => 'nullable|string|max:1000',
            'medida' => 'nullable|string|max:10|in:Und,Hrs,Srv',
            'cantidad' => 'required|numeric|min:0.01',
            'vr_unitario' => 'required|numeric|min:0',
            'iva' => 'nullable|numeric|min:0|max:100',
            // REQ-DOP-002 — closed allow-list of offer types. Nullable so
            // existing callers can omit the field (DB default 'servicio'
            // applies on write).
            'tipo_oferta' => [
                'nullable',
                'string',
                'max:50',
                Rule::in(DetalleOportunidad::TIPOS_OFERTA),
            ],
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
        $allowed = implode(', ', DetalleOportunidad::TIPOS_OFERTA);

        return [
            'producto_id.required' => 'El producto es obligatorio.',
            'producto_id.exists' => 'El producto seleccionado no existe.',
            'cantidad.required' => 'La cantidad es obligatoria.',
            'cantidad.min' => 'La cantidad debe ser mayor a 0.',
            'vr_unitario.required' => 'El valor unitario es obligatorio.',
            'medida.in' => 'La medida debe ser Und, Hrs o Srv.',
            'tipo_oferta.in' => "Tipo de oferta no válido. Valores aceptados: {$allowed}.",
            'tipo_oferta.max' => 'El tipo de oferta no debe exceder 50 caracteres.',
        ];
    }
}
