<?php

namespace App\Http\Requests;

use App\Models\Persona;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PR-I 6a.8 — Full validation for `POST /api/v1/personas`.
 *
 * The previous single `PersonaRequest` class is split because:
 *  - POST is full-create (all-or-nothing required fields + uniqueness check)
 *  - PATCH is partial (every rule `sometimes`, no uniqueness on this layer)
 *
 * Validation specifics (REQ-PRAPI-001, REQ-PRAPI-002, OI-5):
 *  - `nombres` required (Natural persons must carry a name)
 *  - `apellidos` nullable (Juridica personas have no surname)
 *  - `email_principal` optional, well-formed, app-level unique per `entidad_id`
 *  - `tipo_persona` enum ['Natural','Juridica'] defaults to 'Natural'
 *  - `entidad_id` required when `tipo_persona === 'Juridica'`
 *  - `identificacion_tipo` limited to the RQ-7 enum whitelist
 */
class PersonaStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tipoPersona = $this->input('tipo_persona', 'Natural');
        $entidadId = $this->input('entidad_id');

        return [
            'nombres' => 'required|string|max:100',
            'apellidos' => 'nullable|string|max:100',

            // RQ-7: app-level uniqueness scoped to entidad. NULL emails are
            // not subject to the rule (multiple personas may share a NULL
            // email column).
            'email_principal' => [
                'nullable',
                'email:rfc',
                'max:150',
                function (string $attribute, mixed $value, \Closure $fail) use ($entidadId): void {
                    if ($value === null || $value === '') {
                        return;
                    }
                    $exists = Persona::query()
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
                'nullable',
                'string',
                'max:10',
                Rule::in(['CC', 'CE', 'NIT', 'PAS', 'TI', 'PEP']),
            ],
            'identificacion_numero' => 'nullable|string|max:20',
            'telefono_principal' => 'nullable|string|max:30',
            'direccion' => 'nullable|string|max:200',
            'ciudad' => 'nullable|string|max:100',
            'pais' => 'nullable|string|max:100',

            // PR-A: tipo_persona defaults to 'Natural' on the model, but
            // we enforce the enum here so a typo doesn't reach the DB.
            'tipo_persona' => ['nullable', Rule::in(['Natural', 'Juridica'])],

            // PR-A: entidad_id is optional for Natural personas
            // (involution happens upstream in a later PR per AD-8 / R-08).
            // Juridica personas MUST belong to an entity (else the entity
            // itself wouldn't exist as Juridica).
            'entidad_id' => [
                'nullable',
                'integer',
                'exists:entidad,id',
                Rule::requiredIf(fn () => $tipoPersona === 'Juridica'),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'nombres.required' => 'Los nombres son obligatorios.',
            'email_principal.email' => 'El correo principal no es válido.',
            'entidad_id.required' => 'La entidad es obligatoria para personas jurídicas.',
            'identificacion_tipo.in' => 'Tipo de identificación inválido.',
            'tipo_persona.in' => 'Tipo de persona inválido (Natural o Juridica).',
        ];
    }
}
