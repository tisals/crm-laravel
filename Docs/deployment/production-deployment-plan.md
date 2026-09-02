# Plan de Producción — Multi-app Access & BRP

**Fecha:** 2026-08-03
**Versión:** 1.0
**Stack:** Laravel 12 + PHP 8.2 + MariaDB

---

## 1. Pre-requisitos

Antes de empezar, validar:

- [ ] Backup completo de la BD de producción (`mysqldump` o equivalente)
- [ ] Acceso SSH al servidor de producción
- [ ] Confirmar que el usuario ejecutor tiene permisos de DBA
- [ ] Tener el listado de los 4 usuarios canónicos (admin, lorena, patricia, jaime) con sus emails
- [ ] Tener el listado de los psicologos a matricular como usuarios (BD BRP)
- [ ] Tener el listado de apps a registrar (las 6: crm, sailus, marketing, wp-plugin, la-llave, brp)

---

## 2. Pasos de despliegue (en orden)

### Paso 1 — Backup
```bash
mysqldump -h mariadb -u root -p minerva > backup_$(date +%Y%m%d_%H%M%S).sql
```

### Paso 2 — Verificar estado actual
```bash
docker exec crm-laravel-dev php artisan tinker --execute="
echo 'apps: ' . DB::table('apps')->count();
echo 'roles.slug: ' . DB::table('roles')->whereNotNull('slug')->count();
echo 'usuario_app: ' . DB::table('usuario_app')->count();
echo 'contacto.persona_id: ' . DB::table('contacto')->whereNotNull('persona_id')->count();
echo 'personas: ' . DB::table('personas')->count();
echo 'usuarios.persona_id: ' . DB::table('usuarios')->whereNotNull('persona_id')->count();
echo 'entidad_usuario.tipo_relacion: ' . DB::table('entidad_usuario')->whereNotNull('tipo_relacion')->count();
echo 'servicio_app: ' . DB::table('servicio_app')->count();
echo 'auth_audit_log: ' . DB::table('auth_audit_log')->count();
"
```

**Resultado esperado** (Post-Step 3): todas las filas pobladas.

### Paso 3 — Correr las migraciones nuevas
```bash
docker exec crm-laravel-dev php artisan migrate
```

**Tablas creadas/alteradas:**
- `add_slug_and_es_super_admin_to_roles_table` (roles.slug, es_super_admin)
- `create_personas_table` (personas)
- `create_apps_table` (apps)
- `create_usuario_app_table` (usuario_app pivot)
- `add_identificacion_and_persona_id_to_contacto_table` (contacto)
- `create_auth_audit_log_table` (audit log)
- `add_tipo_relacion_to_entidad_usuario_table` (tipo_relacion, metadata)
- `add_persona_id_to_usuarios_table` (usuarios.persona_id)
- `create_servicio_app_table` (servicio_app pivot)

**Si las migraciones dicen "Nothing to migrate"** (ya aplicadas): continuar al Paso 4.

### Paso 4 — Aplicar data migrations (CRÍTICO)

Si las migraciones se aplicaron sobre una BD con datos preexistentes, las data migrations internas NO se ejecutaron retroactivamente. Aplicar manualmente:

```bash
docker exec crm-laravel-dev php /var/www/html/crm:remediate.php
```

Si no tenés `crm:remediate.php`, ejecutar el script embebido (ver Apéndice A).

**Lo que hace:**
1. Pobla `roles.slug` para los 4 roles originales (SuperAdmin, Comercial, Operaciones, Finanzas)
2. Marca `es_super_admin=true` en SuperAdmin
3. Re-corre `AppsSeeder`, `BrpRolesSeeder`, `UsuarioAppAssignmentsSeeder`
4. Setea `tipo_relacion='asignado'` en las 2054 filas existentes de `entidad_usuario`
5. Re-corre `crm:backfill-personas` (crea personas desde contactos)
6. Re-corre `crm:backfill-usuarios-personas` (vincula usuarios staff a personas)

### Paso 5 — Verificar post-remediación
```bash
docker exec crm-laravel-dev php artisan tinker --execute="
echo 'apps: ' . DB::table('apps')->count() . ' (expected: 6)';
echo 'roles.slug: ' . DB::table('roles')->whereNotNull('slug')->count() . ' (expected: 7)';
echo 'usuario_app: ' . DB::table('usuario_app')->count() . ' (expected: 11)';
echo 'contacto.persona_id: ' . DB::table('contacto')->whereNotNull('persona_id')->count() . ' (expected: ~2828)';
echo 'personas: ' . DB::table('personas')->count() . ' (expected: ~2133)';
echo 'usuarios.persona_id: ' . DB::table('usuarios')->whereNotNull('persona_id')->count() . ' (expected: 0 for staff)';
echo 'entidad_usuario.tipo_relacion: ' . DB::table('entidad_usuario')->whereNotNull('tipo_relacion')->count() . ' (expected: 2054)';
"
```

### Paso 6 — Smoke test end-to-end con curl

Verificar 4 escenarios críticos:

```bash
# Login admin → 6 apps
curl -X POST http://localhost:8001/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"email":"admin@tecnoinnsoft.dev","password":"<password>","device_name":"deploy-verify"}'

# Token-exchange (sin X-Internal-Source → 400)
curl -i -X POST http://localhost:8001/api/v1/auth/token-exchange \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"email":"admin@tecnoinnsoft.dev","password":"<password>","device_name":"test"}'

# Validate-token (X-API-Key → 400)
curl -i http://localhost:8001/api/v1/auth/validate-token \
  -H "Accept: application/json" \
  -H "X-API-Key: fake"
```

**Esperado**:
- Login admin: 200 con 6 apps
- Token-exchange sin X-Internal-Source: 400 con `missing_internal_source`
- Validate-token con X-API-Key: 400 con `bearer_required`

### Paso 7 — Documentar cambios de entorno

Si todavía no está, agregar al `.env`:
```bash
# Multi-app access
TOKEN_EXCHANGE_TTL=3600
TOKEN_EXCHANGE_ALLOWED_SOURCES=sailus
```

### Paso 8 — Comunicación con SAIlus

Enviar el doc `D:\sitios desarrollo\crm-laravel\Docs\integrations\sailus-integration.md` al equipo SAIlus.

Puntos clave:
- Endpoint `/auth/token-exchange` con header `X-Internal-Source: sailus`
- Endpoint `/auth/validate-token` con Bearer Sanctum
- Caché 5 min (HIT/MISS en `X-Cache`)
- Throttling 120/min
- Audit log en `auth_audit_log`

---

## 3. Rollback plan

Si algo falla en los primeros 30 minutos post-deploy:

### Paso R1 — Revertir migraciones
```bash
docker exec crm-laravel-dev php artisan migrate:rollback --step=9
```
(9 = número de migraciones nuevas, contando del más reciente al más viejo)

### Paso R2 — Restaurar backup
```bash
mysql -h mariadb -u root -p minerva < backup_YYYYMMDD_HHMMSS.sql
```

### Paso R3 — Verificar estado
```bash
docker exec crm-laravel-dev php artisan tinker --execute="
echo 'apps: ' . DB::table('apps')->count() . ' (expected: 0)';
echo 'roles.slug: ' . DB::table('roles')->whereNotNull('slug')->count() . ' (expected: 0)';
"
```

### Paso R4 — Comunicar a stakeholders
"Deploy reverted. Service restored to pre-change state. Investigation pending."

---

## 4. Post-deploy monitoring

### Métricas a vigilar (primeras 48h)

| Métrica | Threshold | Acción |
|---|---|---|
| Login 5xx rate | > 0.5% | Investigación inmediata |
| Token-exchange 5xx rate | > 0.1% | Investigación inmediata |
| Validate-token latency p95 | > 200ms | Investigar cache hit/miss ratio |
| Token-exchange latency p95 | > 500ms | Investigar DB queries |
| Audit log growth | < 1k rows/h | OK |
| Cache hit rate (validate-token) | > 80% | OK |

### Logs clave
- `auth_audit_log` table: `SELECT * FROM auth_audit_log ORDER BY created_at DESC LIMIT 100`
- Laravel logs: `storage/logs/laravel.log`
- Nginx access log: `/var/log/nginx/access.log`

---

## 5. Próximos pasos (post-deploy)

### Sprint 2 (Semana 2)
- [ ] Resolver los 9 críticos del verify v2 (los 5 fixes ya confirmados en approval tests, quedan 4)
- [ ] Implementar servicio_app para entidades activas
- [ ] Crear endpoint admin para asignar apps a entidades (no solo a usuarios)

### Sprint 3 (Semana 3)
- [ ] BRP backend repository (proyecto separado)
- [ ] Onboarding psicologos (crear usuario + persona + proveedor + asignación)
- [ ] BRP integration tests con el CRM

### Sprint 4 (Semana 4)
- [ ] Launch BRP con el cliente Banco de Bogotá (3000 empleados)
- [ ] Carga de empleados (script con chunks)
- [ ] Onboarding programaciones

---

## 6. Apéndice A — Script de remediación

Si no tenés el archivo `crm:remediate.php`, crear en `/var/www/html/crm:remediate.php`:

```php
<?php
$base = '/var/www/html';
$envFile = $base . '/.env';
foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (str_starts_with(trim($line), '#')) continue;
    if (!str_contains($line, '=')) continue;
    [$k, $v] = explode('=', $line, 2);
    putenv(trim($k) . '=' . trim($v));
    $_ENV[trim($k)] = trim($v);
    $_SERVER[trim($k)] = trim($v);
}
require $base . '/vendor/autoload.php';
$app = require $base . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;

// Step 1: Populate roles.slug
$roleSlugMap = [
    'SuperAdmin' => 'super-admin', 'Comercial' => 'comercial',
    'Operaciones' => 'operaciones', 'Finanzas' => 'finanzas',
];
foreach ($roleSlugMap as $nombre => $slug) {
    DB::table('roles')->where('nombre', $nombre)->whereNull('slug')->update(['slug' => $slug]);
}
DB::table('roles')->where('nombre', 'SuperAdmin')->update(['es_super_admin' => true]);

// Step 2: Re-run seeders (idempotent)
Artisan::call('db:seed', ['--class' => 'Modules\\\\Shared\\\\Database\\\\Seeders\\\\AppsSeeder']);
Artisan::call('db:seed', ['--class' => 'Modules\\\\Shared\\\\Database\\\\Seeders\\\\BrpRolesSeeder']);
Artisan::call('db:seed', ['--class' => 'Modules\\\\Shared\\\\Database\\\\Seeders\\\\UsuarioAppAssignmentsSeeder']);

// Step 3: Set default tipo_relacion on entidad_usuario
DB::table('entidad_usuario')->whereNull('tipo_relacion')->update(['tipo_relacion' => 'asignado']);

// Step 4: Backfill personas from contactos
Artisan::call('crm:backfill-personas');

// Step 5: Backfill usuarios.persona_id
Artisan::call('crm:backfill-usuarios-personas');

echo "REMEDIATION DONE\n";
```

Ejecutar con:
```bash
docker cp remediate.php crm-laravel-dev:/var/www/html/remediate.php
docker exec crm-laravel-dev php /var/www/html/remediate.php
```

---

## 7. Contacto y escalación

- **Issue tracker:** `#integration/crm-laravel` channel
- **On-call:** Rotación semanal según schedule
- **Criticidad:** Cualquier falla en login o token-exchange es **SEV-1** — escalar a tech lead inmediatamente

---

**Última actualización:** 2026-08-03
**Próximo review:** después del deploy de BRP (Banco de Bogotá)

---

## 8. Addendum — Limpieza de duplicados de contactos (post-prod 2026-08-04)

**Contexto:** En prod hay 442 emails duplicados (mismo email en múltiples entidades, distintas `entidad_id`). El seeder `ContactoCsvSeeder` usa DELETE+INSERT sin validar — es la **causa raíz** del problema.

**Estado actual (post-limpieza 2026-08-04):**

| Métrica | Valor |
|---|---|
| Grupos de emails duplicados | 442 |
| SKIP (dominios ambiguos) | 233 |
| Match de dominio único | 107 |
| Protegidas ganan | 100 |
| Menos contactos gana | 1 |
| Contactos soft-deleted aplicados | **206** |
| Contactos aún con duplicados (por dominio ambiguo) | 233 casos en CSV para revisión manual |
| Backup pre-cambio | `Docs/backup_contacto_pre_limpieza.sql` (667KB, 2828 contactos) |

**Comando nuevo:** `php artisan crm:limpiar-duplicados-contactos`

**Para garantizar ejecución automática tras cada seed**, agregar al final de `DatabaseSeeder.php`:

```php
$this->call([
    // ... seeders existentes ...
    SharedDatabaseSeeder::class,
]);

// NUEVO: limpieza de duplicados post-seed
\Illuminate\Support\Facades\Artisan::call('crm:limpiar-duplicados-contactos', ['--apply' => true]);
```

**Causa raíz:** En `database/seeders/ContactoCsvSeeder.php` líneas 286-297:

```php
DB::transaction(function () use ($rows) {
    $entidadIds = array_unique(array_column($rows, 'entidad_id'));
    if (! empty($nonNullIds)) {
        DB::table('contacto')->whereIn('entidad_id', $nonNullIds)->delete(); // ⚠️ HARD DELETE
    }
    DB::table('contacto')->whereNull('entidad_id')->delete(); // ⚠️ HARD DELETE
    DB::table('contacto')->insert($rows);
});
```

El `DELETE + INSERT` borra todo y re-inserta. Si el CSV tiene `Karen Medina | karen@x.com | Acme` y otra fila `Karen Medina | karen@x.com | Vortex`, crea 2 contactos con mismo email en distintas entidades. NO hay dedupe global, solo intra-corrida.

**TODO (no bloqueante):** Refactor del seeder para ser idempotente:
- Usar `updateOrCreate(['email_contacto' => ..., 'entidad_id' => ...], [...])`
- O `firstOrCreate` con merge

**Pendiente (manual):** Revisar el CSV generado con los 233 grupos de dominios compartidos y decidir manualmente cuáles deben conservar el dominio.
