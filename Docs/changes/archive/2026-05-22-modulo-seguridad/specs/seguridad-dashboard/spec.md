# Seguridad Dashboard Specification

## Purpose

Dashboard de KPIs del módulo Seguridad que reemplaza el stub de SeguridadPage. Proporciona métricas del sistema y acceso rápido a secciones de gestión.

## Requirements

### Requirement: Endpoint GET /api/v1/seguridad/dashboard

The system MUST expose `GET /api/v1/seguridad/dashboard` protected by `auth:sanctum`. Returns envelope: `{ success: bool, data: { kpis, distribucion_roles, actividad_reciente } }`.

#### Scenario: Dashboard returns KPIs for authenticated user

- GIVEN users, productos, and entidades Propia exist in the database
- WHEN GET `/api/v1/seguridad/dashboard` with valid Bearer token
- THEN response MUST be 200 with `success: true`
- AND `data.kpi` MUST contain: `total_usuarios`, `usuarios_activos`, `total_productos`, `total_marcas`
- AND `data.distribucion_roles` MUST be an array of `{ rol: string, total: number }`
- AND `data.actividad_reciente` MUST be an array of recent system activity

#### Scenario: Unauthenticated request returns 401

- GIVEN no Bearer token
- WHEN GET `/api/v1/seguridad/dashboard`
- THEN response MUST be 401

### Requirement: KPIs reflect real-time system state

The system MUST query live counts from the database. KPIs SHALL NOT use cached data.

- `total_usuarios` = count of all `usuarios` records
- `usuarios_activos` = count of `usuarios` with `estado='Activo'`
- `total_productos` = count of all `productos` records
- `total_marcas` = count of `entidad` with `estado='Propia'`

#### Scenario: Empty database returns zero KPIs

- GIVEN no usuarios, productos, or entidades Propia exist
- WHEN GET `/api/v1/seguridad/dashboard`
- THEN all KPI values MUST be 0 (not null or absent)

### Requirement: Frontend SeguridadPage shows dashboard

The frontend MUST replace the SeguridadPage stub with a dashboard component that fetches `/api/v1/seguridad/dashboard` and renders KPIs as stat cards.

#### Scenario: Dashboard loads and displays KPIs

- GIVEN user navigates to `/seguridad`
- WHEN the page loads
- THEN 4 stat cards MUST display: Usuarios (total/activos), Productos (total), Marcas (total)
- THEN role distribution MUST render as a list or chart
- THEN recent activity MUST render as a timeline

#### Scenario: API error shows fallback message

- GIVEN the API is unreachable
- WHEN the dashboard loads
- THEN an error banner MUST appear: "Error al cargar dashboard de seguridad"
- AND KPI cards MUST show "—" instead of numeric values

### Requirement: Quick access navigation cards

The dashboard MUST include navigation cards linking to `/usuarios`, `/productos`, `/marcas` (new route), `/maestros`, and `/ciudades`.

#### Scenario: Click navigation card redirects

- GIVEN the dashboard is displayed
- WHEN user clicks a navigation card (e.g. "Gestionar Usuarios")
- THEN the browser navigates to the corresponding route
