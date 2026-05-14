# Memory - CRM Laravel (Tecnoinnsoft)

## Goal
CRM modular con bounded contexts: CRM Core, Operaciones, Finanzas

## What Was Done
- Proyecto Laravel 12 + PHP 8.2 + Tailwind CSS 4 + Vite 7
- Clean Architecture: Domain (Entities+Repos) → Application (UseCases) → Infrastructure → Interfaces
- API completo con Sanctum: auth, RBAC, webhooks entrantes
- Módulos CRM Core (Entidad, Contacto, Oportunidad, Seguimiento)
- Módulo Operaciones (Servicio, DetalleServicio, OrdenServicio, Proveedor, Colaborador)
- Módulo Finanzas (Cuenta, Movimiento)
- Seeds de prueba y usuarios de test

## Key Discoveries
- Laravel 12 con Vite 7 y Tailwind 4 (stack moderno)
- API pública webhook (`/api/v1/webhook/registration`) + auth endpoints
- Todos los módulos juntos en un monolito Laravel bien modularizado
- RBAC con middleware `rbac` sobre Sanctum
- Webhooks HMAC-SHA256 de salida hacia FastAPI
- Middleware de API key (`X-API-Key`) para integración FastAPI

## Architecture State
```
app/
├── Domain/Entities+Interfaces    # Pure domain, no framework deps
├── Application/UseCases+Services # Business logic
├── Infrastructure/Persistence   # Eloquent repos
└── Interfaces/
    ├── Http/Controllers+Resources  # Thin controllers, API Resources
    └── Console/Commands
```

## Pending (modularizacion)
- [ ] Reorganizar `Http/Resources/` en subcarpetas por contexto (CRM/Operaciones/Finanzas)
- [ ] Reorganizar `Http/Controllers/` análogos
- [ ] Group controllers similarly if needed

## How to Proceed
1. Reorganizar Resources en subcarpetas por bounded context
2. Actualizar referencias de routes/api.php
3. Consolidar cualquier lógica compartida

## Relevant Files
- `routes/api.php` — todos los endpoints
- `app/Http/Resources/` — API Resources (flattened, needs grouping)
- `app/Domain/Entities/` — pure domain entities
- `app/Application/UseCases/` — business logic by entity