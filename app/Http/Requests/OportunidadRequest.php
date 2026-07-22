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
            'pipeline_id' => 'nullable|integer|exists:pipelines,id',
            'pipeline_etapa_id' => 'nullable|integer|exists:pipeline_etapas,id',
            'fecha' => 'required|date',
            // codigo is optional on POST (server auto-generates if missing) and
            // optional on PUT (unique except self when present).
            'codigo' => [
                'nullable',
                'string',
                'max:100',
                \Illuminate\Validation\Rule::unique('oportunidad', 'codigo')
                    ->ignore($oportunidadId)
                    ->whereNull('deleted_at'),
            ],
            'fuente_canal' => 'nullable|string|max:100',
            'estado' => 'nullable|string|max:100', // Relaxed state validation to allow custom CRM states
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
        ];
    }
}
