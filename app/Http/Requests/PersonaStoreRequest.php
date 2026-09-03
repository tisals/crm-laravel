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
 * Validation specifics (REQ-PRAPI-001, REQ-PRAPI-002, REQ-PNCE-001..005, OI-5):
 *  - `nombres` required (Natural persons must carry a name)
 *  - `apellidos` nullable (Juridica personas have no surname)
 *  - `email_principal` optional, well-formed, app-level unique per `entidad_id`
 *  - `tipo_persona` accepts case-insensitive `'natural'` / `'juridica'`; the
 *    DB column is ENUM('Natural','Juridica') so the canonical form is the
 *    stored value (RQ-6). When the client omits it, defaults to `'natural'`
 *    so the R-iter4.R08 inversion kicks in (REQ-PNCE-005).
 *  - `entidad_id` required when `tipo_persona === 'juridica'` (case-insensitive).
 *    For natural personas, `entidad_id` is OPTIONAL — the use case will
 *    auto-create one via the R-iter4.R08 inversion (REQ-PNCE-001).
 *  - `identificacion_tipo` limited to the RQ-7 enum whitelist
 */
class PersonaStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * PR-K: lower-case the `tipo_persona` BEFORE the validation rules run so
     * the validator sees `'natural'` / `'juridica'` regardless of how the
     * client capitalised it. The downstream use case then reads
     * `validated()['tipo_persona']` and applies the canonical mapping
     * (`ucfirst`) before persisting into the ENUM column.
     *
     * Without this normalization the validator would have to whitelist every
     * case variant (`['Natural','Juridica','natural','juridica',...]`), which
     * is brittle and obscures the actual contract (the spec calls for
     * case-insensitive input, canonical storage).
     */
    protected function prepareForValidation(): void
    {
        $tipo = $this->input('tipo_persona');

        if ($tipo !== null && $tipo !== '') {
            $this->merge([
                'tipo_persona' => strtolower((string) $tipo),
            ]);
        }
    }

    public function rules(): array
    {
        // After `prepareForValidation()`, `tipo_persona` is already lowercase
        // when present. The validator only needs to whitelist the lowercase
        // canonical forms; missing input means the use case defaults to
        // 'natural' (REQ-PNCE-005).
        $tipoPersona = $this->input('tipo_persona', 'natural');
        $entidadId = $this->input('entidad_id');

        return [
            'nombres' => 'required|string|max:100',
            'apellidos' => 'nullable|string|max:100',

            // RQ-7: app-level uniqueness scoped to entidad. The
            // check now queries the shared `emails` table (Commit 4
            // dropped `personas.email_principal`). NULL emails are not
            // subject to the rule (multiple personas may share a NULL
            // email column).
            'email_principal' => [
                'nullable',
                'email:rfc',
                'max:150',
                function (string $attribute, mixed $value, \Closure $fail) use ($entidadId): void {
                    if ($value === null || $value === '') {
                        return;
                    }
                    $exists = \DB::table('emails')
                        ->where('email', $value)
                        ->when(
                            $entidadId !== null,
                            fn ($q) => $q->whereExists(
                                fn ($sub) => $sub
                                    ->from('entidad_persona')
                                    ->whereColumn('entidad_persona.persona_id', 'emails.persona_id')
                                    ->where('entidad_persona.entidad_id', (int) $entidadId)
                            ),
                            fn ($q) => $q->whereNotExists(
                                fn ($sub) => $sub
                                    ->from('entidad_persona')
                                    ->whereColumn('entidad_persona.persona_id', 'emails.persona_id')
                            )
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
            // Commit 4 dropped `telefono_principal`, `direccion`,
            // `ciudad`, `pais` from `personas`. They now live in the
            // shared `telefonos` / `direcciones` tables, written through
            // their own REST verbs (`POST /api/v1/telefonos`, etc.). The
            // request still ACCEPTS these fields for backwards
            // compatibility and mirrors them into the new tables in
            // the use case; once the API migrates, drop the rules.
            'telefono_principal' => 'nullable|string|max:30',
            'direccion' => 'nullable|string|max:200',
            'ciudad' => 'nullable|string|max:100',
            'pais' => 'nullable|string|max:100',

            // PR-K (REQ-PNCE-005): the validator accepts ONLY lowercase
            // forms because `prepareForValidation()` already normalized the
            // input. The use case is responsible for the canonical ENUM
            // mapping before INSERT.
            'tipo_persona' => [
                'nullable',
                Rule::in(['natural', 'juridica']),
            ],

            // PR-K: `entidad_id` is REQUIRED for juridica personas (the
            // inversion doesn't apply to companies) and OPTIONAL for
            // natural personas (the use case will auto-create one).
            'entidad_id' => [
                'nullable',
                'integer',
                'exists:entidad,id',
                Rule::requiredIf(fn () => $tipoPersona === 'juridica'),
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
