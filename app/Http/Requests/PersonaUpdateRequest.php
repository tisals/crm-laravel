<?php

namespace App\Http\Requests;

use App\Models\Persona;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PR-I 6a.9 — Partial-update validation for `PATCH /api/v1/personas/{id}`.
 *
 * Every rule is `sometimes`: only fields present in the request body are
 * validated/updated. The previous single `PersonaRequest` made `nombres`
 * `required` and would 422 on any PATCH that omitted it — a bug for the
 * spec REQ-PRAPI-004 partial-update contract.
 *
 * Immutable-field guard: `entidad_id` is intentionally locked on this
 * endpoint. Re-parenting a persona across entities crosses a tenant
 * boundary; that flow belongs to a dedicated `reasignar` action (mirroring
 * `ContactoController::reasignar`) and is NOT in PR-I's scope.
 *
 * Uniqueness on update must EXCLUDE the current row, otherwise the user
 * could not "save" their existing email. The closure below handles this.
 */
class PersonaUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $personaId = (int) $this->route('id');
        $entidadId = $this->input('entidad_id');

        return [
            'nombres' => 'sometimes|required|string|max:100',
            'apellidos' => 'sometimes|nullable|string|max:100',

            'email_principal' => [
                'sometimes',
                'nullable',
                'email:rfc',
                'max:150',
                function (string $attribute, mixed $value, \Closure $fail) use ($personaId, $entidadId): void {
                    if ($value === null || $value === '') {
                        return;
                    }
                    $exists = Persona::query()
                        ->where('id', '!=', $personaId)
                        ->where('email_principal', $value)
                        ->when(
                            $entidadId !== null,
                            fn ($q) => $q->where('entidad_id', (int) $entidadId),
                            fn ($q) => $q->whereNull('entidad_id'),
                        )
                        ->exists();
                    if ($exists) {
                        $fail('El correo principal ya está registrado para esta entidad.');
                    }
                },
            ],

            'identificacion_tipo' => [
                'sometimes',
                'nullable',
                'string',
                'max:10',
                Rule::in(['CC', 'CE', 'NIT', 'PAS', 'TI', 'PEP']),
            ],
            'identificacion_numero' => 'sometimes|nullable|string|max:20',
            'telefono_principal' => 'sometimes|nullable|string|max:30',
            'direccion' => 'sometimes|nullable|string|max:200',
            'ciudad' => 'sometimes|nullable|string|max:100',
            'pais' => 'sometimes|nullable|string|max:100',

            'tipo_persona' => ['sometimes', 'nullable', Rule::in(['Natural', 'Juridica'])],

            // Immutable: present in the body to forbid re-parenting on PATCH.
            // Re-parenting must go through a dedicated endpoint (outside
            // PR-I scope).
            'entidad_id' => 'prohibited',
        ];
    }

    public function messages(): array
    {
        return [
            'nombres.required' => 'Los nombres son obligatorios.',
            'email_principal.email' => 'El correo principal no es válido.',
            'entidad_id.prohibited' => 'No se puede re-asignar la entidad de una persona en un PATCH; use el endpoint dedicado.',
            'identificacion_tipo.in' => 'Tipo de identificación inválido.',
            'tipo_persona.in' => 'Tipo de persona inválido (Natural o Juridica).',
        ];
    }
}
