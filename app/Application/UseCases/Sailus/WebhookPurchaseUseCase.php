<?php

namespace App\Application\UseCases\Sailus;

use App\Mail\LicenseActivated;
use App\Models\Contacto;
use App\Models\Entidad;
use App\Models\Servicio;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class WebhookPurchaseUseCase
{
    public function execute(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $customerEmail = $data['customer_email'];
            $customerName = $data['customer_name'];
            $planId = $data['plan_id'] ?? 'base';
            $subscriptionId = $data['subscription_id'] ?? null;
            $billingInterval = $data['billing_interval'] ?? 'monthly';
            $amount = $data['amount'] ?? 0;
            $siteUrl = $data['site_url'] ?? 'https://sailus.dev';

            // 1. Check if contact already exists
            $contacto = Contacto::where('email_contacto', $customerEmail)->first();

            if ($contacto) {
                // Per commit fe99f70: `contacto.entidad_id` was dropped.
                // Look up the contacto's primary entidad via the pivot.
                // If none exists, fall back to the first entity in the table
                // so the legacy purchase flow doesn't break.
                $entidadId = (int) DB::table('entidad_persona')
                    ->where('persona_id', $contacto->persona_id)
                    ->orderByDesc('entidad_id')
                    ->value('entidad_id');
                if (! $entidadId) {
                    $entidadId = (int) DB::table('entidad')->min('id');
                }
                $contactoId = $contacto->id;
            } else {
                // Parse names
                $nameParts = explode(' ', $customerName, 2);
                $nombres = $nameParts[0];
                $apellidos = $nameParts[1] ?? '';

                // Create Entidad (Organization)
                $entidad = Entidad::create([
                    'tipo_persona' => 'Juridica',
                    'tipo_id' => 'NIT',
                    'identificacion' => 'PEND-'.strtoupper(substr(md5(uniqid()), 0, 8)),
                    'nombre' => $customerName.' Org',
                    'nombre_comercial' => $customerName.' Org',
                    'estado' => 'Prospecto',
                ]);

                $entidadId = $entidad->id;

                // Per commit fe99f70: contacto.entidad_id was dropped.
                // We create the persona, link it to the entidad via the
                // pivot, and stamp the contacto with the persona_id.
                $personaId = DB::table('personas')->insertGetId([
                    'nombres' => $nombres,
                    'apellidos' => $apellidos,
                    'email_principal' => $customerEmail,
                    'tipo_persona' => 'Natural',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // Create Contacto
                $newContacto = Contacto::create([
                    'persona_id' => $personaId,
                    'nombres' => $nombres,
                    'apellidos' => $apellidos,
                    'email_contacto' => $customerEmail,
                    'rol' => 'Contacto WP',
                    'estado' => 'Activo',
                ]);

                DB::table('entidad_persona')->insert([
                    'persona_id' => $personaId,
                    'entidad_id' => $entidadId,
                    'categoria' => 'asignacion',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $contactoId = $newContacto->id;
            }

            // 2. Map plan to tier and name
            $tier = ($planId === 'pro' || $planId === 'enterprise') ? 'premium' : 'base';
            $serviceName = ($planId === 'pro') ? 'Plan Pro' : (($planId === 'starter') ? 'Starter Plan' : 'Plan '.ucfirst($planId));

            // 3. Calculate expires at (fecha_fin)
            $expiresAt = now();
            if ($billingInterval === 'yearly') {
                $expiresAt = $expiresAt->addYear();
            } else {
                $expiresAt = $expiresAt->addMonth();
            }

            // 4. Generate activation token
            $activationToken = 'SAILUS-'.(string) Str::uuid();

            // 5. Create Servicio
            $servicio = Servicio::create([
                'entidad_id' => $entidadId,
                'nombre' => $serviceName,
                'plan_id' => $planId,
                'subscription_id' => $subscriptionId,
                'tier' => $tier,
                'vr_servicio' => $amount,
                'fecha_inicio' => now(),
                'fecha_fin' => $expiresAt,
                'activation_token' => $activationToken,
                'estado' => 'Activo',
                'metadata' => [],
            ]);

            // 6. Send the activation email
            $emailData = [
                'customer_name' => $customerName,
                'activation_token' => $activationToken,
                'plan_name' => $serviceName,
                'expires_at' => $expiresAt->toDateString(),
                'site_url' => $siteUrl,
            ];

            Mail::to($customerEmail)->send(new LicenseActivated($emailData));

            return [
                'success' => true,
                'org_id' => $entidadId,
                'contact_id' => $contactoId,
                'service_id' => $servicio->id,
                'activation_token' => $activationToken,
                'email_sent' => true,
            ];
        });
    }
}
