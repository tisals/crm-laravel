<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ServicioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $servicioId = $this->route('id');

        $rules = [
            'oportunidad_id' => 'nullable|integer|exists:oportunidad,id|unique:servicios,oportunidad_id',
            'entidad_id' => 'required|integer|exists:entidad,id',
            'nombre' => 'required|string|max:255',
            'vr_servicio' => 'nullable|numeric|min:0',
            'fecha_inicio' => 'nullable|date',
            'fecha_fin' => 'nullable|date|after_or_equal:fecha_inicio',
            'prestador_id' => 'nullable|integer|exists:proveedores,id',
            'estado' => 'nullable|in:Nuevo,EnEjecucion,Finalizado,Cancelado',
        ];

        if ($this->isMethod('PUT')) {
            $rules['oportunidad_id'] = 'nullable|integer|exists:oportunidad,id|unique:servicios,oportunidad_id,' . $servicioId;
            $rules['entidad_id'] = 'nullable|integer|exists:entidad,id';
            $rules['nombre'] = 'nullable|string|max:255';
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'entidad_id.required' => 'La entidad es obligatoria.',
            'entidad_id.exists' => 'La entidad seleccionada no existe.',
            'nombre.required' => 'El nombre del servicio es obligatorio.',
            'oportunidad_id.unique' => 'Esta oportunidad ya tiene un servicio asociado.',
            'fecha_fin.after_or_equal' => 'La fecha fin debe ser posterior o igual a la fecha inicio.',
            'estado.in' => 'El estado debe ser Nuevo, EnEjecucion, Finalizado o Cancelado.',
        ];
    }
}
