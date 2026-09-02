# Diccionario de Datos — CRM Tecnoinnsoft (SQLite / MariaDB)

Diccionario actualizado contra la estructura real de migraciones (40 migrations).
Base de datos relacional con convenciones Laravel (snake_case, timestamps, soft deletes).

> **Convenciones comunes** (a menos que se especifique lo contrario):
> - `created_by`, `updated_by` → `UNSIGNED BIGINT NULL`, FK a `usuarios(id)` con `nullOnDelete`
> - `created_at`, `updated_at` → `TIMESTAMP NULL` (Laravel automático)
> - `deleted_at` → `TIMESTAMP NULL` (soft delete de Laravel)
> - Tablas con `id` → `UNSIGNED BIGINT AUTO_INCREMENT` (PK)

---

## 1. Seguridad y Accesos

### Tabla: `roles`

| Campo        | Tipo             | Restricciones            | Descripción                       |
| :----------- | :--------------- | :----------------------- | :-------------------------------- |
| `id`         | BIGINT UNSIGNED  | PK, AUTO_INCREMENT       | Identificador único del rol.      |
| `nombre`     | VARCHAR(100)     | NOT NULL                 | Nombre del rol (Admin, Ventas...).|
| `estado`     | VARCHAR(20)      | DEFAULT 'Activo'         | Control de activación.            |
| `created_by` | BIGINT UNSIGNED  | NULL, FK → `usuarios(id)`| Creado por.                       |
| `updated_by` | BIGINT UNSIGNED  | NULL, FK → `usuarios(id)`| Modificado por.                   |
| `created_at` | TIMESTAMP        | NULL                     |                                   |
| `updated_at` | TIMESTAMP        | NULL                     |                                   |
| `deleted_at` | TIMESTAMP        | NULL                     | Soft delete.                      |

---

### Tabla: `permisos`

| Campo        | Tipo             | Restricciones                         | Descripción                      |
| :----------- | :--------------- | :------------------------------------ | :------------------------------- |
| `id`         | BIGINT UNSIGNED  | PK, AUTO_INCREMENT                    |                                  |
| `rol_id`     | BIGINT UNSIGNED  | NOT NULL, FK → `roles(id)` ON DELETE CASCADE | Rol asociado.            |
| `vista`      | VARCHAR(100)     | NOT NULL                              | Identificador del módulo/vista.  |
| `created_by` | BIGINT UNSIGNED  | NULL, FK → `usuarios(id)`             |                                  |
| `updated_by` | BIGINT UNSIGNED  | NULL, FK → `usuarios(id)`             |                                  |
| `created_at` | TIMESTAMP        | NULL                                  |                                  |
| `updated_at` | TIMESTAMP        | NULL                                  |                                  |
| `deleted_at` | TIMESTAMP        | NULL                                  | Soft delete.                     |

---

### Tabla: `usuarios`

| Campo           | Tipo             | Restricciones                 | Descripción                       |
| :-------------- | :--------------- | :---------------------------- | :-------------------------------- |
| `id`            | BIGINT UNSIGNED  | PK, AUTO_INCREMENT            | Identificador interno.            |
| `nombre`        | VARCHAR(150)     | NOT NULL                      | Nombre completo.                  |
| `email`         | VARCHAR(150)     | UNIQUE, NOT NULL              | Correo para login.                |
| `password_hash` | VARCHAR(255)     | NOT NULL                      | Contraseña encriptada (bcrypt).   |
| `rol_id`        | BIGINT UNSIGNED  | NOT NULL, FK → `roles(id)`    | Rol asignado.                     |
| `estado`        | VARCHAR(20)      | DEFAULT 'Activo'              | Activo / Inactivo.                |
| `created_by`    | BIGINT UNSIGNED  | NULL                          |                                   |
| `updated_by`    | BIGINT UNSIGNED  | NULL                          |                                   |
| `created_at`    | TIMESTAMP        | NULL                          |                                   |
| `updated_at`    | TIMESTAMP        | NULL                          |                                   |
| `deleted_at`    | TIMESTAMP        | NULL                          | Soft delete.                      |

> Nota: No tiene `tel`, `remember_token` ni `email_verified_at`. La autenticación es por API tokens (Sanctum), no por sesión web.

---

### Tabla: `personal_access_tokens` (Sanctum)

| Campo            | Tipo             | Restricciones      | Descripción                       |
| :--------------- | :--------------- | :----------------- | :-------------------------------- |
| `id`             | BIGINT UNSIGNED  | PK, AUTO_INCREMENT |                                   |
| `tokenable_type` | VARCHAR(255)     | NOT NULL           | Morfable: `App\Models\Usuario`    |
| `tokenable_id`   | BIGINT UNSIGNED  | NOT NULL           | ID del usuario.                   |
| `name`           | VARCHAR(255)     | NOT NULL           | Nombre del token.                 |
| `token`          | VARCHAR(64)      | UNIQUE, NOT NULL   | Hash SHA-256 del token.           |
| `abilities`      | TEXT             | NULL               | JSON de habilidades.              |
| `last_used_at`   | TIMESTAMP        | NULL               |                                   |
| `expires_at`     | TIMESTAMP        | NULL               |                                   |
| `created_at`     | TIMESTAMP        | NULL               |                                   |
| `updated_at`     | TIMESTAMP        | NULL               |                                   |

---

## 2. Maestros Generales

### Tabla: `ciudades`

| Campo           | Tipo             | Restricciones                    | Descripción                   |
| :-------------- | :--------------- | :------------------------------- | :---------------------------- |
| `cod_municipio` | VARCHAR(10)      | PK                               | Código DANE del municipio.    |
| `nombre`        | VARCHAR(150)     | NOT NULL                         | Nombre de la ciudad/municipio.|
| `departamento`  | VARCHAR(100)     | NOT NULL                         | Nombre del departamento.      |
| `created_by`    | BIGINT UNSIGNED  | NULL                             |                               |
| `updated_by`    | BIGINT UNSIGNED  | NULL                             |                               |
| `created_at`    | TIMESTAMP        | NULL                             |                               |
| `updated_at`    | TIMESTAMP        | NULL                             |                               |

> Nota: Datos de referencia de solo lectura (sin soft deletes). No tiene coordenadas, tipo de municipio ni código de departamento como campo separado.

---

### Tabla: `productos`

| Campo            | Tipo             | Restricciones      | Descripción                              |
| :--------------- | :--------------- | :----------------- | :--------------------------------------- |
| `id`             | BIGINT UNSIGNED  | PK, AUTO_INCREMENT | Identificador del producto/servicio.     |
| `nombre`         | VARCHAR(200)     | NOT NULL           | Nombre comercial.                        |
| `tipo`           | VARCHAR(50)      | DEFAULT 'producto' | `producto` o `suscripcion`.              |
| `linea_negocio`  | VARCHAR(100)     | NULL               | Agrupación comercial (Seguridad Electrónica, Venta de Equipos...). |
| `iva`            | DECIMAL(5,2)     | DEFAULT 19.00      | Porcentaje de IVA.                       |
| `precio`         | DECIMAL(10,2)    | NULL               | Precio de referencia (opcional).         |
| `descripcion`    | TEXT             | NULL               | Descripción larga del producto.          |
| `caracteristicas`| JSON             | NULL               | Array de características.                |
| `estado`         | VARCHAR(20)      | DEFAULT 'Activo'   | Activo / Inactivo.                       |
| `created_by`     | BIGINT UNSIGNED  | NULL               |                                          |
| `updated_by`     | BIGINT UNSIGNED  | NULL               |                                          |
| `created_at`     | TIMESTAMP        | NULL               |                                          |
| `updated_at`     | TIMESTAMP        | NULL               |                                          |
| `deleted_at`     | TIMESTAMP        | NULL               | Soft delete.                             |

> Nota: Los campos `tipo`, `precio`, `descripcion` y `caracteristicas` se agregaron en una migración posterior (`2026_05_15_000000`).

---

### Tabla: `etiquetas`

| Campo        | Tipo             | Restricciones      | Descripción                       |
| :----------- | :--------------- | :----------------- | :-------------------------------- |
| `id`         | BIGINT UNSIGNED  | PK, AUTO_INCREMENT |                                   |
| `nombre`     | VARCHAR(100)     | UNIQUE, NOT NULL   | Nombre de la etiqueta.            |
| `estado`     | VARCHAR(20)      | DEFAULT 'Activo'   |                                   |
| `created_by` | BIGINT UNSIGNED  | NULL               |                                   |
| `updated_by` | BIGINT UNSIGNED  | NULL               |                                   |
| `created_at` | TIMESTAMP        | NULL               |                                   |
| `updated_at` | TIMESTAMP        | NULL               |                                   |
| `deleted_at` | TIMESTAMP        | NULL               | Soft delete.                      |

---

## 3. Directorio Empresarial

### Tabla: `entidad` (Clientes / Empresas)

| Campo            | Tipo                     | Restricciones                          | Descripción                       |
| :--------------- | :----------------------- | :------------------------------------- | :-------------------------------- |
| `id`             | BIGINT UNSIGNED          | PK, AUTO_INCREMENT                     | Identificador único.              |
| `tipo_persona`   | ENUM('Natural','Juridica')| NOT NULL                               |                                   |
| `tipo_id`        | VARCHAR(20)              | NULL                                   | NIT, CC, CE.                      |
| `identificacion` | VARCHAR(50)              | UNIQUE, NOT NULL                       | Número de documento.              |
| `nombre`         | VARCHAR(255)             | NOT NULL                               | Razón social o nombre completo.   |
| `nombre_comercial`| VARCHAR(255)            | NULL                                   |                                   |
| `direccion`      | VARCHAR(255)             | NULL                                   | Dirección principal.              |
| `ciudad_cod`     | VARCHAR(10)              | NULL, FK → `ciudades(cod_municipio)`   | Ubicación principal.              |
| `dominio`        | VARCHAR(255)             | NULL                                   | Sitio web.                        |
| `rut`            | VARCHAR(255)             | NULL                                   | Ruta al archivo del RUT.          |
| `logo`           | VARCHAR(255)             | NULL                                   | Ruta al logo.                     |
| `estado`         | VARCHAR(50)              | DEFAULT 'Activo'                       |                                   |
| `allowed_domains`| TEXT                     | NULL                                   | Dominios permitidos para API key. |
| `webhook_url`    | VARCHAR(500)             | NULL                                   | URL para webhooks de eventos.     |
| `webhook_secret` | VARCHAR(255)             | NULL                                   | Secreto HMAC-SHA256.              |
| `webhook_enabled`| BOOLEAN                  | DEFAULT FALSE                          | Habilitar envío de webhooks.      |
| `created_by`     | BIGINT UNSIGNED          | NULL                                   |                                   |
| `updated_by`     | BIGINT UNSIGNED          | NULL                                   |                                   |
| `created_at`     | TIMESTAMP                | NULL                                   |                                   |
| `updated_at`     | TIMESTAMP                | NULL                                   |                                   |
| `deleted_at`     | TIMESTAMP                | NULL                                   | Soft delete.                      |

> Nota: Los campos `allowed_domains`, `webhook_url`, `webhook_secret` y `webhook_enabled` se agregaron en migraciones posteriores (`2026_05_10`).

---

### Tabla: `contacto`

| Campo              | Tipo             | Restricciones                              | Descripción                    |
| :----------------- | :--------------- | :----------------------------------------- | :----------------------------- |
| `id`               | BIGINT UNSIGNED  | PK, AUTO_INCREMENT                         |                                 |
| `entidad_id`       | BIGINT UNSIGNED  | NULL, FK → `entidad(id)` ON DELETE SET NULL| Entidad a la que pertenece.    |
| `nombres`          | VARCHAR(150)     | NOT NULL                                   |                                 |
| `apellidos`        | VARCHAR(150)     | NOT NULL                                   |                                 |
| `area`             | VARCHAR(100)     | NULL                                       |                                 |
| `cargo`            | VARCHAR(100)     | NULL                                       |                                 |
| `tel_contacto`     | VARCHAR(50)      | NULL                                       | Teléfono fijo.                  |
| `movil`            | VARCHAR(50)      | NULL                                       | Celular.                        |
| `email_contacto`   | VARCHAR(255)     | NULL                                       | Correo principal.               |
| `email_secundario` | VARCHAR(255)     | NULL                                       |                                 |
| `rol`              | VARCHAR(100)     | NULL                                       | Rol de compra/decisión.         |
| `etapa`            | VARCHAR(50)      | NULL                                       | Etapa del lead.                 |
| `estado`           | VARCHAR(50)      | DEFAULT 'Activo'                            |                                 |
| `diagnostico_data` | JSON             | NULL                                       | Datos del webhook de diagnóstico.|
| `fuente`           | VARCHAR(100)     | NULL                                       | Origen del contacto.            |
| `created_by`       | BIGINT UNSIGNED  | NULL                                       |                                 |
| `updated_by`       | BIGINT UNSIGNED  | NULL                                       |                                 |
| `created_at`       | TIMESTAMP        | NULL                                       |                                 |
| `updated_at`       | TIMESTAMP        | NULL                                       |                                 |
| `deleted_at`       | TIMESTAMP        | NULL                                       | Soft delete.                    |

> Restricción única: `UNIQUE(entidad_id, email_contacto)`. Los campos `diagnostico_data` y `fuente` se agregaron en migración posterior (`2026_05_15_000001`).

---

### Tabla: `lugares_entidad` (Sedes)

| Campo              | Tipo             | Restricciones                               | Descripción                    |
| :----------------- | :--------------- | :------------------------------------------ | :----------------------------- |
| `id`               | BIGINT UNSIGNED  | PK, AUTO_INCREMENT                          |                                 |
| `entidad_id`       | BIGINT UNSIGNED  | NOT NULL, FK → `entidad(id)` ON DELETE CASCADE | Empresa propietaria.         |
| `area_oficina`     | VARCHAR(100)     | NULL                                        | Nombre de la sede.             |
| `direccion`        | VARCHAR(255)     | NULL                                        |                                 |
| `direccion_adicional`| VARCHAR(255)   | NULL                                        |                                 |
| `ciudad_cod`       | VARCHAR(10)      | NULL, FK → `ciudades(cod_municipio)`        |                                 |
| `contacto_id`      | BIGINT UNSIGNED  | NULL, FK → `contacto(id)`                   | Contacto principal de la sede.  |
| `estado`           | VARCHAR(50)      | DEFAULT 'Activo'                            |                                 |
| `created_by`       | BIGINT UNSIGNED  | NULL                                        |                                 |
| `updated_by`       | BIGINT UNSIGNED  | NULL                                        |                                 |
| `created_at`       | TIMESTAMP        | NULL                                        |                                 |
| `updated_at`       | TIMESTAMP        | NULL                                        |                                 |
| `deleted_at`       | TIMESTAMP        | NULL                                        | Soft delete.                    |

---

### Tabla: `entidad_usuario` (Pivot — Usuarios por Entidad)

| Campo         | Tipo             | Restricciones                              | Descripción                  |
| :------------ | :--------------- | :----------------------------------------- | :--------------------------- |
| `usuario_id`  | BIGINT UNSIGNED  | PK compuesta, FK → `usuarios(id)` ON DELETE CASCADE |                    |
| `entidad_id`  | BIGINT UNSIGNED  | PK compuesta, FK → `entidad(id)` ON DELETE CASCADE |                    |
| `created_at`  | TIMESTAMP        | NULL                                       |                              |
| `updated_at`  | TIMESTAMP        | NULL                                       |                              |

> Tabla pivote para relacionar usuarios con las entidades a las que tienen acceso (multi-tenant básico).

---

## 4. Talento Humano y Proveedores

### Tabla: `colaboradores` (Staff Interno)

| Campo            | Tipo             | Restricciones                       | Descripción                    |
| :--------------- | :--------------- | :---------------------------------- | :----------------------------- |
| `id`             | BIGINT UNSIGNED  | PK, AUTO_INCREMENT                  |                                |
| `usuario_id`     | BIGINT UNSIGNED  | NULL, UNIQUE, FK → `usuarios(id)`   | Enlace al acceso del sistema.  |
| `nombres`        | VARCHAR(150)     | NOT NULL                            |                                |
| `apellidos`      | VARCHAR(150)     | NOT NULL                            |                                |
| `tipo_id`        | VARCHAR(20)      | NULL                                | CC, CE, etc.                   |
| `identificacion` | VARCHAR(50)      | UNIQUE, NOT NULL                    |                                |
| `cargo`          | VARCHAR(100)     | NULL                                |                                |
| `area`           | VARCHAR(100)     | NULL                                |                                |
| `fecha_ingreso`  | DATE             | NULL                                |                                |
| `fecha_retiro`   | DATE             | NULL                                |                                |
| `contrato`       | VARCHAR(100)     | NULL                                | Tipo de contrato.              |
| `estado`         | VARCHAR(50)      | DEFAULT 'Activo'                    |                                |
| `created_by`     | BIGINT UNSIGNED  | NULL                                |                                |
| `updated_by`     | BIGINT UNSIGNED  | NULL                                |                                |
| `created_at`     | TIMESTAMP        | NULL                                |                                |
| `updated_at`     | TIMESTAMP        | NULL                                |                                |
| `deleted_at`     | TIMESTAMP        | NULL                                | Soft delete.                   |

---

### Tabla: `proveedores` (Terceros / Contratistas)

| Campo            | Tipo             | Restricciones                          | Descripción                  |
| :--------------- | :--------------- | :------------------------------------- | :--------------------------- |
| `id`             | BIGINT UNSIGNED  | PK, AUTO_INCREMENT                     |                              |
| `tipo_id`        | VARCHAR(20)      | NULL                                   | CC, NIT, etc.               |
| `identificacion` | VARCHAR(50)      | UNIQUE, NOT NULL                       | Incluye DV si aplica.        |
| `nombres`        | VARCHAR(150)     | NULL                                   |                              |
| `apellidos`      | VARCHAR(150)     | NULL                                   |                              |
| `profesion`      | VARCHAR(150)     | NULL                                   |                              |
| `especialidad`   | VARCHAR(150)     | NULL                                   |                              |
| `iva`            | DECIMAL(5,2)     | NULL                                   | % IVA del proveedor.         |
| `retenciones`    | DECIMAL(5,2)     | NULL                                   | % retención aplicada.        |
| `ciudad_cod`     | VARCHAR(10)      | NULL, FK → `ciudades(cod_municipio)`   |                              |
| `fecha_registro` | DATE             | NULL                                   |                              |
| `estado`         | VARCHAR(50)      | DEFAULT 'Activo'                       |                              |
| `created_by`     | BIGINT UNSIGNED  | NULL                                   |                              |
| `updated_by`     | BIGINT UNSIGNED  | NULL                                   |                              |
| `created_at`     | TIMESTAMP        | NULL                                   |                              |
| `updated_at`     | TIMESTAMP        | NULL                                   |                              |
| `deleted_at`     | TIMESTAMP        | NULL                                   | Soft delete.                 |

---

## 5. CRM (Comercial)

### Tabla: `oportunidad` (Oportunidades / Cotizaciones)

| Campo            | Tipo               | Restricciones                          | Descripción                              |
| :--------------- | :----------------- | :------------------------------------- | :--------------------------------------- |
| `id`             | BIGINT UNSIGNED    | PK, AUTO_INCREMENT                     |                                          |
| `codigo`         | VARCHAR(20)        | UNIQUE, NOT NULL                       | ID visible de la cotización.             |
| `entidad_id`     | BIGINT UNSIGNED    | NOT NULL, FK → `entidad(id)` ON DELETE CASCADE | Cliente.                        |
| `contacto_id`    | BIGINT UNSIGNED    | NULL, FK → `contacto(id)`              | Contacto directo.                        |
| `fecha`          | DATE               | NOT NULL                               | Fecha de creación.                       |
| `fuente_canal`   | VARCHAR(100)       | NULL                                   | Origen del prospecto.                    |
| `estado`         | ENUM('Borrador','Enviada','Aceptada','Rechazada','Ganada','Perdida') | DEFAULT 'Borrador' |                                          |
| `observaciones`  | TEXT               | NULL                                   |                                          |
| `aclaraciones`   | TEXT               | NULL                                   |                                          |
| `validez_oferta` | INT UNSIGNED       | NULL                                   | Días de validez de la oferta.            |
| `tiempo_entrega` | VARCHAR(255)       | NULL                                   | Plazo de entrega.                        |
| `forma_pago`     | VARCHAR(255)       | NULL                                   | Condiciones de pago.                     |
| `garantia`       | VARCHAR(255)       | NULL                                   |                                          |
| `created_by`     | BIGINT UNSIGNED    | NULL                                   |                                          |
| `updated_by`     | BIGINT UNSIGNED    | NULL                                   |                                          |
| `created_at`     | TIMESTAMP          | NULL                                   |                                          |
| `updated_at`     | TIMESTAMP          | NULL                                   |                                          |
| `deleted_at`     | TIMESTAMP          | NULL                                   | Soft delete.                             |

> Nota: En el frontend, los estados "Aceptada" y "Perdida" se renombraron como "Negociada" y se eliminó "Perdida" del flujo (Borrador → Enviada → Negociada → Ganada / Rechazada). La migración mantiene el ENUM original con ambos valores.

---

### Tabla: `detalle_oportunidad` (Líneas de Cotización)

| Campo            | Tipo               | Restricciones                              | Descripción                      |
| :--------------- | :----------------- | :----------------------------------------- | :------------------------------- |
| `id`             | BIGINT UNSIGNED    | PK, AUTO_INCREMENT                         |                                  |
| `oportunidad_id` | BIGINT UNSIGNED    | NOT NULL, FK → `oportunidad(id)` ON DELETE CASCADE | Cotización padre.       |
| `producto_id`    | BIGINT UNSIGNED    | NOT NULL, FK → `productos(id)` ON DELETE RESTRICT | Ítem del catálogo.       |
| `concepto`       | VARCHAR(255)       | NULL                                       | Descripción específica.          |
| `medida`         | VARCHAR(10)        | DEFAULT 'Und'                              | Unidad (Und, Hrs, Srv, etc.).    |
| `cantidad`       | DECIMAL(10,2)      | NOT NULL                                   |                                  |
| `vr_unitario`    | DECIMAL(15,2)      | NOT NULL                                   | Valor antes de impuestos.        |
| `iva`            | DECIMAL(15,2)      | DEFAULT 0.00                               | Monto de IVA calculado.          |
| `vr_total`       | DECIMAL(15,2)      | DEFAULT 0.00                               | Total línea (vr_unitario * cantidad + iva). |
| `created_by`     | BIGINT UNSIGNED    | NULL                                       |                                  |
| `updated_by`     | BIGINT UNSIGNED    | NULL                                       |                                  |
| `created_at`     | TIMESTAMP          | NULL                                       |                                  |
| `updated_at`     | TIMESTAMP          | NULL                                       |                                  |
| `deleted_at`     | TIMESTAMP          | NULL                                       | Soft delete.                     |

---

### Tabla: `seguimiento` (Actividades del CRM)

| Campo            | Tipo                                   | Restricciones                          | Descripción                      |
| :--------------- | :------------------------------------- | :------------------------------------- | :------------------------------- |
| `id`             | BIGINT UNSIGNED                        | PK, AUTO_INCREMENT                     |                                  |
| `oportunidad_id` | BIGINT UNSIGNED                        | NULL, FK → `oportunidad(id)`           | Oportunidad asociada.            |
| `contacto_id`    | BIGINT UNSIGNED                        | NULL, FK → `contacto(id)`              | Contacto involucrado.            |
| `entidad_id`     | BIGINT UNSIGNED                        | NULL, FK → `entidad(id)`               | Entidad asociada.                |
| `tipo`           | ENUM('Llamada','Correo','Reunion','Nota','Otro') | NOT NULL                   | Tipo de actividad.               |
| `fecha`          | DATE                                   | NOT NULL                               | Fecha del seguimiento.           |
| `hora`           | TIME                                   | NULL                                   | Hora programada.                 |
| `fecha_fin`      | DATETIME                               | NULL                                   | Si es evento con duración.       |
| `notas`          | TEXT                                   | NULL                                   | Desarrollo / comentarios.        |
| `autor_id`       | BIGINT UNSIGNED                        | NULL, FK → `usuarios(id)`              | Usuario que registra.            |
| `estado`         | ENUM('Pendiente','Completado','Cancelado') | DEFAULT 'Pendiente'                |                                  |
| `created_by`     | BIGINT UNSIGNED                        | NULL                                   |                                  |
| `updated_by`     | BIGINT UNSIGNED                        | NULL                                   |                                  |
| `created_at`     | TIMESTAMP                              | NULL                                   |                                  |
| `updated_at`     | TIMESTAMP                              | NULL                                   |                                  |
| `deleted_at`     | TIMESTAMP                              | NULL                                   | Soft delete.                     |

---

## 6. Operaciones (ERP)

### Tabla: `servicios` (Proyectos / Servicios Vendidos)

| Campo              | Tipo               | Restricciones                         | Descripción                      |
| :----------------- | :----------------- | :------------------------------------ | :------------------------------- |
| `id`               | BIGINT UNSIGNED    | PK, AUTO_INCREMENT                    |                                  |
| `oportunidad_id`   | BIGINT UNSIGNED    | NULL, UNIQUE, FK → `oportunidad(id)`  | Origen del negocio.              |
| `entidad_id`       | BIGINT UNSIGNED    | NOT NULL, FK → `entidad(id)`          | Cliente.                         |
| `nombre`           | VARCHAR(255)       | NOT NULL                              | Título del servicio.             |
| `vr_servicio`      | DECIMAL(15,2)      | DEFAULT 0.00                          | Valor total.                     |
| `fecha_inicio`     | DATE               | NULL                                  |                                  |
| `fecha_fin`        | DATE               | NULL                                  |                                  |
| `prestador_id`     | BIGINT UNSIGNED    | NULL, FK → `proveedores(id)`          | Proveedor principal a cargo.     |
| `estado`           | VARCHAR(50)        | DEFAULT 'Nuevo'                       | Nuevo, Ejecución, Finalizado...  |
| `activation_token` | VARCHAR(64)        | NULL, UNIQUE                          | Token de activación (licencias). |
| `plan_id`          | VARCHAR(50)        | NULL                                  | ID del plan (suscripciones).     |
| `tier`             | VARCHAR(50)        | NULL                                  | Nivel del servicio.              |
| `subscription_id`  | VARCHAR(100)       | NULL                                  | ID de suscripción externa.       |
| `metadata`         | JSON               | NULL                                  | Datos adicionales.               |
| `created_by`       | BIGINT UNSIGNED    | NULL, FK → `usuarios(id)`             |                                  |
| `updated_by`       | BIGINT UNSIGNED    | NULL, FK → `usuarios(id)`             |                                  |
| `created_at`       | TIMESTAMP          | NULL                                  |                                  |
| `updated_at`       | TIMESTAMP          | NULL                                  |                                  |
| `deleted_at`       | TIMESTAMP          | NULL                                  | Soft delete.                     |

> Nota: Los campos `activation_token`, `plan_id`, `tier`, `subscription_id` y `metadata` se agregaron en migración posterior (`2026_05_20_165454`) para soporte de licencias y suscripciones.

---

### Tabla: `detalle_servicios` (Líneas de Servicio)

| Campo         | Tipo               | Restricciones                           | Descripción              |
| :------------ | :----------------- | :-------------------------------------- | :----------------------- |
| `id`          | BIGINT UNSIGNED    | PK, AUTO_INCREMENT                      |                          |
| `servicio_id` | BIGINT UNSIGNED    | NOT NULL, FK → `servicios(id)` ON DELETE CASCADE | Servicio padre. |
| `producto_id` | BIGINT UNSIGNED    | NULL, FK → `productos(id)`              | Producto base.           |
| `observacion` | TEXT               | NULL                                    |                          |
| `cantidad`    | DECIMAL(10,2)      | DEFAULT 0.00                            |                          |
| `precio`      | DECIMAL(15,2)      | DEFAULT 0.00                            |                          |
| `descuento`   | DECIMAL(15,2)      | DEFAULT 0.00                            |                          |
| `sub_total`   | DECIMAL(15,2)      | DEFAULT 0.00                            |                          |
| `iva`         | DECIMAL(15,2)      | DEFAULT 0.00                            |                          |
| `total`       | DECIMAL(15,2)      | DEFAULT 0.00                            |                          |
| `created_by`  | BIGINT UNSIGNED    | NULL, FK → `usuarios(id)`               |                          |
| `updated_by`  | BIGINT UNSIGNED    | NULL, FK → `usuarios(id)`               |                          |
| `created_at`  | TIMESTAMP          | NULL                                    |                          |
| `updated_at`  | TIMESTAMP          | NULL                                    |                          |
| `deleted_at`  | TIMESTAMP          | NULL                                    | Soft delete.             |

---

### Tabla: `orden_servicio` (Asignación Operativa)

| Campo            | Tipo               | Restricciones                              | Descripción                    |
| :--------------- | :----------------- | :----------------------------------------- | :----------------------------- |
| `id`             | BIGINT UNSIGNED    | PK, AUTO_INCREMENT                         |                                |
| `detalle_srv_id` | BIGINT UNSIGNED    | NULL, FK → `detalle_servicios(id)`          | Actividad específica.          |
| `colaborador_id` | BIGINT UNSIGNED    | NULL, FK → `colaboradores(id)`              | Si es staff interno.           |
| `proveedor_id`   | BIGINT UNSIGNED    | NULL, FK → `proveedores(id)`                | Si es outsourcing.             |
| `contacto_id`    | BIGINT UNSIGNED    | NULL, FK → `contacto(id)`                   | Persona a contactar en sitio.  |
| `descripcion`    | TEXT               | NULL                                       |                                |
| `objetivo`       | TEXT               | NULL                                       |                                |
| `ubicacion`      | VARCHAR(255)       | NULL                                       |                                |
| `fecha_desde`    | DATETIME           | NULL                                       |                                |
| `fecha_hasta`    | DATETIME           | NULL                                       |                                |
| `valor`          | DECIMAL(15,2)      | DEFAULT 0.00                               | Costo de la orden.             |
| `estado`         | VARCHAR(50)        | DEFAULT 'Pendiente'                        |                                |
| `created_by`     | BIGINT UNSIGNED    | NULL, FK → `usuarios(id)`                  |                                |
| `updated_by`     | BIGINT UNSIGNED    | NULL, FK → `usuarios(id)`                  |                                |
| `created_at`     | TIMESTAMP          | NULL                                       |                                |
| `updated_at`     | TIMESTAMP          | NULL                                       |                                |
| `deleted_at`     | TIMESTAMP          | NULL                                       | Soft delete.                   |

---

## 7. Finanzas (Básico)

### Tabla: `cuentas` (Bancarias de Proveedores)

| Campo           | Tipo               | Restricciones                          | Descripción             |
| :-------------- | :----------------- | :------------------------------------- | :---------------------- |
| `id`            | BIGINT UNSIGNED    | PK, AUTO_INCREMENT                     |                         |
| `proveedor_id`  | BIGINT UNSIGNED    | NOT NULL, FK → `proveedores(id)` ON DELETE CASCADE |              |
| `banco`         | VARCHAR(255)       | NOT NULL                               | Nombre del banco.       |
| `numero_cuenta` | VARCHAR(255)       | NOT NULL                               | Número de cuenta.       |
| `tipo`          | VARCHAR(20)        | DEFAULT 'Ahorros'                      | Ahorros, Corriente.     |
| `estado`        | VARCHAR(20)        | DEFAULT 'Activo'                       |                         |
| `created_by`    | BIGINT UNSIGNED    | NULL, FK → `usuarios(id)`              |                         |
| `updated_by`    | BIGINT UNSIGNED    | NULL, FK → `usuarios(id)`              |                         |
| `created_at`    | TIMESTAMP          | NULL                                   |                         |
| `updated_at`    | TIMESTAMP          | NULL                                   |                         |

> Nota: Sin soft delete — las cuentas bancarias son registros permanentes.

---

### Tabla: `movimientos` (Flujo Financiero)

| Campo            | Tipo               | Restricciones                          | Descripción                    |
| :--------------- | :----------------- | :------------------------------------- | :----------------------------- |
| `id`             | BIGINT UNSIGNED    | PK, AUTO_INCREMENT                     |                                |
| `fecha`          | DATE               | NOT NULL                               | Fecha de la transacción.       |
| `valor_debito`   | DECIMAL(15,2)      | DEFAULT 0.00                           | Egresos.                       |
| `valor_credito`  | DECIMAL(15,2)      | DEFAULT 0.00                           | Ingresos.                      |
| `proveedor_id`   | BIGINT UNSIGNED    | NULL, FK → `proveedores(id)`           |                                |
| `colaborador_id` | BIGINT UNSIGNED    | NULL, FK → `colaboradores(id)`         |                                |
| `servicio_id`    | BIGINT UNSIGNED    | NULL, FK → `servicios(id)`             | Centro de costo.               |
| `observaciones`  | TEXT               | NULL                                   | Concepto del pago/ingreso.     |
| `created_by`     | BIGINT UNSIGNED    | NULL, FK → `usuarios(id)`              |                                |
| `updated_by`     | BIGINT UNSIGNED    | NULL, FK → `usuarios(id)`              |                                |
| `created_at`     | TIMESTAMP          | NULL                                   |                                |
| `updated_at`     | TIMESTAMP          | NULL                                   |                                |

---

## 8. Tablas del Framework (Laravel)

Son tablas internas de Laravel que no forman parte del dominio de negocio:

| Tabla                    | Propósito                                      |
| :----------------------- | :--------------------------------------------- |
| `cache`                  | Cache de Laravel (driver `database`).          |
| `cache_locks`            | Bloqueos de cache para operaciones atómicas.   |
| `jobs`                   | Cola de trabajos (driver `database`).          |
| `job_batches`            | Lotes de trabajos en cola.                     |
| `failed_jobs`            | Trabajos fallidos de la cola.                  |
| `sessions`               | Sesiones web (solo para autenticación básica). |
| `personal_access_tokens` | Tokens de API de Laravel Sanctum (documentada en sección 1). |

---

## Resumen de cambios respecto a la versión anterior del diccionario

1. **Convención audit**: Todas las tablas de negocio ahora tienen `created_by`, `updated_by` y soft deletes (`deleted_at`).
2. **Roles**: Agregado `estado`, audit fields y soft delete.
3. **Permisos**: FK a `roles` ahora con `cascadeOnDelete`.
4. **Usuarios**: Sin `tel`, sin `remember_token`. Agregados audit fields y soft delete.
5. **Ciudades**: Simplificada — solo `cod_municipio` (PK), `nombre`, `departamento`. Sin coordenadas ni tipo de municipio. Sin soft delete.
6. **Productos**: Agregados `tipo`, `precio`, `descripcion`, `caracteristicas` (JSON), `estado`, audit fields y soft delete.
7. **Etiquetas**: Agregados `estado`, audit fields y soft delete.
8. **Entidad**: Agregados `allowed_domains`, `webhook_url`, `webhook_secret`, `webhook_enabled`, audit fields y soft delete.
9. **Contacto**: Agregados `estado`, `diagnostico_data` (JSON), `fuente`, audit fields, soft delete y unique compuesto (`entidad_id`, `email_contacto`).
10. **Lugares entidad**: Agregados `estado`, audit fields y soft delete.
11. **Colaboradores**: `usuario_id` es único y nullable. Agregados audit fields y soft delete.
12. **Proveedores**: Agregados `estado`, `ciudad_cod` FK, audit fields y soft delete.
13. **Oportunidad**: `codigo` reduce a VARCHAR(20). `estado` es ENUM con `Aceptada` / `Perdida` en BD (aunque frontend usa "Negociada"). Agregados `validez_oferta` como INT UNSIGNED, audit fields y soft delete.
14. **Detalle oportunidad**: `iva` es DECIMAL(15,2) (monto calculado, no porcentaje). `medida` es VARCHAR(10). FK a `productos` con `restrictOnDelete`. Audit fields y soft delete.
15. **Seguimiento**: `tipo` y `estado` son ENUM. Agregados `fecha_fin`, audit fields y soft delete.
16. **Servicios**: Nuevos campos `activation_token`, `plan_id`, `tier`, `subscription_id`, `metadata` (JSON). FKs con `foreignId` explícito.
17. **Detalle servicios, Orden servicio, Cuentas, Movimientos**: FKs normalizados con `foreignId`, audit fields consistentes.
18. **Entidad_usuario**: Nueva tabla pivote para multi-tenant básico.
