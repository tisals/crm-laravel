# Guía de Despliegue — CRM Tecnoinnsoft

## Repositorios

| Proyecto | Ubicación | Descripción |
|----------|-----------|-------------|
| **Backend** | `D:\sitios desarrollo\crm-laravel` | Laravel 12 API |
| **Frontend** | `D:\sitios desarrollo\dashboard-crm` | React 18 + Vite SPA |

---

## Desarrollo Local

### Requisitos
- PHP 8.2+
- Composer 2.x
- Node.js 20+
- SQLite (incluido en PHP) o MariaDB/MySQL

### 1. Backend (crm-laravel)

```bash
cd "D:\sitios desarrollo\crm-laravel"

# Instalar dependencias
composer install

# Copiar env y configurar
copy .env.example .env
# Editar .env: APP_URL=http://localhost:8001
#              DB_CONNECTION=sqlite
#              DB_DATABASE=database/database.sqlite

# Generar keys
php artisan key:generate

# Crear SQLite (si no existe)
echo "" > database/database.sqlite

# Migrar + seed
php artisan migrate:fresh --seed

# Iniciar servidor
php artisan serve --port=8001
```

**Seed genera:**
- Roles: `super_admin`, `comercial`, `operaciones`, `admin`
- Permisos asociados
- Ciudades colombianas
- 1 usuario admin: `admin@tecnoinnsoft.dev` / `password` (8+ chars)

### 2. Frontend (dashboard-crm)

```bash
cd "D:\sitios desarrollo\dashboard-crm"

# Instalar dependencias
npm install

# Crear .env.local
# VITE_API_BASE_URL=http://localhost:8001/api/v1

# Iniciar dev server
npm run dev
# → http://localhost:5173
```

### 3. Ambos en paralelo (opcional)

```bash
# En PowerShell (desde dashboard-crm)
npm run dev

# En otra terminal (desde crm-laravel)
php artisan serve --port=8001
```

---

## Staging (EasyPanel)

### Preparar imágenes Docker

```bash
# En crm-laravel
# Asegurar que .env.production tiene valores reales
# APP_KEY, DB_*, MAIL_*

# Build producción
npm run build  # desde dashboard-crm (copia a public/build)

composer install --optimize-autoloader --no-dev

php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### EasyPanel Deploy

1. **Conectar repositorio** a EasyPanel (GitHub)
2. **Build command** (crm-laravel):
   ```bash
   composer install --no-dev && npm run build && php artisan config:cache && php artisan route:cache
   ```
3. **Start command** (el Dockerfile tiene su propio entrypoint que corre migrate + seed automáticamente):
   ```bash
   # El entrypoint (docker-entrypoint.sh) ya hace:
   # key:generate → migrate --force → db:seed --force → php-fpm + nginx
   # No hace falta correr artisan serve.
   ```

4. **Variables de entorno** en EasyPanel:
   ```
   APP_ENV=production
   APP_DEBUG=false
   APP_URL=https://hubapi.tecnoinnsoft.com/

   DB_CONNECTION=mysql
   DB_HOST=<host>
   DB_PORT=3306
   DB_DATABASE=crm_prod
   DB_USERNAME=<user>
   DB_PASSWORD=<password>

   MAIL_MAILER=log
   ```

5. **Deploy del Frontend** (dashboard-crm):
   - Deploy manual a EasyPanel
   - Variable: `VITE_API_BASE_URL=https://hubapi.tecnoinnsoft.com/api/v1`

### Checklist de deploy (backend)

```bash
# 1. Obtener container ID
CID=$(docker ps --filter "ancestor=easypanel/prod/crm-back:latest" -q)

# 2. Verificar que git pull se aplicó (EasyPanel lo hace automáticamente)
docker exec $CID cat /var/www/html/.git/HEAD

# 3. Correr migraciones pendientes
docker exec $CID php artisan migrate --force

# 4. Limpiar caché
docker exec $CID php artisan config:clear
docker exec $CID php artisan route:clear
docker exec $CID php artisan cache:clear

# 5. Regenerar autoload
docker exec $CID composer dump-autoload --optimize

# 6. Verificar salud
docker exec $CID php artisan migrate:status
curl -s https://hubapi.tecnoinnsoft.com/api/v1/plans | head -c 200
```

### Verificar deploy

```bash
# Health check
curl https://crm-api.tecnoinnsoft.dev/api/v1/health

# Login test
curl -X POST https://crm-api.tecnoinnsoft.dev/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@tecnoinnsoft.dev","password":"password"}'
```

---

## Base de datos — Migraciones

### Desarrollo local (Docker)
```bash
# Reset completo — USAR SIEMPRE en dev (no hay "Nothing to migrate" surprises)
docker exec crm-laravel-dev php artisan migrate:fresh --seed --force

# Solo migraciones pendientes
docker exec crm-laravel-dev php artisan migrate --force

# Ver estado de migraciones
docker exec crm-laravel-dev php artisan migrate:status
```

> **⚠️ No usar `php artisan migrate --seed` (sin `fresh`)** sobre una DB con datos viejos — los seeders intentan insertar encima de datos existentes y pegan con FK constraints. `migrate:fresh --seed` dropea todo y recrea limpio.

### Producción / Staging (EasyPanel SSH)

En prod, `php artisan` NO corre directamente — hay que invocarlo dentro del container cuyo ID cambia en cada deploy.

```bash
# Obtener el ID del container activo (cambia en cada deploy)
CID=$(docker ps --filter "ancestor=easypanel/prod/crm-back:latest" -q)

# Migraciones pendientes (después de git pull en EasyPanel)
docker exec $CID php artisan migrate --force

# Limpiar caché
docker exec $CID php artisan config:clear
docker exec $CID php artisan route:clear
docker exec $CID php artisan cache:clear

# Regenerar autoload (si se cambiaron modelos/use cases)
docker exec $CID composer dump-autoload --optimize

# Verificar estado
docker exec $CID php artisan migrate:status
```

> **Nunca correr `migrate:fresh` en prod** — borra TODA la data.

### Emergencias (producción)
```bash
CID=$(docker ps --filter "ancestor=easypanel/prod/crm-back:latest" -q)

# Rollback última migración
docker exec $CID php artisan migrate:rollback

# Logs en tiempo real
docker exec $CID tail -f storage/logs/laravel.log
```

---

## Logs y Debug

### Backend — Desarrollo (Docker)
```bash
docker exec crm-laravel-dev php artisan tail

# Ver logs en tiempo real
docker exec crm-laravel-dev tail -f storage/logs/laravel.log

# Ver últimas líneas
docker exec crm-laravel-dev tail -100 storage/logs/laravel.log

# Limpiar cachés
docker exec crm-laravel-dev php artisan config:clear
docker exec crm-laravel-dev php artisan cache:clear
docker exec crm-laravel-dev php artisan view:clear
```

### Backend — Producción (EasyPanel SSH)
```bash
CID=$(docker ps --filter "ancestor=easypanel/prod/crm-back:latest" -q)

# Ver logs
docker exec $CID tail -100 storage/logs/laravel.log

# Verificar que el container está vivo
docker exec $CID php artisan --version

# Tinker interactivo
docker exec -it $CID php artisan tinker
```

### Queue workers (producción)
```bash
CID=$(docker ps --filter "ancestor=easypanel/prod/crm-back:latest" -q)

# Trabaja cola de emails, webhooks salientes
docker exec $CID php artisan queue:work --tries=3
```

---

## Webhooks Salientes

El CRM envía webhooks HMAC-SHA256 al endpoint configurado en `CRM_WEBHOOK_URL`.

- **Org creado**: `POST /webhooks/organization`
- **Contacto actualizado**: `POST /webhooks/contact`
- **Pago completado**: `POST /webhooks/payment`

Si `CRM_WEBHOOK_URL` no está definido → se omiten silenciosamente.

---

## Usuario de prueba

| Campo | Valor |
|-------|-------|
| Email | `admin@tecnoinnsoft.dev` |
| Contraseña | `password` (mínimo 8 caracteres) |
| Rol | `super_admin` |

---

## Troubleshooting

| Problema | Solución |
|-----------|----------|
| Error 401 en frontend | Verificar que `auth_token` está en localStorage y no expiró |
| Página en blanco | `npm run build` en dashboard-crm, limpiar `storage/framework/views` |
| DB conexión fallida | Verificar `.env` → `DB_HOST`, `DB_DATABASE`, credenciales |
| Webhooks no se envían | Verificar `CRM_WEBHOOK_URL` y que el servidor destino acepta HMAC |
| `Nothing to migrate` en dev | Usar `migrate:fresh --seed --force` en lugar de `migrate --seed` |
| FK violation en seed | El container tiene un container ID diferente tras cada deploy; usar `docker ps` para obtenerlo |

---

*Última actualización: 2026-07-22*

---

## Procedimiento: Reconciliación de Entidades (sin dominio)

**Cuándo usar:** Cuando se detecta que entidades en producción quedaron mal asignadas a oportunidades/contactos, especialmente con dominios NULL.

### Script
`database/fixes/reconcile-entities-sin-dominio.php`

### Lógica (3 reglas)

1. **A1 — CSV sin dominio cruza con shell**: Si la entidad destino es una "shell" (sin opps, sin contactos, sin dominio) y matchea por nombre normalizado → reasignar oportunidad + contacto al shell, actualizar nombre del shell con el real del CSV.

2. **A1 — CSV sin dominio matchea con entidad real existente**: Si ya está bien asignada → no hacer nada (skip).

3. **A1 — CSV sin dominio no matchea nada**: Si la oportunidad existe en BD y no hay match con shell ni con real → crear nueva entidad y reasignar.

4. **A2 — Borrar shells huérfanas**: Después del A1, todas las entidades que siguen siendo shells (0 opps, 0 contactos, sin dominio) se eliminan.

### Ejecución

```bash
# En el container de prod (después de EasyPanel deploy)
CID=$(docker ps --filter "ancestor=easypanel/prod/crm-back:latest" -q)

# 1. Dry-run (reporte, no aplica cambios)
docker exec -e DBPW='Tis_innovation_1' $CID \
  php /var/www/html/database/fixes/reconcile-entities-sin-dominio.php --dry-run

# 2. Aplicar (si el reporte es correcto)
docker exec -e DBPW='Tis_innovation_1' $CID \
  php /var/www/html/database/fixes/reconcile-entities-sin-dominio.php
```

---

## Procedimiento: Import Delta Solo Nuevos

**Cuándo usar:** Durante el período de transición, cuando se sigue usando el método viejo de import que crea duplicados. Este script procesa **solo codigos nuevos** del CSV, evitando crear duplicadas.

### Script
`database/fixes/import-delta-solo-nuevos.php`

### Lógica

1. Lee `oportunidades.csv` (path por defecto: `/var/www/html/database/csv/oportunidades.csv`)
2. Para cada row:
   - Si `codigo` ya existe en BD → **SKIP** (no crea duplicada)
   - Si `codigo` aparece más de una vez en el CSV → solo procesa la primera (las demás SKIP)
   - Si no existe → procesar:
     a. **Entity**: si `dominio` matchea por dominio → usar esa; si no, match por nombre normalizado; si no, crear nueva
     b. **Contacto**: si `email` ya existe → reasignar a la entidad actual si difiere; si no, crear
     c. **Oportunidad**: INSERT con `codigo`, `entidad_id`, `valor_sin_iva`, `fecha`, `is_latest=true`, `version=0`, `estado='Activa'`

### Reglas canónicas (1-a-1)

- 1 codigo = 1 oportunidad
- 1 (empresa, dominio) = 1 entidad
- 1 email = 1 contacto

### Ejecución
```bash
# En el container de prod (después de EasyPanel deploy)
CID=$(docker ps --filter "ancestor=easypanel/prod/crm-back:latest" -q)


# 1. Dry-run (reporte de qué se crearía)
docker exec -e DBPW='Tis_innovation_1' $CID \
  php /var/www/html/database/fixes/import-delta-solo-nuevos.php --dry-run

# 2. Aplicar
docker exec -e DBPW='Tis_innovation_1' $CID \
  php /var/www/html/database/fixes/import-delta-solo-nuevos.php
```

### Salida esperada

```
=== DRY-RUN ===
CSV: /var/www/html/database/csv/oportunidades.csv

Existing codigos in DB: 2718
Entities with domain: 1500
Entities without domain: 700
Contactos with email: 2100

=== RESUMEN ===
  created: <N>
  skipped_existing: <M>
  skipped_dup_in_csv: <K>
  failed: 0
```

### Migración de codigos duplicados

Para limpiar codigos duplicados que ya existen (creados por el método viejo), renombrar manualmente via SQL/phpMyAdmin:

```sql
-- Renombrar la versión vieja para liberar el codigo "limpio"
UPDATE oportunidad
SET codigo = CONCAT(codigo, '_v1_old_', UNIX_TIMESTAMP())
WHERE id = <ID_VIEJA>;
```

Después correr el delta import con el codigo limpio.

### Plan de salida del método viejo

1. ✅ Actualizar manualmente cotizaciones nuevas con el método canónico
2. 🕒 Continuar corriendo delta import para que el CSV legacy no cree duplicadas
3. 🕒 Cuando todas las cotizaciones se hagan con el método nuevo, dejar de correr el delta import
4. 🕒 Cleanup final: borrar todos los codigos `_v1_old_*` y desactivar el método viejo

---

## Procedimiento: Reconciliación de Entidades sin Dominio

**Cuándo usar:** Cuando hay entidades en BD con dominio NULL que están mal asignadas, y el CSV `oportunidades.csv` tiene rows sin dominio que deberían asignarse correctamente.

### Script
`database/fixes/reconcile-entities-sin-dominio.php`

### Reglas (1-a-1)

- **1 codigo = 1 oportunidad**
- **1 (empresa, dominio) = 1 entidad** (dominio es la clave primaria, nombre es secundario)
- **1 email = 1 contacto**

### Lógica A1 — Para cada row del CSV sin dominio:

1. Calcular `empresa_normalizada` (sin sufijos S.A.S, LTDA, etc.)
2. **Match contra SHELL** (entidad con 0 opps, 0 contactos, dominio NULL):
   - Si matchea → reasignar oportunidad + contacto al shell
3. **Match contra entidad REAL existente** (dominio NULL pero con datos):
   - Si matchea → no hacer nada (ya está bien)
4. **Si no matchea nada**:
   - Crear nueva entidad con el nombre del CSV
   - Reasignar oportunidad + contacto

### Lógica A2 — Después de A1:

- Borrar todas las shells que quedaron sin reasignar (0 opps, 0 contactos, dominio NULL)

### Ejecución

```bash
# 1. Dry-run (reporte, no aplica cambios)
CID=$(docker ps --filter "ancestor=easypanel/prod/crm-back:latest" -q)
docker exec -e DBPW='Tis_innovation_1' $CID \
  php /var/www/html/database/fixes/reconcile-entities-sin-dominio.php --dry-run

# 2. Aplicar (si el reporte es correcto)
docker exec -e DBPW='Tis_innovation_1' $CID \
  php /var/www/html/database/fixes/reconcile-entities-sin-dominio.php
```

### Salida esperada

```
=== DRY-RUN ===
CSV: /var/www/html/database/csv/oportunidades.csv

Shells (0 opps, 0 contactos, sin dominio): <N>
Reales sin dominio: <M>
Oportunidades en BD: <X>
Contactos con email: <Y>

=== A1 — Process sin-dominio ===
  reassigned_shell: <A>
  real_match: <B>
  new_entity: <C>
  skipped_with_dom: <D>
  no_opp: <E>

=== A2 — Shells huérfanas (a borrar) ===
Total: <F>
```

### Por qué se necesita B2 (fix del seed)

El import use case original (`OportunidadCsvImportUseCase::resolveEntityId()`) usaba **keyword fuzzy matching** con `$bestScore >= 1` que causaba:
- "B2" matcheaba con "B2 Soluciones" (porque comparten keyword "b2b")
- "TECNOINN" absorbía otras entidades sin dominio

El fix en commit `70e677d` eliminó el keyword fuzzy. Ahora solo match estricto:
- Dominio exacto → 1 dominio = 1 entidad
- Nombre normalizado → fallback seguro

### Diagnóstico previo

Antes de correr el script, ejecutá:

```sql
-- Entidades sin dominio y sus oportunidades
SELECT e.id, e.nombre, e.dominio,
       (SELECT COUNT(*) FROM oportunidad WHERE entidad_id=e.id) AS opps,
       (SELECT COUNT(*) FROM contacto WHERE entidad_id=e.id) AS contactos
FROM entidad e
WHERE e.dominio IS NULL OR e.dominio = ''
ORDER BY opps DESC, contactos DESC
LIMIT 50;

-- Shells (entidades vacías)
SELECT e.id, e.nombre
FROM entidad e
WHERE (e.dominio IS NULL OR e.dominio='')
  AND (SELECT COUNT(*) FROM oportunidad WHERE entidad_id=e.id)=0
  AND (SELECT COUNT(*) FROM contacto WHERE entidad_id=e.id)=0
ORDER BY e.nombre;

-- Top entidades con contactos de dominios mezclados (sospechosas de merge malo)
SELECT e.id, e.nombre, e.dominio, COUNT(c.id) AS contactos,
       GROUP_CONCAT(DISTINCT SUBSTRING_INDEX(c.email_contacto, '@', -1) SEPARATOR ', ') AS dominios
FROM entidad e
LEFT JOIN contacto c ON c.entidad_id = e.id
WHERE e.dominio IS NULL OR e.dominio = ''
GROUP BY e.id, e.nombre, e.dominio
HAVING contactos > 0
ORDER BY contactos DESC
LIMIT 20;
```

---

### Salida esperada

```
=== DRY-RUN ===
CSV: /var/www/html/database/csv/oportunidades.csv

Shells (0 opps, 0 contactos, sin dominio): <N>
Reales sin dominio: <M>
Oportunidades en BD: <X>
Contactos con email en BD: <Y>

=== A1 — Process sin-dominio ===
  reassigned_shell: <A>
  real_match: <B>
  new_entity: <C>
  skipped_with_dom: <D>
  no_opp: <E>

=== A2 — Shells huérfanas (a borrar) ===
Total: <F>
  [SHELL] id=N nombre=X
```

---

## Procedimiento: Limpieza Integral de Entidades (cleanup-v3)

**Cuándo usar:** Después de aplicar el `reconcile-entities-sin-dominio.php` o cuando se detecta que hay muchas entidades con datos mal asignados (opps huérfanas, shells que no deberían existir, dominios faltantes).

### Script
`database/fixes/cleanup-v3.php`

### Modos

| Modo | Comando | Uso |
|------|---------|-----|
| **Dry-run** | `php cleanup-v3.php` | Genera reporte + temp table. NO aplica cambios destructivos. |
| **Apply from temp** | `php cleanup-v3.php --apply --from-temp-table` | Aplica cambios aprobados de la temp table. |

### Stages

1. **`opp_reasign` (CSV canónico)** — Reasigna opps según el dominio del CSV. Si la entidad destino no existe → la crea.

2. **`opp_reasign_by_contact`** — Reasigna opps según el dominio del email del contacto asociado.

3. **`entity_infer_dominio`** — Para entidades con NIT + contactos con dominios compartidos, infiere el dominio corporativo.

4. **`entity_consolidate`** — Detecta entidades con nombre normalizado duplicado y las consolida: mueve opps/contactos al ganador, borra perdedores (con dedup de emails).

5. **`shell_delete` en dos pasadas:**
   - **5a — Iniciales**: entidades vacías desde el inicio (0 opps, 0 contactos, dominio NULL).
   - **5b — Post-movimiento**: entidades que quedaron vacías DESPUÉS de los stages 1-4. Calculado en memoria: `oppCount - reasignaciones - consolidaciones`.

### Workflow completo

```bash
# Variables
CID=$(docker ps --filter "ancestor=easypanel/prod/crm-back:latest" -q)
DBPW='Tis_innovation_1'

# 1. Backup antes de cualquier cosa
docker exec -e DBPW=$DBPW $CID php -r '
$pdo = new PDO("mysql:host=" . getenv("DB_HOST") . ";dbname=" . getenv("DB_NAME"), getenv("DB_USERNAME"), getenv("DBPW"));
$pdo->exec("DROP TABLE IF EXISTS cleanup_proposed_changes_backup");
$pdo->exec("CREATE TABLE cleanup_proposed_changes_backup AS SELECT * FROM cleanup_proposed_changes");
$pdo->exec("DROP TABLE IF EXISTS entidad_backup_pre_cleanup");
$pdo->exec("CREATE TABLE entidad_backup_pre_cleanup AS SELECT * FROM entidad");
$pdo->exec("DROP TABLE IF EXISTS oportunidad_backup_pre_cleanup");
$pdo->exec("CREATE TABLE oportunidad_backup_pre_cleanup AS SELECT * FROM oportunidad");
$pdo->exec("DROP TABLE IF EXISTS contacto_backup_pre_cleanup");
$pdo->exec("CREATE TABLE contacto_backup_pre_cleanup AS SELECT * FROM contacto");
echo "Backups OK\n";
'

# 2. Dry-run (genera reporte + temp table)
docker exec -e DBPW=$DBPW $CID php /var/www/html/database/fixes/cleanup-v3.php

# Revisar el reporte generado en database/cleanup-reports/cleanup-pending-FECHA.txt
# Y revisar las propuestas en la tabla cleanup_proposed_changes:

docker exec -e DBPW=$DBPW $CID php -r '
$pdo = new PDO("mysql:host=" . getenv("DB_HOST") . ";dbname=" . getenv("DB_NAME"), getenv("DB_USERNAME"), getenv("DBPW"));
foreach ($pdo->query("SELECT action_type, status, COUNT(*) as c FROM cleanup_proposed_changes GROUP BY action_type, status") as $r) {
    echo "{$r["action_type"]} ({$r["status"]}): {$r["c"]}\n";
}
'

# 3. Revisar manualmente y agregar notas / aprobar / rechazar

# Las notas se preservan entre corridas (basado en hash del payload)
# Aprobar propuestas legítimas:
docker exec -e DBPW=$DBPW $CID php -r '
$pdo = new PDO("mysql:host=" . getenv("DB_HOST") . ";dbname=" . getenv("DB_NAME"), getenv("DB_USERNAME"), getenv("DBPW"));
$pdo->exec("UPDATE cleanup_proposed_changes SET status = \"approved\" WHERE id IN (1,2,3,...)");
'

# Rechazar las que no querés aplicar (ej. Brinks, DIPREM aliases):
docker exec -e DBPW=$DBPW $CID php -r '
$pdo = new PDO("mysql:host=" . getenv("DB_HOST") . ";dbname=" . getenv("DB_NAME"), getenv("DB_USERNAME"), getenv("DBPW"));
$pdo->exec("UPDATE cleanup_proposed_changes SET status = \"rejected\", user_notes = \"batch cotizacion\" WHERE action_type = \"opp_reasign\" AND id IN (4,5,...)");
'

# 4. Aplicar cambios aprobados
docker exec -e DBPW=$DBPW $CID php /var/www/html/database/fixes/cleanup-v3.php --apply --from-temp-table

# Salida esperada:
#   opp_reasign: <N>
#   entity_create: <M>
#   entity_consolidate: <K>
#   shell_delete: <L>
#   failed: 0

# 5. Verificar conteos finales
docker exec -e DBPW=$DBPW $CID php -r '
$pdo = new PDO("mysql:host=" . getenv("DB_HOST") . ";dbname=" . getenv("DB_NAME"), getenv("DB_USERNAME"), getenv("DBPW"));
echo "Entidades: " . $pdo->query("SELECT COUNT(*) FROM entidad")->fetchColumn() . "\n";
echo "Oportunidades: " . $pdo->query("SELECT COUNT(*) FROM oportunidad")->fetchColumn() . "\n";
echo "Contactos: " . $pdo->query("SELECT COUNT(*) FROM contacto")->fetchColumn() . "\n";
echo "Shells: " . $pdo->query("SELECT COUNT(*) FROM entidad e WHERE (e.dominio IS NULL OR e.dominio = \"\") AND NOT EXISTS (SELECT 1 FROM oportunidad WHERE entidad_id = e.id) AND NOT EXISTS (SELECT 1 FROM contacto WHERE entidad_id = e.id)")->fetchColumn() . "\n";
'
```

### Criterios de rechazo comunes (registrados en `instrucciones.md`)

| Criterio | SQL ejemplo |
|----------|-------------|
| **Batch cotización** — códigos consecutivos del mismo admin request, distintas empresas | `WHERE action_type = 'opp_reasign' AND id IN (...)` |
| **Grupo empresarial** (Brinks, etc.) — cada subsidiaria es entidad separada | `WHERE reason LIKE '%BRINKS%'` |
| **Multi-admin** — opps consecutivas con `created_by` distintos | `WHERE target_id IN (...requiere query previa...)` |

### Notas importantes

- **El `--apply` solo afecta `status = 'approved'`** — los `pending` quedan intactos para revisión.
- **Las notas se preservan entre corridas** — agregar `user_notes = 'razón'` no se pierde al re-correr dry-run.
- **El temp table tiene `UNIQUE KEY (action_type, payload_hash)`** — propuestas duplicadas (mismo payload) se descartan automáticamente.
- **Stage 5b detecta shells nuevos** — es crítico para encontrar entidades que quedaron vacías tras consolidaciones.

### Resultados esperados en dev (post-2026-07-16)

| Métrica | Antes | Después |
|---------|-------|---------|
| Entidades | 2533 | **2251** |
| Oportunidades | 2812 | 2812 |
| Contactos | 2812 | **2541** |
| Shells | 52 | **0** |

**1081 acciones aplicadas**, 75 rechazadas, 0 pendientes.

---

## Procedimiento: Repair Oportunidad Versioning

**Cuándo usar:** Cuando el versionado de oportunidades está inconsistente (familias con `is_latest` apuntando a versiones viejas, `parent_id` cruzados, version default ≠ 0). Causado por la migración `2026_07_10_000000` que decía `default(0)` pero en prod quedó en `1`.

### Script
`database/fixes/repair-oportunidad-versioning.php`

### Lógica

Para cada familia de oportunidades (mismo `codigo` base):
1. La versión no-borrada con `version` más alto → `is_latest=1`, `estado='Activa'`, `parent_id=NULL`
2. Las demás → `is_latest=0`, `estado='Inactiva'`, `parent_id=<latest_id>`
3. Filas con soft-delete se dejan intactas

### Ejecución

```bash
CID=$(docker ps --filter "ancestor=easypanel/prod/crm-back:latest" -q)
DBPW='Tis_innovation_1'

# 1. Dry-run (solo reporte, no muta)
docker exec -e DBPW=$DBPW $CID \
  php /var/www/html/database/fixes/repair-oportunidad-versioning.php --dry-run

# 2. Aplicar (si el reporte es correcto)
docker exec -e DBPW=$DBPW $CID \
  php /var/www/html/database/fixes/repair-oportunidad-versioning.php

# 3. Verificar
docker exec -e DBPW=$DBPW $CID php -r '
$pdo = new PDO("mysql:host=" . getenv("DB_HOST") . ";dbname=" . getenv("DB_DATABASE"), getenv("DB_USERNAME"), getenv("DBPW"));
$total = $pdo->query("SELECT COUNT(*) FROM oportunidad")->fetchColumn();
$latest = $pdo->query("SELECT COUNT(*) FROM oportunidad WHERE is_latest=1")->fetchColumn();
echo "Total: $total, is_latest=1: $latest\n";
'
```

### Salida esperada del dry-run

```
=== REPAIR OPORTUNIDAD VERSIONING ===
Total oportunidades: 2834
Families with >1 version: <N>
Repaired: <M>
Already OK: <K>
```

---

## Procedimiento: Reasignar Entidades a Comercial (filtrado por fecha)

**Cuándo usar:** Para reasignar entidades a un comercial (user_id) específico, en base a la fecha de las OPORTUNIDADES (no de la asignación). Útil cuando un comercial se fue y sus entidades activas necesitan transferirse a otro.

### Tabla involucrados

- `entidad_usuario` — pivote (PK: `entidad_id` + `usuario_id`)
- `oportunidad` — filtra por `fecha`
- `entidad` — JOIN intermedio

### Preview (revisar antes de aplicar)

```sql
-- Ver entidades con opps en 2026 y su comercial actual
SELECT 
    eu.entidad_id,
    e.nombre AS entidad,
    eu.usuario_id AS comercial_actual,
    (SELECT COUNT(*) FROM oportunidad o WHERE o.entidad_id = eu.entidad_id AND o.fecha >= '2026-01-01') AS opps_2026,
    MAX(o.fecha) AS ultima_opp
FROM entidad_usuario eu
JOIN entidad e ON e.id = eu.entidad_id
JOIN oportunidad o ON o.entidad_id = eu.entidad_id
WHERE o.fecha >= '2026-01-01'
GROUP BY eu.entidad_id, eu.usuario_id, e.nombre
ORDER BY opps_2026 DESC
LIMIT 100;

-- Conteo de entidades únicas
SELECT COUNT(DISTINCT eu.entidad_id) AS entidades_unicas_2026
FROM entidad_usuario eu
JOIN oportunidad o ON o.entidad_id = eu.entidad_id
WHERE o.fecha >= '2026-01-01';
```

### UPDATE

```sql
-- BACKUP
CREATE TABLE entidad_usuario_backup_reasign_opps_2026 AS
SELECT DISTINCT eu.*
FROM entidad_usuario eu
JOIN oportunidad o ON o.entidad_id = eu.entidad_id
WHERE o.fecha >= '2026-01-01';

-- Verificar
SELECT COUNT(*) AS backup_rows FROM entidad_usuario_backup_reasign_opps_2026;

-- UPDATE: cambiar comercial actual → user 2
UPDATE entidad_usuario eu
JOIN oportunidad o ON o.entidad_id = eu.entidad_id
SET eu.usuario_id = 2,
    eu.updated_at = NOW()
WHERE o.fecha >= '2026-01-01'
  AND eu.usuario_id != 2;
```

### Verificar

```sql
SELECT usuario_id, COUNT(*) AS entidades
FROM entidad_usuario
GROUP BY usuario_id
ORDER BY entidades DESC;
```

### Revertir

```sql
UPDATE entidad_usuario eu
JOIN entidad_usuario_backup_reasign_opps_2026 b 
    ON eu.entidad_id = b.entidad_id
    AND eu.usuario_id = 2
SET eu.usuario_id = b.usuario_id,
    eu.updated_at = NOW()
WHERE b.usuario_id != eu.usuario_id;

DROP TABLE entidad_usuario_backup_reasign_opps_2026;
```

### Variantes

- **Cambiar un comercial específico** (no todos): agregar `AND eu.usuario_id = COMERCIAL_ORIGEN_ID`
- **Por rango de fechas**: `AND o.fecha BETWEEN '2024-01-01' AND '2024-12-31'`
- **Por entidad**: `AND eu.entidad_id IN (10, 20, 30)`

---

## Procedimiento: Reasignar Oportunidades a otra Entidad (bulk)

**Cuándo usar:** Para reasignar muchas oportunidades de una o varias entidades a una entidad destino, filtrando por fecha o por entidades origen.

### Preview

```sql
SELECT 
    o.id,
    o.codigo,
    o.entidad_id AS entidad_actual,
    e.nombre AS nombre_actual,
    o.fecha,
    o.valor,
    o.estado
FROM oportunidad o
LEFT JOIN entidad e ON e.id = o.entidad_id
WHERE o.entidad_id IN (ENTIDADES_ORIGEN_IDS)            -- ej: 100,200,300
  AND o.entidad_id IS NOT NULL
  AND o.fecha >= 'FECHA_DESDE'                         -- ej: '2024-01-01'
  AND o.fecha <= 'FECHA_HASTA'                         -- ej: '2024-12-31'
ORDER BY o.id DESC
LIMIT 100;

-- Conteo
SELECT COUNT(*) AS total_a_modificar
FROM oportunidad
WHERE entidad_id IN (ENTIDADES_ORIGEN_IDS)
  AND fecha >= 'FECHA_DESDE'
  AND fecha <= 'FECHA_HASTA';
```

### UPDATE

```sql
-- BACKUP
CREATE TABLE oportunidad_backup_reasign_fecha AS
SELECT * FROM oportunidad
WHERE entidad_id IN (ENTIDADES_ORIGEN_IDS)
  AND fecha >= 'FECHA_DESDE'
  AND fecha <= 'FECHA_HASTA';

-- Aplicar
UPDATE oportunidad
SET entidad_id = ENTIDAD_DESTINO_ID,
    updated_at = NOW()
WHERE entidad_id IN (ENTIDADES_ORIGEN_IDS)
  AND fecha >= 'FECHA_DESDE'
  AND fecha <= 'FECHA_HASTA';
```

### Revertir

```sql
UPDATE oportunidad o
INNER JOIN oportunidad_backup_reasign_fecha b ON o.id = b.id
SET o.entidad_id = b.entidad_id,
    o.updated_at = NOW()
WHERE b.entidad_id != o.entidad_id;

DROP TABLE oportunidad_backup_reasign_fecha;
```

---

## Procedimiento: Validar y Aplicar Cleanup-v3 (checklist prod)

**Cuándo usar:** Cada vez que se ejecute el cleanup-v3.php en prod. Lista de verificación paso a paso.

### 1. Pre-cositas

```bash
CID=$(docker ps --filter "ancestor=easypanel/prod/crm-back:latest" -q)
DBPW='Tis_innovation_1'
```

### 2. Backup completo

```bash
docker exec -e DBPW=$DBPW $CID php -r '
$pdo = new PDO("mysql:host=prod_mariabd;dbname=crm_prod", "sailusdb", getenv("DBPW"));
$pdo->exec("DROP TABLE IF EXISTS cleanup_proposed_changes_backup");
$pdo->exec("CREATE TABLE cleanup_proposed_changes_backup AS SELECT * FROM cleanup_proposed_changes");
$pdo->exec("DROP TABLE IF EXISTS entidad_backup_pre_cleanup");
$pdo->exec("CREATE TABLE entidad_backup_pre_cleanup AS SELECT * FROM entidad");
$pdo->exec("DROP TABLE IF EXISTS oportunidad_backup_pre_cleanup");
$pdo->exec("CREATE TABLE oportunidad_backup_pre_cleanup AS SELECT * FROM oportunidad");
$pdo->exec("DROP TABLE IF EXISTS contacto_backup_pre_cleanup");
$pdo->exec("CREATE TABLE contacto_backup_pre_cleanup AS SELECT * FROM contacto");
echo "OK\n";
'
```

### 3. Dry-run

```bash
docker exec -e DBPW=$DBPW $CID php /var/www/html/database/fixes/cleanup-v3.php
```

Esto genera un reporte en `database/cleanup-reports/cleanup-pending-FECHA.txt` y puebla la tabla `cleanup_proposed_changes` con todas las propuestas en estado `pending`.

### 4. Revisar cantidades

```bash
docker exec -e DBPW=$DBPW $CID php -r '
$pdo = new PDO("mysql:host=prod_mariabd;dbname=crm_prod", "sailusdb", getenv("DBPW"));
foreach ($pdo->query("SELECT action_type, status, COUNT(*) c FROM cleanup_proposed_changes GROUP BY action_type, status ORDER BY action_type, status") as $r) {
    echo $r["action_type"]." (".$r["status"]."): ".$r["c"]."\n";
}
'
```

Esperado: ~970 propuestas en `pending` (681+12+3+248+26).

### 5. Rechazar lo que NO se debe aplicar

**Brinks** (grupo empresarial — cada subsidiaria es separada):

```bash
docker exec -e DBPW=$DBPW $CID php -r '
$pdo = new PDO("mysql:host=prod_mariabd;dbname=crm_prod", "sailusdb", getenv("DBPW"));
$pdo->exec("UPDATE cleanup_proposed_changes SET status = '\''rejected'\'', user_notes = '\''grupo Brinks - cada subsidiaria separada'\'' WHERE reason LIKE '\''%BRINKS%'\''");
echo "Brinks rechazados\n";
'
```

Otros criterios que aplicaron en dev:
- **POLICIA NACIONAL** cuando aparece consolidación
- **Batch cotización** consecutivo con `created_by` distintos

### 6. Aprobar el resto

```bash
docker exec -e DBPW=$DBPW $CID php -r '
$pdo = new PDO("mysql:host=prod_mariabd;dbname=crm_prod", "sailusdb", getenv("DBPW"));
$pdo->exec("UPDATE cleanup_proposed_changes SET status = '\''approved'\'' WHERE status = '\''pending'\''");
foreach ($pdo->query("SELECT status, COUNT(*) c FROM cleanup_proposed_changes GROUP BY status") as $r) {
    echo $r["status"].": ".$r["c"]."\n";
}
'
```

### 7. Aplicar

```bash
docker exec -e DBPW=$DBPW $CID php /var/www/html/database/fixes/cleanup-v3.php --apply --from-temp-table
```

Esperado:
```
opp_reasign: ~686
entity_create: 16
entity_consolidate: ~248
shell_delete: ~26
failed: 0
```

### 8. Resultado real (2026-07-24, prod)

```
opp_reasign:        686
entity_create:       16
entity_consolidate: 248
shell_delete:        26
failed:               0
```

Cero failures. Los Brinks (7 propuestas) quedaron en `pending` originalmente pero fueron rechazados.

---

## Procedimiento: Reasignar Entidad desde UI (post-cleanup)

**Cuándo usar:** Después del cleanup, cuando quedan opps mal asignadas que necesitan corrección sin tocar la BD directamente.

### Disponible en `dashboard-crm`

| Acción | Dónde | Para qué |
|--------|-------|----------|
| **Reasignar entidad a opp** (botón en sidebar Kanban) | Al abrir una opp en el CRM | Cambiar el `entidad_id` de una opp a otra entidad |
| **Modificar opp** (botón en cada tarjeta del Directorio) | Vista de detalle de una entidad | Cambiar la opp asociada: reasignar (swap) o agregar (mover) |

### Swap vs Mover

- **Mover**: la opp actual se queda donde está, agregás una nueva opp de otra entidad. La opp de origen se reasigna a esta entidad.
- **Swap**: intercambio directo. La opp actual va a la entidad de origen de la opp elegida, y viceversa.

Ambos flujos requieren confirmación con preview del cambio antes de aplicar.

### Backend usado

Ambas UI usan el endpoint:

```
PUT /api/v1/oportunidades/{id}
Body: { "entidad_id": <nuevo_id> }
```

Funciona con token Sanctum válido. Si este endpoint devuelve 500 con "Class AppModelsUsuario not found", revisar `config/auth.php` (ver Lecciones Aprendidas).

---

## Lecciones Aprendidas

### Migraciones
- **Nunca agregar columnas a una migración ya ejecutada** — el `php artisan migrate` la salta silenciosamente. Crear una migración nueva con sufijo timestamp posterior.
- **El container de EasyPanel resetea `/tmp` en cada reinicio** — usar rutas del proyecto (`/var/www/html/database/...`) en lugar de `/tmp/...` para scripts de datos.

### Datos
- **El `keyword fuzzy match` en el import causaba asignaciones erróneas** — "B2B TAX&LEGAL" matcheaba con "B2B Soluciones SAS" por la keyword "b2b". Eliminado en commit `cc44033`. Ahora solo match estricto por: dominio → nombre normalizado → nombre lowercase.
- **El `vr_total` debe incluir IVA y cantidad** — fórmula correcta: `(vr_unitario * cantidad) + iva`. Bug original calculaba solo `vr_unitario + iva`. Fix en commit `cc44033`. Aplicado en prod via script.
- **Las "shells" son entidades vacías post-merge** — siempre quedan después de MergeDuplicateEntitiesSeeder. Identificables como `dominio IS NULL AND 0 opps AND 0 contactos`. El script `reconcile-entities-sin-dominio.php` las limpia.
- **Las entidades sin dominio pero con contactos son reales** — el match se hace por nombre normalizado (sin sufijos S.A.S, LTDA, etc.).
- **La fuente de verdad es `oportunidades.csv`** — cada fila tiene la triple canónica (oportunidad + entidad + contacto). Las entidades son las que se desincronizan, no las opps ni los contactos.
- **El Stage 5 del cleanup debe correr en DOS pasadas** — el original (5a, "shells iniciales") no detectaba las entidades que quedaban vacías DESPUÉS de consolidaciones (5b). Fix: calcular `oppCount - reasignaciones - consolidaciones` en memoria.
- **En prod, el algoritmo 5b detecta 26 shells nuevos** — entidades que tenían opps/contactos al inicio, pero que las reasignaciones los dejaron vacías. Algunos nombres: BETA SERVICIOS TEMPORALES, HILVERDAFLORIST, MMT MANUFACTURA, etc. Todos `Estado=Activo`, `dominio=NULL`.

### Producción
- **El servidor SSH del VPS solo acepta publickey** — el scp desde PowerShell falla con "Permission denied (publickey)". Solución: pushear el script al repo y hacer EasyPanel redeploy (los archivos quedan en `/var/www/html/...` del container).
- **El container `crm-back` no tiene cliente `mysql`** — usar `php -r` con PDO para ejecutar SQL. No usar `docker exec ... mysql`.
- **El container `mariabd:11` tampoco tiene cliente `mysql` instalado** — usar el container `crm-back` con PHP para todo acceso a la BD.
- **El container `crm-back` no tiene `bash`** — solo `sh`. Usar `sh -c` o `php -r` directo. Si `bash -c` falla, no es error del comando, es que bash no está en el PATH.
- **Las credenciales del `.env` del container son la fuente de password DB** — `DB_USERNAME` (ej: `sailusdb`) y `DB_PASSWORD` (ej: `Tis_innovation_1`) están en `/var/www/html/.env` dentro del container.
- **La password quedó expuesta en el chat** — rotar después de cada deploy de cambios sensibles.
- **El config `auth.php` importa `App\Models\Usuario` (no `Modules\Shared\Models\Usuario`)** — bug pre-existente que rompía todos los PUT endpoints protegidos por Sanctum con "Class AppModelsUsuario not found". Si en prod pasa esto, revisar y corregir.
- **El script `cleanup-v3.php` usa `getenv()` directo, no Laravel `env()`** — no lee automáticamente `.env`. Los env vars deben estar en el shell del container (lo están por defecto en EasyPanel). Si querés mandar un PDO manual desde el container, pasá `DB_HOST`, `DB_NAME`, `DB_USER`, `DBPW` en el `docker exec -e`.

### Operación
- **Siempre correr `--dry-run` antes de aplicar** — el script de reconciliación, el de fix_detalle_from_csv.php y el cleanup-v3 generan reporte sin mutar.
- **Backup antes de cualquier UPDATE masivo** — `CREATE TABLE x_backup AS SELECT * FROM x` antes del script.
- **Las CSVs están dentro de la imagen Docker** — `database/csv/*.csv` se copia al container en build. No hace falta transferirlas al VPS.
- **El cleanup usa temp table `cleanup_proposed_changes`** — permite revisar y aprobar propuestas antes de aplicar, y preservar notas entre corridas.
- **Los IDs de entidades/opps/contactos NO matchean entre dev y prod** — no se puede llevar propuestas de dev a prod como copia. Hay que correr el dry-run en prod.
- **Para cargar scripts de fix al container** — usar `docker cp archivo.php CID:/var/www/html/database/fixes/` desde el host.
- **phpMyAdmin está disponible en prod** — `https://prod-phpmyadmin.jsvdny.easypanel.host` con la DB `crm_prod`. Útil para revisar tablas sin tocar el container.

### Frontend
- **Invalidar `['dashboard']` en cada mutación del Kanban** — sin esto, el dashboard muestra data vieja hasta los 60s del polling. Fix en `dashboard-crm` con 11 `queryClient.invalidateQueries({ queryKey: ['dashboard'] })`.
- **Para reasignar opps/contactos después del cleanup** — usar el modal `ReasignarOportunidadModal` (modos "mover" y "swap"). Disponible en cada tarjeta de opp del Directorio.
- **Para cambiar la entidad de una opp desde el Kanban** — usar el campo "Reasignar a otra entidad" en el sidebar del detalle. Solo buscar y seleccionar.
- **SQL puro para phpMyAdmin** — para reasignar comercial por fecha, usar `UPDATE entidad_usuario eu JOIN oportunidad o ON o.entidad_id = eu.entidad_id SET eu.usuario_id = ? WHERE o.fecha >= '2026-01-01'`. Filtrar por `created_at` de la asignación no es lo mismo que por `fecha` de la opp.

---

## Cambios recientes (changelog)

### 2026-07-24 — Cleanup v3 ejecutado en prod
- Ejecutado `cleanup-v3.php` en prod: 686 opp_reasign, 16 entity_create, 248 entity_consolidate, 26 shell_delete, 0 failed.
- Brinks rechazados (7 propuestas) por ser grupo empresarial.
- Cero errores de aplicación.

### 2026-07-24 — Modal `ReasignarOportunidadModal` agregado
- Permite reasignar opps entre entidades con dos modos: "mover" (agrega) y "swap" (intercambia).
- Confirmación con preview del cambio antes de aplicar.
- Disponible en cada tarjeta de opp del Directorio.

### 2026-07-24 — Kanban: reasignar entidad a opp
- Nuevo campo "Reasignar a otra entidad" en el sidebar del detalle de oportunidad.
- Usa `EntidadSearchSelect` con búsqueda.

### 2026-07-16 — Componentes `EntidadSearchSelect` y `OportunidadSearchSelect`
- Dropdowns de búsqueda reutilizables.
- Debounce 250ms, click-outside para cerrar.

### 2026-07-16 — Cleanup-v3: Stage 5a + 5b
- Stage 5 original (un solo pass) no detectaba shells que quedaban vacías DESPUÉS de consolidaciones.
- Split en 5a (shells iniciales) + 5b (post-movimiento, calculado en memoria).
- 1081 aplicadas en dev, 0 pendientes.

### 2026-07-16 — Fix bug auth.php
- Pre-existente: usaba `Modules\Shared\Models\Usuario` (no existe).
- Corregido a `App\Models\Usuario`.
- Sin esto, todos los PUT endpoints protegidos por Sanctum tiraban 500.

---

# para el manejo de Contactos duplicados
En prod ya podés correr
# Diagnóstico (read-only, sin tocar nada)
docker exec crm-laravel-prod php artisan crm:diagnosticar-dominios

# Simular limpieza (read-only)
docker exec crm-laravel-prod php artisan crm:limpiar-duplicados-contactos --dry-run

# Ejecutar (con backup previo en el host)
docker exec crm-laravel-prod mysqldump -u root -p minerva contacto > backup_contacto_$(date +%Y%m%d).sql
docker exec crm-laravel-prod php artisan crm:limpiar-duplicados-contactos --apply

*Actualizado: 2026-08-04*