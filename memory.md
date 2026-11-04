# Memory — CRM Laravel Session

## Goal
Levantar el stack de Minerva después del traslado del proyecto a `D:\desarrollo\Minerva\`: el Front (`minerva-dashboard`) y el Back (`minerva-backend`) no arrancaban.

## What Was Done
- ✅ **Front**: `minerva-dashboard` recreado sobre la ruta actual → Vite 5.4.21, HTTP 200 en `http://localhost:5174/`
  - Causa: los bind mounts apuntaban a la ruta vieja (`D:\sitios desarrollo\Minerva\...`), que Docker Desktop creó como carpeta vacía → `/app` sin `package.json` → ENOENT
  - Volumen `/app/node_modules` cambiado de anónimo a nombrado `dashboard-crm_node_modules` (`external: true`, pre-sembrado)
- ✅ **Back**: `minerva-backend` recreado → php-fpm + nginx, HTTP 200 en `/api/v1/plans` y `/up`
  - Causa: mismo stale-mount → `vendor/` vacío → `require(vendor/autoload.php)` mataba el entrypoint antes de levantar PHP-FPM
- ✅ **Bug independiente en `docker-compose.override.yml`**: el guard "skip migrate" nunca funcionó (compose v2 interpoló `\$pdo` → parse error de PHP → `TABLE_COUNT` vacío → siempre corría `migrate --force`). Reescrito con `getenv()` + `DATABASE()`, sin `$` que escapar. Verificado: **42 tablas**
- ✅ Verificado que NO hay migraciones pendientes (81/81 aplicadas) y que la DB `minerva` quedó intacta
- ✅ Restaurados 6 contenedores que tumbó el `docker desktop restart`; los 10 del stack quedan Up

## Key Discoveries
- **Stale bind mounts no fallan**: Docker Desktop crea el path del host faltante como carpeta vacía → el container arranca con `/app` o `/vendor` vacíos y el error apunta a un archivo, no al mount
- **Seeding de volumen anónimo patológico**: Docker Desktop re-siembra desde la imagen a ~3 MB/min (247 MB nunca terminan); la misma copia dentro de un container tarda ~50 s → usar volumen nombrado pre-sembrado
- **Contenedor fantasma**: matar el CLI de compose a mitad del create deja el nombre reservado en el daemon; `ps`/`inspect`/`rm` no lo ven. Solo `docker desktop restart` lo libera (y tumba el resto con restart policy, hay que levantarlos a mano)
- **Compose v2 se come `$var`** en strings inline de entrypoint → usar `getenv()` en vez de variables PHP
- **La imagen `minerva-crm` (1-oct) tiene un `nginx.conf` MÁS NUEVO que el repo** (`/up`, `@laravel`, bloquea `/index.php`) y no está bind-montado → **no rebuild**, se perdería ese hardening. `composer.lock` es idéntico host vs imagen
- **Cold start >25s**: el primer request tras el boot tarda (disk-sleep de bind mounts en Windows) y nginx loguea `499`; calentado responde en ~1s. No es el servidor caído
- **Red `sailus-shared`**: el alias `mariadb` existe en `mercurio-mariadb-dev` → `DB_HOST=mariadb` resuelve
- `docker run <img> php -r ...` NO ejecuta php: el ENTRYPOINT de la imagen gana → usar `--entrypoint php`

## Files Created/Modified
- `../dashboard-crm/docker-compose.dev.yml` — volumen nombrado + bootstrap documentado en el header (**modificado, sin commitear**)
- `docker-compose.override.yml` — snippet `TABLE_COUNT` corregido (**archivo NO trackeado por git**)
- Engram: 3 observaciones + session summary (proyecto `dashboard-crm`)

## How to Proceed
1. Decidir el commit de los 2 compose (el override del back no está trackeado)
2. Borrar las carpetas cáscara `D:\sitios desarrollo\Minerva\{dashboard-crm,crm-laravel}` (0 archivos, las creó Docker)
3. `janus-frontend` (otro proyecto) sigue con ENOENT `/app/package.json` desde el 28/10
4. Pendientes del trabajo de CSV (siguen abiertos): migrar `concepto`→`text` y `medida`→`varchar(20)` en `detalle_oportunidad`; validar en CRMPage; revisar las 708 ops sin detalle
5. **NO ejecutar `php artisan test`** contra MariaDB (conecta a la DB real, no a SQLite)

## Lecciones Aprendidas
- Cuando un container "no encuentra" un archivo, primero `docker inspect --format '{{json .Mounts}}'` y compará cada `Source` contra el disco real
- Un `restart: unless-stopped` no sobrevive a un restart del engine si el container muere durante el arranque: hay que levantarlo a mano
- Antes de un `--build`, compará imagen vs working tree: la imagen puede tener config más nueva que el repo
