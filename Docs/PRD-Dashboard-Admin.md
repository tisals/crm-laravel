# PRD - Dashboard Admin CRM Tecnoinnsoft

## 1. Resumen Ejecutivo

**Nombre del proyecto**: Dashboard Admin CRM Tecnoinnsoft  
**Tipo de producto**: Aplicación web progresiva (PWA) / SPA  
**Resumen**: Dashboard administrativo para gestionar el CRM/ERP con diseño móvil first, consume la API REST del CRM Laravel y se integra con SAIlus (FastAPI) para funcionalidades avanzadas.  
**Usuarios objetivo**: Administradores, vendedores, colaboradores internos, gerentes de operaciones y finanzas.

---

## 2. Alcance del Proyecto

### 2.1 Módulos Incluidos

| Módulo | Entidades | Funcionalidad Principal |
|--------|-----------|------------------------|
| **Seguridad** | Usuarios, Roles, Permisos | Gestión de accesos y autenticación |
| **Maestros** | Ciudades, Productos, Etiquetas | Datos de referencia (solo lectura para ciudades) |
| **Directorio** | Entidades, Lugares, Contactos | Directorio empresarial y clientes |
| **Talento** | Colaboradores, Proveedores | Gestión de personal y proveedores |
| **CRM** | Oportunidades, Detalles, Seguimientos | Pipeline de ventas y actividades |
| **Operaciones** | Servicios, Detalles, Órdenes | Gestión de proyectos y servicios |
| **Finanzas** | Cuentas, Movimientos | Control financiero y bancarios |
| **Dashboard** | KPIs, Gráficos, Notificaciones | Vista principal con métricas |

### 2.2 Funcionalidades Fuera de Alcance
- Portal de clientes (separado)
- Generación de facturas/PDFs
- Chat en tiempo real
- Reportes avanzados (BI)

---

## 3. Requisitos Funcionales

### 3.1 Autenticación y Seguridad

| ID | Requisito | Prioridad |
|----|-----------|-----------|
| R1.1 | Login con email/password | Alta |
| R1.2 | Login con API Key (para integraciones SAIlus) | Alta |
| R1.3 | Logout y destrucción de token | Alta |
| R1.4 | Protección de rutas por permisos (RBAC) | Alta |
| R1.5 | Indicador visual de permisos por usuario | Media |
| R1.6 | Sesión activa visible en header | Baja |

### 3.2 Dashboard Principal

| ID | Requisito | Prioridad |
|----|-----------|-----------|
| R2.1 | KPIs: Total oportunidades, tasa de conversión, ventas del mes | Alta |
| R2.2 | Gráfico de oportunidades por estado (barras) | Alta |
| R2.3 | Gráfico de ventas últimas 4 semanas (línea) | Alta |
| R2.4 | Lista de actividades recientes (seguimientos) | Alta |
| R2.5 | Notificaciones de webhooks recibidos | Media |
| R2.6 | Accesos directos a módulos frecuentes | Baja |

### 3.3 Módulo: Directorio (Entidades)

| ID | Requisito | Prioridad |
|----|-----------|-----------|
| R3.1 | Listar entidades con filtros (estado, tipo_persona, búsqueda) | Alta |
| R3.2 | Crear nueva entidad con validaciones | Alta |
| R3.3 | Ver detalle de entidad | Alta |
| R3.4 | Editar entidad | Alta |
| R3.5 | Eliminar entidad (soft delete) | Alta |
| R3.6 | Ver lugares de la entidad | Media |
| R3.7 | Ver contactos de la entidad | Media |

### 3.4 Módulo: Contactos

| ID | Requisito | Prioridad |
|----|-----------|-----------|
| R4.1 | Listar contactos con filtros | Alta |
| R4.2 | Crear/editar/eliminar contacto | Alta |
| R4.3 | Ver información del contacto y su entidad | Alta |
| R4.4 | Historial de seguimientos del contacto | Media |
| R4.5 | Acciones rápidas: llamada, email | Baja |

### 3.5 Módulo: CRM (Oportunidades)

| ID | Requisito | Prioridad |
|----|-----------|-----------|
| R5.1 | Kanban visual por estado (Borrador → Enviada → Aceptada → Ganada/Perdida) | Alta |
| R5.2 | Listar oportunidades en tabla | Alta |
| R5.3 | Crear oportunidad (auto-genera código COT-000001) | Alta |
| R5.4 | Editar oportunidad y cambiar estado | Alta |
| R5.5 | Detalle de oportunidad con líneas (productos) | Alta |
| R5.6 | Crear/editar líneas de oportunidad (auto-cálculo IVA/total) | Alta |
| R5.7 | Convertir oportunidad Ganada → crear Servicio automáticamente | Alta |
| R5.8 | Filtrar por entidad, estado, fecha | Media |

### 3.6 Módulo: Seguimientos

| ID | Requisito | Prioridad |
|----|-----------|-----------|
| R6.1 | Listar seguimientos con filtros | Alta |
| R6.2 | Crear seguimiento (llamada, email, reunión, nota) | Alta |
| R6.3 | Programar seguimiento futuro | Alta |
| R6.4 | Vista de calendario (semana/mes) | Media |
| R6.5 | Marcar como completado | Alta |

### 3.7 Módulo: Operaciones (Servicios)

| ID | Requisito | Prioridad |
|----|-----------|-----------|
| R7.1 | Listar servicios con filtros (estado, entidad) | Alta |
| R7.2 | Crear/editar servicio | Alta |
| R7.3 | Detalle del servicio con líneas | Alta |
| R7.4 | Crear orden de servicio | Alta |
| R7.5 | Asignar colaborador o proveedor a orden | Alta |
| R7.6 | Estado de la orden (Pendiente → En Progreso → Completado) | Alta |

### 3.8 Módulo: Finanzas

| ID | Requisito | Prioridad |
|----|-----------|-----------|
| R8.1 | Listar movimientos con filtros (fecha, tipo) | Alta |
| R8.2 | Crear movimiento (débitos/créditos) | Alta |
| R8.3 | Relacionar con proveedor, colaborador o servicio | Alta |
| R8.4 | Resumen: total ingresos, total egresos, saldo | Alta |
| R8.5 | Listar cuentas bancarias por proveedor | Media |

### 3.9 Módulo: Seguridad (Admin)

| ID | Requisito | Prioridad |
|----|-----------|-----------|
| R9.1 | CRUD de usuarios | Alta |
| R9.2 | Asignar roles a usuarios | Alta |
| R9.3 | CRUD de roles | Alta |
| R9.4 | CRUD de permisos | Media |
| R9.5 | Ver actividad reciente del sistema | Baja |

---

## 4. Diseño UX/UI

### 4.1 Principios de Diseño

| Principio | Descripción |
|-----------|-------------|
| **Móvil First** | Diseño para pantalla pequeña primero, escalar a desktop |
| **Minimalista** |Solo elementos necesarios,优先级 visual clara |
| **Feedback inmediato** | spinners, toast notifications, errores claros |
| **Modo offline** | Progressive Web App con cache de datos frecuentes |

### 4.2 Componentes UI Reutilizables

| Componente | Descripción |
|------------|-------------|
| `DataTable` | Tabla con paginación, ordenamiento, búsqueda, filtros |
| `KanbanBoard` | Tablero drag-and-drop para oportunidades |
| `SearchBar` | Barra de búsqueda con debounce (300ms) |
| `FilterPanel` | Panel de filtros colapsable |
| `EntityCard` | Tarjeta de entidad con acciones rápidas |
| `StatCard` | Card de KPI con icono, valor, tendencia |
| `Timeline` | Línea de tiempo para seguimientos |
| `Calendar` | Vista de calendario mensual/semanal |
| `ModalForm` | Formularios en modal (mobile) o drawer (desktop) |
| `Toast` | Notificaciones temporales (success, error, warning) |

### 4.3 Breakpoints

| Dispositivo | Ancho | Comportamiento |
|-------------|-------|----------------|
| Mobile | < 640px | Una columna, tabs inferiores, bottom navigation |
| Tablet | 640px - 1024px | Dos columnas, sidebar colapsable |
| Desktop | > 1024px | Tres columnas, sidebar fija,header completo |

### 4.4 Paleta de Colores

| Uso | Color |
|-----|-------|
| Primary | `#0D9488` (Teal 600) |
| Primary Dark | `#0F766E` (Teal 700) |
| Secondary | `#6366F1` (Indigo 500) |
| Success | `#10B981` (Emerald 500) |
| Warning | `#F59E0B` (Amber 500) |
| Error | `#EF4444` (Red 500) |
| Background | `#F8FAFC` (Slate 50) |
| Surface | `#FFFFFF` |
| Text Primary | `#1E293B` (Slate 800) |
| Text Secondary | `#64748B` (Slate 500) |
| Border | `#E2E8F0` (Slate 200) |

### 4.5 Tipografía

| Elemento | Font | Tamaño | Peso |
|----------|------|--------|------|
| Heading 1 | Inter | 24px | 700 |
| Heading 2 | Inter | 20px | 600 |
| Heading 3 | Inter | 16px | 600 |
| Body | Inter | 14px | 400 |
| Small | Inter | 12px | 400 |
| Mono | JetBrains Mono | 13px | 400 |

---

## 5. Integración con API

### 5.1 Endpoints Utilizados

```
Base URL: https://crm.tu-dominio.com/api/v1

Auth:
- POST /auth/login
- POST /auth/logout

Seguridad:
- GET/POST /usuarios
- GET/PUT/DELETE /usuarios/{id}
- GET/POST /roles
- GET/POST /permisos

Maestros:
- GET /ciudades
- GET/POST /productos
- GET/POST /etiquetas

Directorio:
- GET/POST /entidad
- GET/PUT/DELETE /entidad/{id}
- GET/POST /contacto
- GET/PUT/DELETE /contacto/{id}
- GET/POST /entidad/{id}/lugares

Talento:
- GET/POST /colaboradores
- GET/POST /proveedores

CRM:
- GET/POST /oportunidades
- GET/PUT/DELETE /oportunidades/{id}
- GET/POST /oportunidades/{id}/detalles
- GET/POST /seguimientos
- GET/seguimientos?oportunidad_id=X

Operaciones:
- GET/POST /servicios
- GET/POST /servicios/{id}/detalles
- GET/POST /ordenes-servicio

Finanzas:
- GET/POST /movimientos
- GET/POST /cuentas
```

### 5.2 Autenticación

```javascript
// Login
const response = await fetch('/api/v1/auth/login', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({ email, password })
});

// Guardar token
localStorage.setItem('token', response.data.token);

// Headers para requests
headers: {
  'Authorization': `Bearer ${token}`,
  'Content-Type': 'application/json'
}
```

### 5.3 Manejo de Errores

| Código | Acción en Frontend |
|--------|-------------------|
| 401 | Redirect a login, limpiar token |
| 403 | Mostrar "Sin permisos" + toast |
| 404 | Mostrar "No encontrado" |
| 422 | Mostrar errores de validación en表单 |
| 429 | Mostrar "Intenta más tarde" + countdown |
| 500 | Mostrar "Error del servidor" + opción de reportar |

---

## 6. Requisitos No Funcionales

### 6.1 Performance

| Métrica | Target |
|---------|--------|
| First Contentful Paint (FCP) | < 1.5s |
| Largest Contentful Paint (LCP) | < 2.5s |
| Time to Interactive (TTI) | < 3s |
| Bundle size (JS) | < 500KB gzipped |
| API response | < 500ms (p95) |

### 6.2 Compatibilidad

| Navegador | Versión Mínima |
|-----------|---------------|
| Chrome | 90+ |
| Firefox | 88+ |
| Safari | 14+ |
| Edge | 90+ |

### 6.3 PWA

| Requisito | Descripción |
|-----------|-------------|
| Manifest | Nombre, iconos, theme color, display: standalone |
| Service Worker | Cache de assets estáticos |
| Offline | Datos frecuentes en cache (entidades recientes) |

### 6.4 Accesibilidad

- WCAG 2.1 nivel AA
- Navegación por teclado
- Labels en todos los inputs
- Contraste mínimo 4.5:1

---

## 7. User Stories

### US1: Como vendedor quiero ver el dashboard con KPIs para saber cómo voy
- Dado que estoy logueado
- Cuando accedo al home
- Entonces veo: oportunidades totales, tasa de conversión, ventas del mes, gráfico de estados

### US2: Como vendedor quiero gestionar el pipeline de oportunidades para cerrar más ventas
- Dado que estoy en el módulo CRM
- Cuando veo el Kanban
- Puedo arrastrar oportunidades entre columnas
- Y al pasar a "Ganada" se crea automáticamente un Servicio

### US3: Como administrador quiero gestionar usuarios y roles para controlar accesos
- Dado que tengo rol Administrador
- Cuando voy a Configuración
- Puedo crear/editar roles y asignar permisos
- Puedo crear usuarios y asignarles roles

### US4: Como vendedor quiero registrar seguimientos para no perder leads
- Dado que estoy en una oportunidad
- Cuando agrego un seguimiento
- Selecciono tipo (llamada, email, reunión)
- Defino fecha y hora
- Y el sistema me notifica cuando esté próximo

### US5: Como colaborador quiero ver mis órdenes de servicio para saber qué hacer hoy
- Dado que estoy logueado
- Cuando voy a mis tareas
- Veo las órdenes asignadas filtradas por mi ID
- Puedo actualizar el estado

---

## 8. Cronograma Tentativo

| Semana | Módulo | Entregable |
|--------|--------|------------|
| 1 | Setup + Auth | Repo configurado, login funcional |
| 2 | Dashboard + Directorio | KPIs, CRUD Entidades |
| 3 | Contactos + CRM | CRUD Contactos, Kanban |
| 4 | Oportunidades + Detalles | Líneas, cálculos, transitions |
| 5 | Seguimientos + Operaciones | Calendario, Servicios |
| 6 | Finanzas + Seguridad | Movimientos, Users/Roles |
| 7 | Polish + PWA | Offline, polish, testing |
| 8 | QA + Deploy | Bug fixing, producción |

---

## 9. Stack Tecnológico Sugerido

| Capa | Tecnología |
|------|-------------|
| Framework | Next.js 14 (App Router) o React + Vite |
| UI | Tailwind CSS + shadcn/ui o Radix UI |
| State | Zustand o TanStack Query |
| Forms | React Hook Form + Zod |
| Charts | Recharts |
| Drag & Drop | @dnd-kit |
| Calendar | React Big Calendar |
| HTTP | Axios con interceptors |
| PWA | Vite PWA Plugin |

---

## 10. Glossary

| Término | Definición |
|---------|------------|
| **Entidad** | Cliente/empresa (antes "Organización") |
| **Oportunidad** | Cotización/negocio en el pipeline |
| **Seguimiento** | Actividad registrada (llamada, email, reunión) |
| **Servicio** | Proyecto vendido derivado de oportunidad ganada |
| **Orden de Servicio** | Tarea específica dentro de un servicio |
| **Movimiento** | Transacción financiera (débito/crédito) |
| **Soft Delete** | Eliminación lógica (no borra de la DB) |
| **RBAC** | Control de acceso basado en roles |

---

## 11. Mockups/Wireframes Descripciones

### 11.1 Dashboard (Mobile)
```
┌─────────────────────────┐
│ ☰  CRM Tecnoinnsoft    │  Header
├─────────────────────────┤
│ ┌──────┐ ┌──────┐      │
│ │  24  │ │  65% │      │  KPIs row
│ │Opors │ │Convrs│      │
│ └──────┘ └──────┘      │
│ ┌─────────────────┐    │
│ │   📊[gráfico]  │    │  Chart (full width)
│ └─────────────────┘    │
│ ┌─────────────────┐    │
│ │ 📅 Recientes    │    │  Actividad reciente
│ │ • Llamada Juan  │    │
│ │ • Email Maria   │    │
│ └─────────────────┘    │
├─────────────────────────┤
│ 🏠 │ 📊 │ 📋 │ 👥 │ 🔔│  Bottom nav
└─────────────────────────┘
```

### 11.2 Kanban (Mobile)
```
┌─────────────────────────┐
│ ← Oportunidades         │  Header + back
├─────────────────────────┤
│ [Borrador][Enviada][Ganada] │ Tabs de columnas
├─────────────────────────┤
│ ┌─────────────────────┐ │
│ │ COT-0001            │ │
│ │ Acme Corp           │ │  Card
│ │ $15,000             │ │
│ └─────────────────────┘ │
│ ┌─────────────────────┐ │
│ │ COT-0002            │ │
│ │ TechSoft            │ │
│ │ $8,500              │ │
│ └─────────────────────┘ │
│        +               │  FAB (Floating Action)
└─────────────────────────┘
```

### 11.3 Formulario (Mobile)
```
┌─────────────────────────┐
│ ← Nueva Entidad    [💾] │  Header + save
├─────────────────────────┤
│ ┌─────────────────────┐ │
│ │ Tipo *              │ │  Select
│ │ [Natural ▼]        │ │
│ └─────────────────────┘ │
│ ┌─────────────────────┐ │
│ │ Identificación *    │ │  Input
│ └─────────────────────┘ │
│ ┌─────────────────────┐ │
│ │ Nombre *            │ │
│ └─────────────────────┘ │
│ ┌─────────────────────┐ │
│ │ Ciudad             │ │
│ │ [Seleccionar ▼]    │ │
│ └─────────────────────┘ │
│ ┌─────────────────────┐ │
│ │ Estado             │ │
│ │ [Activo ▼]         │ │
│ └─────────────────────┘ │
│                        │
│ [Guardar]              │  CTA Button
└─────────────────────────┘
```

---

*Documento generado para desarrollo del Dashboard Admin CRM*
*Versión: 1.0*
*Fecha: 2026-05-10*