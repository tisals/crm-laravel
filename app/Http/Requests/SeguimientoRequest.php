<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SeguimientoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'oportunidad_id' => 'nullable|integer|exists:oportunidad,id',
            'contacto_id' => 'nullable|integer|exists:contacto,id',
            'entidad_id' => 'nullable|integer|exists:entidad,id',
            'tipo' => 'required|in:Llamada,Correo,Reunion,Nota,Otro',
            'fecha' => 'required|date',
            'hora' => 'nullable|date_format:H:i:s',
            'fecha_fin' => 'nullable|date',
            'notas' => 'nullable|string',
            'estado' => 'nullable|in:Pendiente,Completado,Cancelado',
        ];

        if ($this->isMethod('PUT')) {
            $rules['tipo'] = 'nullable|in:Llamada,Correo,Reunion,Nota,Otro';
            $rules['fecha'] = 'nullable|date';
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'tipo.required' => 'El tipo de seguimiento es obligatorio.',
            'tipo.in' => 'El tipo debe ser Llamada, Correo, Reunion, Nota u Otro.',
            'fecha.required' => 'La fecha es obligatoria.',
            'oportunidad_id.exists' => 'La oportunidad seleccionada no existe.',
            'contacto_id.exists' => 'El contacto seleccionado no existe.',
            'entidad_id.exists' => 'La entidad seleccionada no existe.',
        ];
    }
}
