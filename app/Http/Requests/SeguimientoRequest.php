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
            // PR-H (Phase 5b - REQ-SEG-004): swap `contacto_id` rule for
            // `persona_id`. The FK swap (PR-G migration) dropped
            // `seguimiento.contacto_id`; the validator now accepts only
            // the post-PR-G canonical key.
            'persona_id' => 'nullable|integer|exists:personas,id',
            'entidad_id' => 'nullable|integer|exists:entidad,id',
            'tipo' => 'required|in:Llamada,Correo,Reunion,Nota,Otro',
            'fecha' => 'required|date',
            'hora' => ['nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/'],
            // fecha_fin must be on or after fecha when both are provided.
            'fecha_fin' => 'nullable|date|after_or_equal:fecha',
            'notas' => 'nullable|string',
            'estado' => 'nullable|in:Pendiente,Completado,Cancelado',
            // PR-H: explicitly reject the legacy `contacto_id` field so
            // clients that have not migrated to `persona_id` get a loud
            // 422 instead of a silent drop. Use Laravel's `prohibited`
            // rule (the field must NOT be present in the input).
            'contacto_id' => 'prohibited',
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
            'fecha_fin.after_or_equal' => 'La fecha de fin debe ser igual o posterior a la fecha de inicio.',
            'oportunidad_id.exists' => 'La oportunidad seleccionada no existe.',
            'persona_id.exists' => 'La persona seleccionada no existe.',
            'entidad_id.exists' => 'La entidad seleccionada no existe.',
            'contacto_id.prohibited' => 'El campo contacto_id ya no se acepta. Use persona_id.',
        ];
    }
}
