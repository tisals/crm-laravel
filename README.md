# CRM Laravel — Tecnoinnsoft

API REST para CRM interno. Consumido por [dashboard-crm](../dashboard-crm) (React) y SAIlus (FastAPI).

---

## 🐳 Docker — Desarrollo Local

```bash
# Build + up (primera vez ~3 min, siguientes ~20s)
docker compose -f docker-compose.dev.yml up -d --build

# Probar health
curl http://localhost:8001/api/v1/health
```

### ⚠️ Base de datos: MariaDB (no SQLite)

Históricamente usábamos SQLite, pero **generaba errores de concurrencia** (`database is locked`) al ejecutar múltiples consultas desde las pantallas del frontend. MariaDB maneja concurrencia real sin bloqueos.

En desarrollo local, MariaDB corre como contenedor Docker. El `.env` del host apunta a `DB_HOST=mariadb` — **solo funciona dentro de la red de Docker**. Si necesitás correr comandos de Artisan desde el host, usá `docker exec`:

```bash
docker exec crm-laravel-dev php artisan <comando>
```

### Primera vez (DB fresh + seed completa)

```bash
docker exec crm-laravel-dev php artisan migrate:fresh --seed
# En prod/staging correr seed completos:

docker ps # extrae el id_contenedor
docker exec -it id_contenedor php artisan migrate:fresh --seed --force--force
```

**Esto corre (en orden):**

| Seeder | Qué crea |
|--------|----------|
| `RoleSeeder` | Roles: Admin, Ventas, Operaciones, Finanzas |
| `PermisoSeeder` | Permisos por módulo |
| `CiudadSeeder` | 1.122 ciudades colombianas |
| `BrandPermissionsSeeder` | Admin + entidades Tecnoinnsoft/Deseguridad + vínculo |
| `RealDataSeeder` | — Maestros (28 rows), Entidades (141), Contactos (245), Productos (35), **Oportunidades (3.498)** |
| `DetalleOportunidadCsvSeeder` | 3.601 detalles desde CSV real |

La seed completa tarda ~2 minutos (la mayor parte en oportunidades).

### Reset rápido (sin reseed)

```bash
docker exec crm-laravel-dev rm storage/framework/.migrated
docker restart crm-laravel-dev
```

El entrypoint corre `migrate --seed` automático si detecta que no hay migraciones ejecutadas.

### Prueba rápida brands endpoint

```bash
TOKEN=$(docker exec crm-laravel-dev php artisan tinker --execute='echo App\Models\Usuario::first()->createToken("test")->plainTextToken')
curl -H "Authorization: Bearer $TOKEN" http://localhost:8001/api/v1/users/1/brands
```

→ `{"success":true,"data":{"brand_permissions":["tecnoinnsoft.com","deseguridad.net"]}}`

---

## ⚙️ Brand Permissions (SAIlus)

**Endpoint:** `GET /api/v1/users/{crm_user_id}/brands`
**Auth:** `Bearer {Sanctum token}`

Relación: `usuario → entidad_usuario (pivot) → entidad (estado = 'Propia')`

Las entidades con `estado = 'Propia'` son marcas internas.
`entidad.dominio` se usa como brand_key.

### Crear usuario + marcas manualmente

```bash
docker exec -it crm-laravel-dev php artisan tinker
```

```php
$u = App\Models\Usuario::firstOrCreate(
    ['email' => 'admin@tecnoinnsoft.dev'],
    ['nombre' => 'Admin', 'password_hash' => bcrypt('password'), 'rol_id' => 1, 'estado' => 'Activo']
);
$e1 = App\Models\Entidad::firstOrCreate(
    ['identificacion' => '900000001-0'],
    ['tipo_persona' => 'Juridica', 'tipo_id' => 'NIT', 'nombre' => 'Tecnoinnsoft', 'dominio' => 'tecnoinnsoft.com', 'estado' => 'Propia']
);
$e2 = App\Models\Entidad::firstOrCreate(
    ['identificacion' => '900000002-0'],
    ['tipo_persona' => 'Juridica', 'tipo_id' => 'NIT', 'nombre' => 'Deseguridad.dev', 'dominio' => 'deseguridad.net', 'estado' => 'Propia']
);
$u->entidades()->syncWithoutDetaching([$e1->id, $e2->id]);
```

---

## 🧪 Tests

```bash
# ✅ Hacer siempre desde Docker (nunca desde el host — apunta a MariaDB real)
docker exec crm-laravel-dev php artisan test --filter BrandPermissionsTest
```

> ⚠️ **No correr `php artisan test` desde el host**: el `.env` del host apunta a MariaDB y `RefreshDatabase` borraría datos reales.

---

## 🏗️ Migraciones clave agregadas

| Migración | Cambio |
|-----------|--------|
| `2026_05_27_115300_update_detalle_oportunidad_columns` | `concepto` → `text`, `medida` → `varchar(20)` (soportaba 255/10 chars, ahora 65535/20) |

---

## 🗂️ Archivos clave del deployment

| Archivo | Propósito |
|---------|-----------|
| `Dockerfile` | Multi-stage: Composer → PHP-FPM + Nginx |
| `nginx.conf` | Laravel config para Nginx (PHP-FPM via TCP 9000) |
| `docker-entrypoint.sh` | Espera MariaDB → key:generate → migrate --seed → PHP-FPM → Nginx |
| `docker-compose.dev.yml` | PHP-FPM + Nginx + MariaDB, puerto 8001 |
| `.dockerignore` | Excluye vendor/node_modules/.git del build |

---

## 📦 Stack

- **Backend:** Laravel 12, PHP 8.2, **MariaDB** (dev y prod)
- **Auth:** Laravel Sanctum (token-based)
- **Arquitectura:** Clean Architecture (Domain → Application → Infrastructure → Interfaces)
- **Frontend:** React 18 + Vite (repositorio separado: `../dashboard-crm`)
- **PDF:** barryvdh/laravel-dompdf (cotizaciones) — requiere `composer install` en el host si se necesita local
