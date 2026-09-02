# Tasks: ERP Unification Phase 1

- `[x]` **1. Instalación Base:**
  - Instalar el paquete `nwidart/laravel-modules` vía Composer en el repositorio `crm-laravel`.
  - Publicar el archivo de configuración `config/modules.php`.
  - Actualizar `composer.json` para añadir el namespace `Modules\\` al PSR-4 autoload.

- `[x]` **2. Generación de Módulos:**
  - Crear módulo `Shared` (`php artisan module:make Shared`).
  - Crear módulo `CRM` (`php artisan module:make CRM`).
  - Crear módulo `Proyectos` (`php artisan module:make Proyectos`).

- `[x]` **3. Migración Módulo Shared:**
  - Mover Modelos, Migraciones y Controladores relacionados a la Autenticación y Usuarios (`User`, `Role`, `Organization`) hacia `Modules/Shared/Entities` y rutas `/api/v1/auth`.
  - Actualizar los namespaces de todas las importaciones afectadas.

- `[x]` **4. Migración Módulo CRM:**
  - `[x]` Mover la lógica actual del CRM (`Lead`, `Opportunity`, `Pipeline`) hacia `Modules/CRM/Entities` (mapeado a `Modules/CRM/app/Models`).
  - `[x]` Mover `ContactoController` y `WebhookController` hacia `Modules/CRM/Http/Controllers` (mapeado a `Modules/CRM/app/Http/Controllers`).
  - `[x]` Asegurar retrocompatibilidad: Registrar explícitamente las rutas `/api/v1/contacto` y `/api/v1/webhook/registration` dentro del archivo de rutas del módulo CRM.

- `[x]` **5. Migración Módulo Administrativo:**
  - `[x]` Mover Modelos, Migraciones y Controladores relacionados a Finanzas/Operaciones (`LugarEntidad`, `Movimiento`, `OrdenServicio`, `Servicio`, `DetalleServicio`, `Colaborador`, `Cuenta`, `Proveedor`) a `Modules/Administrativo/Entities` (mapeado a `Modules/Administrativo/app/Models`).
  - `[x]` Registrar las correspondientes rutas y controladores bajo `/api/v1/administrativo`.
  - `[x]` Actualizar namespaces e importaciones.

- `[x]` **6. Soporte para PDFs (Excepción Blade):**
  - `[x]` Asegurar que la configuración de vistas compartidas (`resources/views`) siga disponible para el uso de Blade al momento de generar Cotizaciones y Documentos PDF.

- `[x]` **7. Pruebas de Humo:**
  - `[x]` Ejecutar tests existentes para validar que el endpoint de validación de API keys (`/auth/validate-key`) y el de registro de webhooks funcionen igual que antes de la reestructuración.
