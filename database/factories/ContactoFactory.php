<?php

namespace Database\Factories;

use App\Models\Contacto;
use App\Models\Persona;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

class ContactoFactory extends Factory
{
    protected $model = Contacto::class;

    /**
     * Static stash for the legacy `entidad_id` caller intent. Set by
     * `forEntidad()` and consumed in `afterMaking()`. We use a static
     * (instead of a model transient attr) because `entidad_id` is not
     * $fillable and the value would be silently dropped before our
     * `afterMaking` hook sees the model.
     */
    public static ?int $pendingEntidadId = null;

    public function definition(): array
    {
        return [
            // Per commit fe99f70: `contacto.entidad_id` was dropped. The
            // factory seeds the legacy field as `null` (now a no-op
            // since the column doesn't exist); the entidad binding lives
            // on `entidad_persona` keyed on the contacto's persona_id.
            'nombres' => fake()->firstName(),
            'apellidos' => fake()->lastName(),
            'email_contacto' => fake()->unique()->safeEmail(),
            'estado' => 'Activo',
        ];
    }

    /**
     * Configure the model factory.
     *
     * Legacy `factory()->create(['entidad_id' => $x])` callers used to
     * rely on the dropped `contacto.entidad_id` FK to carry the binding.
     * The factory runs in `Model::unguarded()` mode, which means the
     * caller's `entidad_id` DOES land on the model (Eloquent doesn't
     * re-filter by `$fillable` in `save()`). But the INSERT will fail
     * because the column doesn't exist anymore (commit fe99f70).
     *
     * Strategy: in `afterMaking` (which fires after `makeInstance` and
     * BEFORE `store()`), intercept any `entidad_id` value on the model
     * and:
     *
     *   1. Copy it onto a transient attribute (`__legacyEntidadId`)
     *      so it survives the round-trip to `afterCreating`.
     *   2. Drop the field from the model's attributes so the INSERT
     *      doesn't reference the missing column.
     *
     * Then `afterCreating` re-applies the binding on `entidad_persona`.
     *
     * Two caller surfaces are accepted:
     *   - legacy `factory()->create(['entidad_id' => $x])` — captured
     *     via the `afterMaking` hook.
     *   - explicit `factory()->forEntidad($x)->create()` — captured via
     *     the static stash (recommended for new tests).
     */
    public function configure(): static
    {
        return $this
            ->afterMaking(function (Contacto $contacto) {
                // 1. Pick up the explicit `forEntidad($x)` intent first.
                $explicit = self::$pendingEntidadId;
                self::$pendingEntidadId = null;

                // 2. Pick up the legacy `entidad_id` field that landed
                //    on the model via `unguarded()`. We use the raw
                //    attributes array because `__get` would try to
                //    resolve it via the `entidades()` relation.
                $attrs = $contacto->getAttributes();
                $legacy = $attrs['entidad_id'] ?? null;

                $effective = $explicit ?? $legacy;
                if ($effective === null) {
                    return;
                }

                // Stash on the model so `afterCreating` can read it.
                $contacto->__legacyEntidadId = (int) $effective;

                // Strip the legacy field so the INSERT doesn't try to
                // write a column that no longer exists.
                unset($contacto->entidad_id);
            })
            ->afterCreating(function (Contacto $contacto) {
                $entidadId = $contacto->__legacyEntidadId ?? null;
                if ($entidadId === null) {
                    return;
                }

                if (! $contacto->persona_id) {
                    // Commit 4 dropped `personas.email_principal`. Create
                    // the persona row without it; the email lives in
                    // the shared `emails` table, inserted below.
                    $persona = Persona::factory()->create();
                    $contacto->update(['persona_id' => $persona->id]);

                    if (! empty($contacto->email_contacto)) {
                        DB::table('emails')->insert([
                            'persona_id' => $persona->id,
                            'email' => $contacto->email_contacto,
                            'tipo' => 'personal',
                            'es_principal' => true,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }

                DB::table('entidad_persona')->insertOrIgnore([
                    'persona_id' => (int) $contacto->persona_id,
                    'entidad_id' => (int) $entidadId,
                    'categoria' => 'asignacion',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }

    /**
     * State helper: bind the contacto to an entidad via the
     * `entidad_persona` pivot once the contacto exists. This is the
     * recommended replacement for the dropped `entidad_id` field.
     *
     * Usage:
     *   Contacto::factory()->forEntidad($entidad->id)->create();
     */
    public function forEntidad(int $entidadId): static
    {
        self::$pendingEntidadId = $entidadId;

        return $this;
    }
}
