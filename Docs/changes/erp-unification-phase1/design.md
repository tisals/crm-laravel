# Technical Design: ERP Unification Phase 1 (Modular Monolith)

## 1. Architecture Overview
El objetivo es transformar el proyecto actual `crm-laravel` en un Monolito Modular (ERP) que albergue tanto la lógica del CRM como la del Gestor de Proyectos, compartiendo la misma base de datos y autenticación. 
Se utilizará el paquete estándar de la industria `nwidart/laravel-modules` para gestionar el ciclo de vida, migraciones y rutas de cada módulo de forma aislada.

## 2. Estructura de Directorios (Target State)
La aplicación pasará de tener una estructura monolítica plana (`app/Http/Controllers`, `app/Models`) a una estructura basada en dominios funcionales:

```text
crm-laravel/
├── Modules/
│   ├── Shared/
│   │   ├── Entities/       (User, Role, Organization)
│   │   ├── Database/       (Migraciones compartidas)
│   │   └── Providers/
│   │
│   ├── CRM/
│   │   ├── Entities/       (Lead, Opportunity, Pipeline)
│   │   ├── Http/Controllers/ (WebhookController, ContactoController)
│   │   ├── Actions/        (AssignScoreAction)
│   │   └── Routes/api.php  (Prefijo: /api/v1/crm)
│   │
│   ├── Proyectos/
│   │   ├── Entities/       (Task, Board, Seguimiento)
│   │   ├── Http/Controllers/
│   │   ├── Actions/        (CreateRescueTaskAction)
│   │   └── Routes/api.php  (Prefijo: /api/v1/proyectos)
│   │
│   └── Administrativo/
│       ├── Entities/       (LugarEntidad, Movimiento, OrdenServicio, Servicio, DetalleServicio, Colaborador, Cuenta, Proveedor)
│       ├── Http/Controllers/
│       └── Routes/api.php  (Prefijo: /api/v1/administrativo)
```

## 3. Patrones de Comunicación Interna
Para evitar el acoplamiento fuerte y el "código espagueti":
- **Lectura/Escritura Cruzada:** Un módulo NO DEBE hacer consultas directas a los modelos Eloquent de otro módulo.
- **Acciones (Action Pattern):** Si el módulo `CRM` necesita crear una tarea cuando un lead se enfría, debe instanciar y ejecutar una clase `Action` pública expuesta por el módulo `Proyectos` (Ej. `Modules\Proyectos\Actions\CreateTaskAction::execute($data)`).
- **Eventos (Event Sourcing/Listeners):** Módulos pueden emitir eventos genéricos (Ej. `LeadStatusChanged`) que otros módulos pueden escuchar de forma asíncrona.

## 4. Estrategia de Frontend y Rutas API
- La arquitectura de Frontend se mantendrá como SPA (Vue/React) desacoplada.
- **Excepción Blade (PDFs):** Aunque el sistema es una API REST, Laravel SÍ utilizará vistas Blade exclusivamente para la generación de plantillas internas (ej. formatos de cotización que luego se exportan a PDF). Fuera de esta excepción, no se renderizarán vistas públicas.
- Las rutas del módulo Shared manejarán la autenticación (`/api/v1/auth`).
- Las rutas estarán protegidas por Sanctum/JWT y prefijadas por módulo para evitar colisiones.

## 5. Riesgos y Mitigaciones
- **Riesgo:** Romper las integraciones actuales de SAIlus (FastAPI) al mover los controladores.
- **Mitigación:** Mantener las URLs de los webhooks exactamente iguales (`/api/v1/webhook/registration`, `/api/v1/contacto`) mapeándolas en las rutas del módulo CRM durante la migración, asegurando retrocompatibilidad al 100%.
