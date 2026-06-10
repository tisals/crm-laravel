<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BulkMoveOportunidadesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'oportunidad_ids' => ['required', 'array', 'min:1'],
            'oportunidad_ids.*' => ['integer'],
            'target_pipeline_etapa_id' => ['required', 'integer', 'exists:pipeline_etapas,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'oportunidad_ids.required' => 'Debe proporcionar al menos una oportunidad.',
            'oportunidad_ids.min' => 'Debe proporcionar al menos una oportunidad.',
            'oportunidad_ids.*.integer' => 'Cada ID de oportunidad debe ser un número entero.',
            'target_pipeline_etapa_id.required' => 'Debe especificar la etapa de destino.',
            'target_pipeline_etapa_id.exists' => 'La etapa especificada no existe.',
        ];
    }
}
