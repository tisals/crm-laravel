<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OrdenServicioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'detalle_srv_id' => 'nullable|integer|exists:detalle_servicios,id',
            'colaborador_id' => 'nullable|integer|exists:colaboradores,id',
            'proveedor_id' => 'nullable|integer|exists:proveedores,id',
            'contacto_id' => 'nullable|integer|exists:contacto,id',
            'descripcion' => 'nullable|string',
            'objetivo' => 'nullable|string',
            'ubicacion' => 'nullable|string|max:255',
            'fecha_desde' => 'nullable|date',
            'fecha_hasta' => 'nullable|date|after_or_equal:fecha_desde',
            'valor' => 'nullable|numeric|min:0',
            'estado' => 'nullable|in:Pendiente,EnProgreso,Completado,Cancelado',
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            if ($this->isMethod('POST')) {
                $colaboradorId = $this->input('colaborador_id');
                $proveedorId = $this->input('proveedor_id');

                if (empty($colaboradorId) && empty($proveedorId)) {
                    $validator->errors()->add('colaborador_id', 'Debe especificar al menos un colaborador o proveedor.');
                    $validator->errors()->add('proveedor_id', 'Debe especificar al menos un colaborador o proveedor.');
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            'detalle_srv_id.exists' => 'El detalle de servicio seleccionado no existe.',
            'colaborador_id.exists' => 'El colaborador seleccionado no existe.',
            'proveedor_id.exists' => 'El proveedor seleccionado no existe.',
            'contacto_id.exists' => 'El contacto seleccionado no existe.',
            'fecha_hasta.after_or_equal' => 'La fecha fin debe ser posterior o igual a la fecha inicio.',
            'estado.in' => 'El estado debe ser Pendiente, EnProgreso, Completado o Cancelado.',
        ];
    }
}
