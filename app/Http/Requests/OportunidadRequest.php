<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OportunidadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $oportunidadId = $this->route('id');

        $rules = [
            'entidad_id' => 'required|integer|exists:entidad,id',
            'contacto_id' => 'nullable|integer|exists:contacto,id',
            'fecha' => 'required|date',
            'fuente_canal' => 'nullable|string|max:100',
            'estado' => 'nullable|in:Borrador,Enviada,Aceptada,Rechazada,Ganada,Perdida',
            'observaciones' => 'nullable|string',
            'aclaraciones' => 'nullable|string',
            'validez_oferta' => 'nullable|integer|min:1',
            'tiempo_entrega' => 'nullable|string|max:255',
            'forma_pago' => 'nullable|string|max:255',
            'garantia' => 'nullable|string|max:255',
        ];

        // On PUT, only require what's sent
        if ($this->isMethod('PUT')) {
            $rules['entidad_id'] = 'nullable|integer|exists:entidad,id';
            $rules['fecha'] = 'nullable|date';
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'entidad_id.required' => 'La entidad es obligatoria.',
            'entidad_id.exists' => 'La entidad seleccionada no existe.',
            'contacto_id.exists' => 'El contacto seleccionado no existe.',
            'fecha.required' => 'La fecha es obligatoria.',
            'estado.in' => 'El estado debe ser Borrador, Enviada, Aceptada, Rechazada, Ganada o Perdida.',
        ];
    }
}
