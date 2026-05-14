# AGENTS.md — CRM Laravel (Tecnoinnsoft)

High-signal context for agents working in this repo. If a fact is obvious from filenames or standard Laravel docs, it is omitted.

## What this repo actually is

API-first CRM backend for Tecnoinnsoft. Receives webhook registrations from a diagnostic tool, stores contacts / organizations, manages service licenses, and sends HTML email reports. Designed to be consumed by a separate FastAPI frontend service.

## Stack (verified from manifests)

- **Backend**: Laravel 12.x, PHP ^8.2
- **API Auth**: Laravel Sanctum ^4.3 (token-based, not session-based)
- **Frontend**: Minimal Blade welcome page only (no SPA). Vite 7 + Tailwind CSS 4 + Axios
- **Database**: SQLite in local dev (`database.sqlite`); MariaDB in staging / production
- **Mail**: `log` driver default; `RegistrationReport` mailable sends diagnostic HTML emails (not PDFs)
- **Queue / Cache / Session**: All default to `database` driver
- **Locale**: `es` (Spanish); Faker locale `es_ES`

## Architecture & entrypoints

- **HTTP entry**: `public/index.php` → `bootstrap/app.php`
- **API routes**: `routes/api.php` → controllers live in `App\Http\Controllers\API\`
- **Web routes**: `routes/web.php` → only `/` returning `welcome` view
- **Console routes**: `routes/console.php`
- **Custom command**: `crm:generate-token` (`app/Console/Commands/GenerateApiToken.php`) — generates Sanctum token for FastAPI integration
- **Architecture**: Clean Architecture (4 layers) — Domain → Application → Infrastructure → Interfaces
  - `App\Domain\Entities\` — pure PHP entities, no framework deps
  - `App\Domain\Repositories\` — repository interfaces (contracts)
  - `App\Application\UseCases\` — business logic orchestration
  - `App\Infrastructure\Persistence\` — Eloquent repository implementations
  - `App\Infrastructure\Webhook\` — outbound webhooks (HMAC-SHA256, queued)
  - `App\Infrastructure\Auth\` — `ValidateApiKeyMiddleware`
  - `App\Http\Controllers\API\` — thin controllers, only HTTP concerns
  - `App\Http\Requests\` — Form Request validation classes
  - `App\Http\Resources\` — API Resources for response shaping

### Domain models (the ones that matter)

| Model | Purpose |
|-------|---------|
| `Contact` | Leads from webhook; has `diagnostico_data` (JSON), `dominante_axis`, `presupuesto_range` |
| `Organization` | Companies; status enum: `prospecto`, `cliente`, `inactivo` |
| `OrganizationService` | License mgmt per org+service; fields: `max_users`, `active_users`, `license_status`, `trial_ends_at` |
| `Plan` | Pricing tiers; public endpoint returns only `is_active=true` ordered by `sort_order` |
| `Tag` | Auto-tagged on webhook via `firstOrCreate` (e.g. `diagnostico-completado`, `eje-tecnico`) |
| `User` | Only for Sanctum token generation; no regular auth UI |

## API route conventions

All API routes are prefixed with `v1`.

- **Public** (no auth): `GET /api/v1/plans`, `POST /api/v1/webhook/registration`
- **Protected** (`auth:sanctum` or `X-API-Key`):
  - `GET /api/v1/auth/validate-key` — validates API key, returns `{ valid, organization_id, permissions }`
  - contacts, organizations, services (`/api/v1/services/*`)
  - `PUT /api/v1/services/{id}/status` — update service status, returns `{ success, data: { id, status, previous_status } }`

All JSON responses are wrapped in a normalized envelope: `{ success: bool, data?: ..., message?: ..., error?: ... }`.

## Exact commands

### One-shot setup (new machine)
```bash
composer setup    # runs: install, copy .env, key:generate, migrate, npm install, npm run build
```

### Development
```bash
composer dev                          # concurrently: artisan serve, queue:listen, pail, npm run dev
php artisan serve --port=8001        # backend on port 8001 (FastAPI default)
npm run dev                          # Vite HMR only
```

### Testing
```bash
composer test     # config:clear + artisan test (PHPUnit)
php artisan test  # PHPUnit directly
```

### Database
```bash
php artisan migrate:fresh --seed   # full reset with DatabaseSeeder (minimal: only test user)
php artisan db:seed --class=TestDataSeeder        # realistic plans + org + contacts
php artisan db:seed --class=TestDataAdvancedSeeder
```

### Generate FastAPI token
```bash
php artisan crm:generate-token --email=crm@tecnoinnsoft.dev
```

## CI / CD

### GitHub Actions workflow
File: `.github/workflows/ci.yml`

Runs on every push to `main` and every pull request:
1. Sets up PHP 8.2 + Node 20
2. Installs Composer and NPM dependencies
3. Builds Vite assets
4. Runs migrations on SQLite `:memory:`
5. Runs `php artisan test`
6. **If tests pass on a PR** — posts a comment: "✅ Tests passed — ready for EasyPanel deploy"
7. **If push to main** — can trigger EasyPanel webhook (set `EASY_PANEL_WEBHOOK` secret in repo settings)

### EasyPanel auto-deploy setup
To enable automatic deploys via EasyPanel:
1. Go to your EasyPanel dashboard → project settings → deploy hooks
2. Copy the webhook URL
3. In GitHub repo → Settings → Secrets and variables → Actions → New repository secret
4. Name: `EASY_PANEL_WEBHOOK`, Value: your webhook URL
5. Uncomment the curl step in `.github/workflows/ci.yml`

## Testing quirks

- **Framework**: PHPUnit ^11.5.50 configured in `phpunit.xml`
- **Test DB**: SQLite `:memory:` (forced in `phpunit.xml`)
- **Trait**: `RefreshDatabase` used in Feature tests
- **Auth in tests**: Tests create a User via factory and call `$user->createToken('test-token')->plainTextToken`; set token in `Authorization: Bearer <token>` header
- **Canonical Feature test**: `tests/Feature/API/PlanControllerTest.php` — use it as the template for new API tests
- **Unit tests exist** for Domain entities and Infrastructure repositories (`tests/Unit/Domain/`, `tests/Unit/Infrastructure/`)

## Tailwind CSS 4 — critical config difference

There is **no `tailwind.config.js`**. Configuration is CSS-based in `resources/css/app.css`:

```css
@import 'tailwindcss';
@source '../../vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php';
@source '../../storage/framework/views/*.php';
@source '../**/*.blade.php';
@source '../**/*.js';

@theme {
  --font-sans: 'Instrument Sans', ui-sans-serif, system-ui, sans-serif, ...;
}
```

If you add new template paths, use `@source` directives — do not create a `tailwind.config.js`.

## Environment & secrets

- **Never commit `.env`** — it contains DB credentials and `APP_KEY`
- **Mail**: Default driver is `log`. For production, set `MAIL_MAILER`, `MAIL_HOST`, `MAIL_FROM_ADDRESS`
- **Sanctum**: Token prefix and expiration controlled in `config/sanctum.php`
- **DB**: Default connection `sqlite` (local dev); MariaDB config ready with fallback DB name `tecnoinnsoft_crm`

## Gotchas

1. **No regular login/register UI** — auth is purely Sanctum tokens. Do not assume session auth.
2. **Webhook is idempotent** — uses `Contact::updateOrCreate(['email' => ...])`
3. **Auto-tagging happens inside webhook** — tags are created on-the-fly with `firstOrCreate`; do not assume they exist in seeder
4. **License enforcement** — `OrganizationService::canAddUser()` checks `max_users` vs `active_users`; returns `true` when `max_users` is `null` (unlimited)
5. **Schema name field** — `organization_services.schema_name` is reserved for FastAPI multi-tenant logic; currently nullable
6. **Mail is synchronous in webhook** — `WebhookController` calls `Mail::send()` directly, not `queue()`, so email sending blocks the webhook response despite the mailable using the `Queueable` trait
7. **Controllers are thin now** — all business logic lives in `App\Application\UseCases\`; do NOT add Eloquent queries directly in controllers
8. **Welcome view** — `resources/views/welcome.blade.php` uses Tailwind v4 classes; editing it requires rebuilding with `npm run build`
9. **Default dev port** — backend runs on port 8001 (`APP_URL=http://localhost:8001`); FastAPI integration expects this port

## Files that explain how the system is wired

| File | Why read it |
|------|-------------|
| `routes/api.php` | All API endpoints and middleware groups |
| `app/Http/Controllers/API/WebhookController.php` | Webhook payload handling, auto-tagging, mail firing |
| `app/Models/OrganizationService.php` | License logic (`canAddUser`, scopes) |
| `database/seeders/TestDataSeeder.php` | Canonical seed data for local testing |
| `tests/Feature/API/PlanControllerTest.php` | Canonical test pattern (RefreshDatabase + Sanctum token) |
| `app/Console/Commands/GenerateApiToken.php` | How the FastAPI integration token is created |
| `app/Http/Controllers/API/OrganizationServiceController.php` | License mgmt endpoints (increment/decrement users) |
| `app/Infrastructure/Auth/ValidateApiKeyMiddleware.php` | API key validation for FastAPI integration |
| `app/Infrastructure/Webhook/CrmWebhookSender.php` | Outbound webhooks to FastAPI with HMAC-SHA256 |
| `resources/css/app.css` | Tailwind v4 entrypoint and `@source` directives |

## Docker dev (fast)

```bash
docker compose -f docker-compose.dev.yml up -d   # ~20s cached build
docker exec crm-laravel-dev php artisan tinker   # shell interactivo
```

**Stack:** PHP 8.2 FPM + Nginx via `php:8.2-fpm-alpine`. Multi-stage Dockerfile:
- Stage 1: Composer (capa cacheable si `composer.lock` no cambia)
- Stage 2: `install-php-extensions` (pre-compilado, rápido) + `apk add nginx`

**Entrypoint:** key:generate → migrate --seed → PHP-FPM → Nginx foreground

**Reset DB:**
```bash
docker exec crm-laravel-dev rm storage/framework/.migrated
docker restart crm-laravel-dev
```

## Brand Permissions (SAIlus)

**Endpoint:** `GET /api/v1/users/{id}/brands` — marca `Propia` como entidad interna.
**Relación:** `usuario → entidad_usuario (pivot) → entidad (estado = 'Propia')`
**brand_key:** `entidad.dominio` (ej: tecnoinnsoft.com, deseguridad.net)

Seed: `BrandPermissionsSeeder` (corre vía `DatabaseSeeder` o manual con `--class`).

## ICS Export

`GET /seguimientos/{id}/ics` — seguimiento individual
`GET /seguimientos/calendar.ics?mes=2026-05` — calendario mensual pendientes

## Seguimiento (ContactoAcción)

`POST /contacto/{id}/acciones` — registra acción (Llamada/Correo/Reunion/Nota)
  + opcional `fecha` + `hora` para programar próximo seguimiento
  + notifica admins vía `FollowUpNotification`
