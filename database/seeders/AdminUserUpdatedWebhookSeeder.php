<?php

namespace Database\Seeders;

use App\Models\Usuario;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dispatch janus-apps-canonicalization webhook E7 (`user.updated`)
 * for the admin user so mercurio's `mercurio_users_snapshot` populates
 * immediately after seed — no need to wait for the 60s periodic loop.
 *
 * Per spec:
 *   - 01-seed-spec.md L11-14 (admin user.updated payload intent)
 *   - 04-minerva-mercurio.md L342-358 (Shape 1 webhook payload)
 *
 * Idempotent: drops any prior `user.updated` rows for the same admin
 * before inserting, so re-running the seed does not accumulate events.
 * The outbox processor (ProcessWebhookOutbox) handles actual delivery.
 */
class AdminUserUpdatedWebhookSeeder extends Seeder
{
    public function run(): void
    {
        // Guard: outbox table may not exist on first deploy (migration
        // runs after seed in fresh-install scenarios). Skip gracefully.
        if (! Schema::hasTable('webhook_outbox')) {
            return;
        }

        // Resolve admin by canonical email + SuperAdmin rol. The spec
        // hardcodes usuario_id = 5 but lookup by email is more robust
        // against reordering of prior seeders (RealDataSeeder, etc.).
        $admin = Usuario::where('email', 'admin@tecnoinnsoft.dev')
            ->where('rol_id', 1)
            ->first();

        if (! $admin) {
            $this->command?->warn(
                'AdminUserUpdatedWebhookSeeder: admin@tecnoinnsoft.dev not found, skipping E7 dispatch.'
            );
            return;
        }

        // Build the bundle that mercurio will receive.
        $apps = DB::table('apps')
            ->whereNull('deleted_at')
            ->orderBy('slug')
            ->get(['id', 'slug', 'nombre'])
            ->map(fn ($a) => [
                'id' => (int) $a->id,
                'slug' => $a->slug,
                'nombre' => $a->nombre,
            ])
            ->all();

        // Admin receives the wildcard '*' permission (per spec L54-56,
        // L129-133). Granular permissions are T10.2 carry-forward.
        $payload = [
            'user_id' => (int) $admin->id,
            'email' => $admin->email,
            'name' => $admin->nombre,
            'estado' => $admin->estado,
            'rol_id' => (int) $admin->rol_id,
            'apps' => $apps,
            'permissions' => ['*'],
        ];

        DB::transaction(function () use ($admin, $payload) {
            // Idempotency: drop any prior user.updated rows for this admin.
            DB::table('webhook_outbox')
                ->where('event_type', 'user.updated')
                ->where('payload_json', 'like', '%"user_id":'.((int) $admin->id).'%')
                ->delete();

            DB::table('webhook_outbox')->insert([
                'event_type' => 'user.updated',
                'payload_json' => json_encode($payload),
                'attempts' => 0,
                'status' => 'pending',
                'next_retry_at' => null,
                'last_error' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->command?->info(
            "AdminUserUpdatedWebhookSeeder: E7 dispatched for admin id={$admin->id} (".count($apps).' apps).'
        );
    }
}