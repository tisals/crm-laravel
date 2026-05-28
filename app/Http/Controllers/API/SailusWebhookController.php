<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Controllers\API\Concerns\ApiResponse;
use App\Http\Requests\WebhookRegistrationRequest;
use App\Models\Contacto;
use App\Models\Entidad;
use App\Models\Producto;
use App\Models\Servicio;
use Illuminate\Support\Facades\DB;

use App\Application\UseCases\Sailus\WebhookPurchaseUseCase;
use App\Application\UseCases\Sailus\ValidateLicenseUseCase;
use Illuminate\Http\Request;

class SailusWebhookController extends Controller
{
    use ApiResponse;

    public function __construct(
        private WebhookPurchaseUseCase $purchaseUseCase,
        private ValidateLicenseUseCase $validateLicenseUseCase,
    ) {}

    public function purchase(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'source' => 'required|string',
            'order_id' => 'required|string',
            'customer_email' => 'required|email',
            'customer_name' => 'required|string',
            'plan_id' => 'required|string',
            'amount' => 'required|numeric',
            'currency' => 'required|string',
            'subscription_id' => 'required|string',
            'billing_interval' => 'required|string',
            'site_url' => 'nullable|string',
        ]);

        $result = $this->purchaseUseCase->execute($data);

        return response()->json($result, 200);
    }

    public function validateLicense(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'username' => 'required|string',
            'token' => 'required|string',
            'site_url' => 'nullable|string',
            'plugin_version' => 'nullable|string',
        ]);

        $result = $this->validateLicenseUseCase->execute(
            $data['username'],
            $data['token'],
            $data['site_url'] ?? null,
            $data['plugin_version'] ?? null
        );

        if (!$result) {
            return $this->errorResponse('Licencia inválida o usuario no autorizado.', 401);
        }

        return response()->json($result, 200);
    }

    public function registration(WebhookRegistrationRequest $request): \Illuminate\Http\JsonResponse
    {
        // Check duplicate email
        $existing = Contacto::where('email_contacto', $request->contact_email)->first();
        if ($existing) {
            return $this->errorResponse('El email del contacto ya está registrado', 409);
        }

        $result = DB::transaction(function () use ($request) {
            // Parse contact name
            $nameParts = explode(' ', $request->contact_name, 2);
            $nombres = $nameParts[0];
            $apellidos = $nameParts[1] ?? '';

            // 1. Create Entidad (organization)
            $entidad = Entidad::create([
                'tipo_persona' => 'Juridica',
                'tipo_id' => 'NIT',
                'identificacion' => 'PEND-' . strtoupper(substr(md5(uniqid()), 0, 8)),
                'nombre' => $request->organization_name,
                'nombre_comercial' => $request->organization_name,
                'estado' => 'Prospecto',
            ]);

            // 2. Create Contacto
            $contacto = Contacto::create([
                'entidad_id' => $entidad->id,
                'nombres' => $nombres,
                'apellidos' => $apellidos,
                'email_contacto' => $request->contact_email,
                'rol' => 'Contacto WP',
                'etapa' => 'Nuevo',
                'estado' => 'Activo',
                'diagnostico_data' => $request->diagnostico_data,
                'fuente' => $request->source ?? 'wordpress',
            ]);

            // 3. Lookup plan
            $planId = null;
            if ($request->plan_type) {
                $plan = Producto::where('tipo', 'suscripcion')
                    ->where('nombre', 'like', "%{$request->plan_type}%")
                    ->first();
                $planId = $plan?->id;
            }

            // 4. Create Servicio
            $servicio = Servicio::create([
                'entidad_id' => $entidad->id,
                'nombre' => $request->service_name ?? 'Servicio - ' . $request->organization_name,
                'vr_servicio' => 0,
                'estado' => 'Nuevo',
            ]);

            return [
                'org_id' => $entidad->id,
                'contact_id' => $contacto->id,
                'plan_id' => $planId,
                'status' => 'active',
                'pdf_url' => null,
            ];
        });

        return response()->json([
            'success' => true,
            ...$result,
        ], 201);
    }
}
