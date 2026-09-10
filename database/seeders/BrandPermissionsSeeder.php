<?php

namespace Database\Seeders;

use App\Models\Entidad;
use App\Models\Rol;
use App\Models\Usuario;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class BrandPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Crear/actualizar entidades marca por NIT (clave de negocio única).
        // Usamos 'identificacion' en lugar de 'id' para que el seeder sea idempotente
        // y no choque con el UNIQUE de identificacion cuando el RealDataSeeder
        // ya pobló la entidad con otro id desde el CSV.
        //
        // Commit 4 dropped `entidad.dominio` (replaced by presencia_online)
        // and Commit 8 dropped `entidad.estado` (replaced by
        // entidad_relacion with tipo_relacion='propia'). We stamp both
        // canonical rows below.
        $tecnoinnsoft = Entidad::updateOrCreate(
            ['identificacion' => '900935453'],
            [
                'tipo_persona' => 'Juridica',
                'tipo_id' => 'NIT',
                'nombre' => 'Tecnoinnsoft SAS BIC',
                'nombre_comercial' => 'Tecnoinnsoft',
            ]
        );

        $deseguridad = Entidad::updateOrCreate(
            ['identificacion' => '900935453-0'],
            [
                'tipo_persona' => 'Juridica',
                'tipo_id' => 'NIT',
                'nombre' => 'Deseguridad.net',
                'nombre_comercial' => 'Deseguridad.net',
            ]
        );

        // Stamp the canonical "Propia" brand state on entidad_relacion.
        // Idempotent: skip the insert if a pivot row already exists for
        // this brand (matches SailusAgentSeeder semantics — re-running
        // the seeder must not touch existing pivots).
        $this->stampBrandPropia($tecnoinnsoft);
        $this->stampBrandPropia($deseguridad);

        // Stamp the canonical dominio on presencia_online (tipo='web').
        // Idempotent: skip the insert if a web-presence row already exists.
        $this->stampBrandDominio($tecnoinnsoft, 'tecnoinnsoft.com');
        $this->stampBrandDominio($deseguridad, 'deseguridad.net');

        // Resolving roles dynamically to handle auto-increment changes across test transactions
        $superAdminRole = Rol::where('nombre', 'SuperAdmin')->first() ?? Rol::create(['nombre' => 'SuperAdmin', 'estado' => 'Activo']);
        $comercialRole = Rol::where('nombre', 'Comercial')->first() ?? Rol::create(['nombre' => 'Comercial', 'estado' => 'Activo']);

        // 2. Definir lista de usuarios a crear
        $usuarios = [
            [
                'email' => 'admin@tecnoinnsoft.dev',
                'nombre' => 'Admin Principal',
                'password_hash' => bcrypt('password'),
                'rol_id' => $superAdminRole->id,
                'estado' => 'Activo',
            ],
            [
                'email' => 'gestorcomercial.tis@gmail.com',
                'nombre' => 'Lorena Bernal',
                'password_hash' => bcrypt('password'),
                'rol_id' => $comercialRole->id, // Ventas (Comercial)
                'estado' => 'Activo',
            ],
            [
                'email' => 'direccion.tis@gmail.com',
                'nombre' => 'Jaime Novoa',
                'password_hash' => bcrypt('password'),
                'rol_id' => $comercialRole->id, // Ventas (Comercial)
                'estado' => 'Activo',
            ],
            [
                'email' => 'innovacionydesarrollo.tis@gmail.com',
                'nombre' => 'Alejandro Leguizamo',
                'password_hash' => bcrypt('password'),
                'rol_id' => $superAdminRole->id, // Admin (Super Admin)
                'estado' => 'Activo',
            ],
            [
                'email' => 'servicioalcliente.tis@gmail.com',
                'nombre' => 'Patricia Moreno',
                'password_hash' => bcrypt('password'),
                'rol_id' => $comercialRole->id, // Ventas (Comercial)
                'estado' => 'Activo',
            ],
        ];

        // 3. Crear y vincular a cada usuario con las marcas.
        // Per commit fe99f70 the user ↔ entidad binding is now via the
        // `entidad_persona` pivot (keyed on persona_id, NOT usuario_id),
        // and `Usuario::entidades()` is a hasManyThrough — no
        // syncWithoutDetaching. Insert the pivot rows directly.
        $now = now();
        foreach ($usuarios as $u) {
            $user = Usuario::firstOrCreate(
                ['email' => $u['email']],
                [
                    'nombre' => $u['nombre'],
                    'password_hash' => $u['password_hash'],
                    'rol_id' => $u['rol_id'],
                    'estado' => $u['estado'],
                ]
            );

            // persona_id is set by the Usuario::creating hook (auto-
            // creates a persona + primary emails row when missing).
            foreach ([$tecnoinnsoft->id, $deseguridad->id] as $entidadId) {
                $exists = DB::table('entidad_persona')
                    ->where('persona_id', $user->persona_id)
                    ->where('entidad_id', $entidadId)
                    ->exists();
                if (! $exists) {
                    DB::table('entidad_persona')->insert([
                        'persona_id' => $user->persona_id,
                        'entidad_id' => $entidadId,
                        'categoria' => 'asignacion',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }

            if (isset($this->command)) {
                $tecnoinnsoftDominio = $this->lookupDominio($tecnoinnsoft->id);
                $deseguridadDominio = $this->lookupDominio($deseguridad->id);
                $this->command->info("✅ Usuario ID {$user->id} ({$user->nombre}) vinculado a: {$tecnoinnsoftDominio}, {$deseguridadDominio}");
            }
        }

        // Verify brand entities are correctly set as Propia.
        // Commit 8 replaced `entidad.estado` with the
        // `entidad_relacion` pivot; we read via the same lookup the
        // seeders use elsewhere.
        if (isset($this->command)) {
            $this->command->info('');
            $this->command->info('📋 Verificación de entidades Propia:');
            $propias = DB::table('entidad')
                ->whereExists(function ($sub) {
                    $sub->select(DB::raw(1))
                        ->from('entidad_relacion')
                        ->whereColumn('entidad_relacion.entidad_id', 'entidad.id')
                        ->where('entidad_relacion.tipo_relacion', 'propia')
                        ->whereNull('entidad_relacion.effective_to');
                })
                ->get(['id', 'nombre', 'identificacion']);
            foreach ($propias as $p) {
                $this->command->info("  → ID {$p->id}: {$p->nombre} (NIT: {$p->identificacion}) - Propia");
            }
        }
    }

    /**
     * Stamp the canonical "Propia" brand state on entidad_relacion.
     * Idempotent: skip if a pivot row already exists for this entidad.
     */
    private function stampBrandPropia(Entidad $entidad): void
    {
        $exists = DB::table('entidad_relacion')
            ->where('entidad_id', $entidad->id)
            ->where('tipo_relacion', 'propia')
            ->exists();
        if ($exists) {
            return;
        }

        $now = now();
        DB::table('entidad_relacion')->insert([
            'entidad_id' => $entidad->id,
            'tipo_relacion' => 'propia',
            'effective_from' => $now->toDateString(),
            'effective_to' => null,
            'frecuencia' => 'unica',
            'recurrencia_cada_meses' => null,
            'vigencia_meses' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Stamp the canonical dominio on presencia_online (tipo='web').
     * Idempotent: skip if a web-presence row already exists.
     */
    private function stampBrandDominio(Entidad $entidad, string $dominio): void
    {
        $exists = DB::table('presencia_online')
            ->where('entidad_id', $entidad->id)
            ->where('tipo', 'web')
            ->exists();
        if ($exists) {
            return;
        }

        $now = now();
        DB::table('presencia_online')->insert([
            'entidad_id' => $entidad->id,
            'tipo' => 'web',
            'plataforma' => 'otro',
            'url' => $dominio,
            'es_principal' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Look up the canonical dominio for an entidad via presencia_online.
     * Returns the principal web URL or a placeholder if none exists.
     */
    private function lookupDominio(int $entidadId): string
    {
        $url = DB::table('presencia_online')
            ->where('entidad_id', $entidadId)
            ->where('tipo', 'web')
            ->where('es_principal', 1)
            ->value('url');

        return $url ?? '(no dominio)';
    }
}
