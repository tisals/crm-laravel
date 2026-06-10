<?php

namespace Modules\CRM\Http\Controllers;

use App\Application\UseCases\Sailus\ValidateLicenseUseCase;
use App\Application\UseCases\Sailus\WebhookPurchaseUseCase;
use App\Http\Controllers\API\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\WebhookRegistrationRequest;
use App\Models\Producto;
use App\Models\Servicio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\CRM\Actions\IngestLeadAction;
use Modules\CRM\Models\Contacto;

class SailusWebhookController extends Controller
{
    use ApiResponse;

    public function __construct(
        private WebhookPurchaseUseCase $purchaseUseCase,
        private ValidateLicenseUseCase $validateLicenseUseCase,
        private IngestLeadAction $ingestLeadAction,
    ) {}

    public function purchase(Request $request): JsonResponse
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

    public function validateLicense(Request $request): JsonResponse
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

        if (! $result) {
            return $this->errorResponse('Licencia inválida o usuario no autorizado.', 401);
        }

        return response()->json($result, 200);
    }

    public function registration(WebhookRegistrationRequest $request): JsonResponse
    {
        // Check duplicate email (global, cross-org)
        $existing = Contacto::where('email_contacto', $request->contact_email)->first();
        if ($existing) {
            return $this->errorResponse('El email del contacto ya está registrado', 409);
        }

        $result = DB::transaction(function () use ($request) {
            $nameParts = explode(' ', $request->contact_name, 2);

            $data = [
                'email' => $request->contact_email,
                'nombres' => $nameParts[0],
                'apellidos' => $nameParts[1] ?? '',
                'nombre_empresa' => $request->organization_name,
                'fuente' => $request->source ?? 'wordpress',
                'diagnostico_data' => $request->diagnostico_data,
                'plan_type' => $request->plan_type,
                'service_name' => $request->service_name,
            ];

            $passable = $this->ingestLeadAction->execute($data);

            // Plan lookup for response
            $planId = null;
            if ($request->plan_type) {
                $plan = Producto::where('tipo', 'suscripcion')
                    ->where('nombre', 'like', "%{$request->plan_type}%")
                    ->first();
                $planId = $plan?->id;
            }

            // Create Servicio
            $servicio = Servicio::create([
                'entidad_id' => $passable['entidad_id'],
                'nombre' => $request->service_name ?? 'Servicio - '.$request->organization_name,
                'vr_servicio' => 0,
                'estado' => 'Nuevo',
            ]);

            return [
                'org_id' => $passable['entidad_id'],
                'contact_id' => $passable['contacto_id'],
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
