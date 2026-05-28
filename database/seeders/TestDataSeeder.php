<?php

namespace Database\Seeders;

use App\Models\Contacto;
use App\Models\DetalleOportunidad;
use App\Models\Entidad;
use App\Models\Oportunidad;
use App\Models\Producto;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class TestDataSeeder extends Seeder
{
    public function run(): void
    {
        // ── Productos: create or update ────────────────────────────
        // Use updateOrCreate so existing rows get their tipo filled

        $planBasico = Producto::updateOrCreate(
            ['nombre' => 'Plan Básico'],
            [
                'linea_negocio' => 'Seguridad Electrónica',
                'iva' => 19,
                'estado' => 'Activo',
                'tipo' => 'suscripcion',
                'descripcion' => 'Monitoreo básico con 1 cámara',
                'caracteristicas' => ['1 cámara', 'Almacenamiento 7 días', 'Soporte email'],
                'created_by' => 1,
            ]
        );
        $planProf = Producto::updateOrCreate(
            ['nombre' => 'Plan Profesional'],
            [
                'linea_negocio' => 'Seguridad Electrónica',
                'iva' => 19,
                'estado' => 'Activo',
                'tipo' => 'suscripcion',
                'descripcion' => 'Monitoreo avanzado con 4 cámaras',
                'caracteristicas' => ['4 cámaras', 'Almacenamiento 30 días', 'Soporte 24/7'],
                'created_by' => 1,
            ]
        );
        $planEmp = Producto::updateOrCreate(
            ['nombre' => 'Plan Empresarial'],
            [
                'linea_negocio' => 'Seguridad Electrónica',
                'iva' => 19,
                'estado' => 'Activo',
                'tipo' => 'suscripcion',
                'descripcion' => 'Solución completa para empresas',
                'caracteristicas' => ['Cámaras ilimitadas', 'Almacenamiento 90 días', 'Soporte prioritario 24/7', 'Reportes personalizados'],
                'created_by' => 1,
            ]
        );

        $prodCamara = Producto::updateOrCreate(
            ['nombre' => 'Cámara IP Hikvision 2MP'],
            [
                'linea_negocio' => 'Venta de Equipos',
                'iva' => 19,
                'estado' => 'Activo',
                'tipo' => 'producto',
                'created_by' => 1,
            ]
        );
        $prodDvr = Producto::updateOrCreate(
            ['nombre' => 'DVR 4 Canales'],
            [
                'linea_negocio' => 'Venta de Equipos',
                'iva' => 19,
                'estado' => 'Activo',
                'tipo' => 'producto',
                'created_by' => 1,
            ]
        );
        $prodSensor = Producto::updateOrCreate(
            ['nombre' => 'Sensor de Movimiento'],
            [
                'linea_negocio' => 'Venta de Equipos',
                'iva' => 19,
                'estado' => 'Activo',
                'tipo' => 'producto',
                'created_by' => 1,
            ]
        );

        // ── Entidades demo ─────────────────────────────────────────

        $ent1 = Entidad::firstOrCreate(
            ['identificacion' => '901123456-7'],
            [
                'tipo_persona' => 'Juridica',
                'tipo_id' => 'NIT',
                'nombre' => 'Tecnoinnsoft SAS',
                'nombre_comercial' => 'Tecnoinnsoft',
                'direccion' => 'Cra 15 # 88-14',
                'dominio' => 'tecnoinnsoft.com',
                'estado' => 'cliente',
            ]
        );
        $ent2 = Entidad::firstOrCreate(
            ['identificacion' => '901789012-3'],
            [
                'tipo_persona' => 'Juridica',
                'tipo_id' => 'NIT',
                'nombre' => 'Distribuidora del Sur SAS',
                'nombre_comercial' => 'Delsur',
                'direccion' => 'Av 68 # 23-45',
                'estado' => 'prospecto',
            ]
        );

        // ── Contactos ──────────────────────────────────────────────

        $contacto1 = Contacto::firstOrCreate(
            ['entidad_id' => $ent1->id, 'email_contacto' => 'carlos@tecnoinnsoft.com'],
            [
                'nombres' => 'Carlos',
                'apellidos' => 'Mendoza',
                'cargo' => 'Gerente TI',
                'tel_contacto' => '3001112233',
            ]
        );
        Contacto::firstOrCreate(
            ['entidad_id' => $ent1->id, 'email_contacto' => 'ana@tecnoinnsoft.com'],
            [
                'nombres' => 'Ana',
                'apellidos' => 'López',
                'cargo' => 'Asistente Administrativa',
                'tel_contacto' => '3001112244',
            ]
        );
        $contacto3 = Contacto::firstOrCreate(
            ['entidad_id' => $ent2->id, 'email_contacto' => 'pedro@distribuidorasur.com'],
            [
                'nombres' => 'Pedro',
                'apellidos' => 'Ramírez',
                'cargo' => 'Dueño',
                'tel_contacto' => '3105556677',
            ]
        );

        // ── Oportunidades ──────────────────────────────────────────

        $opp1 = Oportunidad::firstOrCreate(
            ['codigo' => 'COT-2026-001'],
            [
                'entidad_id' => $ent1->id,
                'contacto_id' => $contacto1->id,
                'fecha' => Carbon::now()->subDays(3),
                'estado' => 'Aceptada',
                'observaciones' => 'Cliente satisfecho con la propuesta',
                'validez_oferta' => 30,
                'created_by' => 1,
            ]
        );
        $opp2 = Oportunidad::firstOrCreate(
            ['codigo' => 'COT-2026-002'],
            [
                'entidad_id' => $ent1->id,
                'contacto_id' => $contacto1->id,
                'fecha' => Carbon::now()->subDays(1),
                'estado' => 'Enviada',
                'observaciones' => 'Esperando aprobación del cliente',
                'validez_oferta' => 30,
                'created_by' => 1,
            ]
        );
        Oportunidad::firstOrCreate(
            ['codigo' => 'COT-2026-003'],
            [
                'entidad_id' => $ent2->id,
                'contacto_id' => $contacto3->id,
                'fecha' => Carbon::now(),
                'estado' => 'Borrador',
                'observaciones' => 'Primera cotización para este prospecto',
                'validez_oferta' => 30,
                'created_by' => 1,
            ]
        );

        // ── Detalles (only if they don't exist) ────────────────────

        DetalleOportunidad::firstOrCreate(
            ['oportunidad_id' => $opp1->id, 'producto_id' => $prodCamara->id],
            [
                'concepto' => 'Cámara IP Hikvision 2MP',
                'medida' => 'Und',
                'cantidad' => 4,
                'vr_unitario' => 249900,
                'iva' => 189924,
                'vr_total' => 999600,
                'created_by' => 1,
            ]
        );
        DetalleOportunidad::firstOrCreate(
            ['oportunidad_id' => $opp1->id, 'producto_id' => $prodSensor->id],
            [
                'concepto' => 'Sensor de Movimiento',
                'medida' => 'Und',
                'cantidad' => 2,
                'vr_unitario' => 59900,
                'iva' => 22762,
                'vr_total' => 119800,
                'created_by' => 1,
            ]
        );
        DetalleOportunidad::firstOrCreate(
            ['oportunidad_id' => $opp2->id, 'producto_id' => $prodDvr->id],
            [
                'concepto' => 'DVR 4 Canales',
                'medida' => 'Und',
                'cantidad' => 2,
                'vr_unitario' => 399900,
                'iva' => 151962,
                'vr_total' => 799800,
                'created_by' => 1,
            ]
        );

        $this->command->info('✅ Test data: ' . Producto::count() . ' productos, ' . Entidad::count() . ' entidades, ' . Contacto::count() . ' contactos, ' . Oportunidad::count() . ' oportunidades.');
    }
}
