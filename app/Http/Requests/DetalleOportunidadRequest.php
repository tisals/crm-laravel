<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class DetalleOportunidadRequest extends FormRequest
{
    /**
     * Persistable fields shared by POST and PUT. PUT additionally requires
     * at least one field to be present (no empty-body PUTs).
     */
    private const PERSISTABLE_FIELDS = [
        'producto_id',
        'concepto',
        'descripcion',
        'medida',
        'cantidad',
        'descuento',
        'vr_unitario',
        'iva',
        'notas',
    ];

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
            'descuento' => 'nullable|numeric|min:0|max:100',
            'vr_unitario' => 'required|numeric|min:0',
            'iva' => 'nullable|numeric|min:0|max:100',
            'notas' => 'nullable|string|max:5000',
        ];

        if ($this->isMethod('PUT')) {
            // PUT allows partial updates — numeric fields are nullable.
            // Validation added in withValidator() requires at least one field present.
            $rules['producto_id'] = 'nullable|integer|exists:productos,id';
            $rules['cantidad'] = 'nullable|numeric|min:0.01';
            $rules['vr_unitario'] = 'nullable|numeric|min:0';
            $rules['descuento'] = 'nullable|numeric|min:0|max:100';
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
            'descripcion.max' => 'La descripción no puede superar los 1000 caracteres.',
            'notas.max' => 'Las notas no pueden superar los 5000 caracteres.',
            'descuento.max' => 'El descuento no puede superar el 100%.',
            'descuento.min' => 'El descuento no puede ser negativo.',
        ];
    }

    /**
     * For PUT: require at least one persistable field to be present.
     * An empty PUT body is a no-op and gets rejected with 422 — spec scenario
     * "PUT with missing required field returns 422" must be triggered by
     * sending literally nothing (or only fields outside the persistable list).
     */
    public function withValidator(Validator $validator): void
    {
        if (! $this->isMethod('PUT')) {
            return;
        }

        $validator->after(function (Validator $v) {
            $present = false;
            foreach (self::PERSISTABLE_FIELDS as $field) {
                if ($this->has($field) && $this->input($field) !== null) {
                    $present = true;
                    break;
                }
            }
            if (! $present) {
                $v->errors()->add(
                    '_request',
                    'Debe enviar al menos un campo para actualizar el detalle.'
                );
            }
        });
    }
}
