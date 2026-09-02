# Proposal: Módulo Seguridad

## Intent

Convertir el stub de SeguridadPage en un módulo funcional que centralice la gestión de seguridad del CRM: dashboard con KPIs del sistema, administración de marcas (entidades Propia) y CRUD completo de productos. Hoy Seguridad es una página vacía, Productos es read-only sin crear/editar/eliminar, y las marcas (Propia) solo existen via seeder sin UI de gestión.

## Scope

### In Scope
- **Security Dashboard**: Nuevo endpoint backend (`SecurityDashboardController` + `GetSecurityDashboardUseCase`) con KPIs del módulo seguridad + frontend dashboard reemplazando stub de SeguridadPage
- **CRUD Marcas (Propia)**: UI para crear, editar y eliminar entidades con `estado='Propia'` (marcas internas tipo Tecnoinnsoft, Deseguridad.dev). Reusa EntidadController existente con filtro `estado=Propia`.
- **CRUD Productos**: Agregar create/edit/delete a ProductosPage (backend ya tiene endpoints). Modal con SlidePanel siguiendo patrón UsuariosPage.
- **CRUD Maestros**: Backend: nuevos endpoints store/update/destroy + tests. Frontend: crear/editar/eliminar en MaestrosPage.
- **CRUD Ciudades**: Backend: nuevos endpoints store/update/destroy + tests. Frontend: crear/editar/eliminar en CiudadesPage.

### Out of Scope
- Reorganización del Sidebar (los módulos ya están agrupados correctamente)
- Multi-tenancy o reporting avanzado

## Capabilities

### New Capabilities
- `seguridad-dashboard`: Nuevo endpoint backend `GET /api/v1/seguridad/dashboard` con KPIs (usuarios totales/activos, total productos, total marcas, distribución de roles, actividad reciente) + frontend dashboard reemplazando stub de SeguridadPage
- `marcas-gestion`: CRUD de entidades con estado 'Propia' — tabla listado + modal create/edit + confirmación delete. Filtro `estado=Propia` via parámetro existente en `GET /entidad`

### Modified Capabilities
- `productos`: Pasa de read-only a CRUD completo. Se agregan `createProducto`, `updateProducto`, `deleteProducto` en crmApi.ts + ProductoFormModal con SlidePanel
- `maestros`: Pasa de read-only a CRUD completo. Backend: nuevos endpoints POST/PUT/DELETE con tests. Frontend: modal create/edit + delete en MaestrosPage
- `ciudades`: Pasa de read-only a CRUD completo. Backend: nuevos endpoints POST/PUT/DELETE con tests. Frontend: modal create/edit + delete en CiudadesPage

## Approach

**Backend-first (nuevos endpoints) + Frontend CRUD**. Se requieren nuevos endpoints backend para seguridad dashboard, maestros y ciudades. Productos y marcas reusan endpoints existentes.

### Backend
1. **SecurityDashboardController**: Nuevo `GET /api/v1/seguridad/dashboard` con `GetSecurityDashboardUseCase` → total usuarios, usuarios activos, total productos, total marcas, distribución de roles, actividad reciente del sistema
2. **MaestroController**: Agregar `store`, `update`, `destroy` + FormRequest validation + tests
3. **CiudadController**: Agregar `store`, `update`, `destroy` + FormRequest validation + tests
4. Tests para los nuevos endpoints

### Frontend
1. **API layer**: Agregar funciones en `crmApi.ts` + tipos en `types.ts`
2. **SeguridadDashboardPage**: Nueva page con KPIs + acceso rápido a secciones
3. **MarcasPage**: CRUD marcas Propia (reusa EntidadController)
4. **ProductosPage**: + create/edit/delete (backend ya existe)
5. **MaestrosPage**: + create/edit/delete (nuevos endpoints backend)
6. **CiudadesPage**: + create/edit/delete (nuevos endpoints backend)

**UI patterns** (validados en UsuariosPage):
- SlidePanel (30% width) para formularios create/edit
- Mutations con invalidación de queries via `useQueryClient`
- Confirm window.confirm() para deletes
- Tema oscuro consistente (bg-slate-800 cards, teal-500/600 accent)

## Affected Areas

### Backend
| Area | Impact | Description |
|------|--------|-------------|
| `app/Http/Controllers/API/SecurityDashboardController.php` | New | Nuevo controller para KPIs de seguridad |
| `app/Application/UseCases/Seguridad/GetSecurityDashboardUseCase.php` | New | UseCase para estadísticas del módulo seguridad |
| `app/Http/Controllers/API/MaestroController.php` | Modified | +store, update, destroy |
| `app/Http/Requests/MaestroRequest.php` | New | FormRequest para validación |
| `app/Http/Controllers/API/CiudadController.php` | Modified | +store, update, destroy |
| `app/Http/Requests/CiudadRequest.php` | New | FormRequest para validación |
| `routes/api.php` | Modified | +rutas seguridad dashboard, +rutas maestros CRUD, +rutas ciudades CRUD |
| `tests/Feature/API/SecurityDashboardTest.php` | New | Tests para dashboard seguridad |
| `tests/Feature/API/MaestroControllerTest.php` | New | Tests para CRUD maestros |
| `tests/Feature/API/CiudadControllerTest.php` | Modified | +tests para create/update/delete |

### Frontend
| Area | Impact | Description |
|------|--------|-------------|
| `dashboard-crm/src/api/crmApi.ts` | Modified | +createProducto, updateProducto, deleteProducto, +getMarcas, +createMaestro, updateMaestro, deleteMaestro, +createCiudad, updateCiudad, deleteCiudad |
| `dashboard-crm/src/api/types.ts` | Modified | +ProductoCreate, MaestroCreate, CiudadCreate types |
| `dashboard-crm/src/pages/ModulosPages.tsx` | Modified | Reemplazar stub SeguridadPage con dashboard real |
| `dashboard-crm/src/pages/SeguridadDashboardPage.tsx` | New | Dashboard con KPIs + acceso rápido |
| `dashboard-crm/src/pages/MarcasPage.tsx` | New | CRUD marcas Propia |
| `dashboard-crm/src/components/MarcaFormModal.tsx` | New | Modal create/edit marca |
| `dashboard-crm/src/pages/ProductosPage.tsx` | Modified | +CRUD con ProductoFormModal |
| `dashboard-crm/src/components/ProductoFormModal.tsx` | New | Modal create/edit producto |
| `dashboard-crm/src/pages/MaestrosPage.tsx` | Modified | +CRUD con create/edit/delete |
| `dashboard-crm/src/pages/CiudadesPage.tsx` | Modified | +CRUD con create/edit/delete |
| `dashboard-crm/src/App.tsx` | Modified | +ruta `/marcas` |
| `dashboard-crm/src/pages/index.ts` | Modified | Exportar nuevas pages |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Marcas (Propia) dentro de Seguridad confunde vs Directorio | Medium | Nombrar "Marcas / Propia" en UI, aclarar que son entidades internas de la empresa, no clientes |
| Maestros no tiene Clean Architecture (usa Eloquent directo) | Medium | Para CRUD nuevo mantener mismo patrón existente o refactorizar si da tiempo |
| Ciudades tiene 1122 registros — crear/editar desde UI es ok pero el listado necesita paginación | Low | Ya existe paginación en CiudadController, reusar |
| `estado='Propia'` no está tipado en Entidad frontend | Medium | Extender type Entidad para aceptar `'Propia'` como estado válido |

## Rollback Plan

1. Revertir backend: eliminar SecurityDashboardController, revertir MaestroController/CiudadController a read-only, eliminar nuevas rutas
2. Revertir frontend: restaurar crmApi.ts, types.ts, pages modificados
3. Eliminar pages nuevas (MarcasPage, SeguridadDashboardPage) y componentes nuevos

## Dependencies

- Backend `ProductoController` ya implementado con store/update/destroy
- Backend `EntidadController` ya acepta filtro `estado` via query params
- Backend `MaestroController` actual: solo index/show — necesita store/update/destroy
- Backend `CiudadController` actual: solo index/show — necesita store/update/destroy
- Routes API protegidas con `auth:sanctum` y RBAC según módulo
- No requiere nuevas migraciones

## Success Criteria

- [ ] `GET /api/v1/seguridad/dashboard` devuelve KPIs correctos (200 + estructura envelope)
- [ ] Maestros CRUD: crear, editar y eliminar desde backend y frontend
- [ ] Ciudades CRUD: crear, editar y eliminar desde backend y frontend
- [ ] Productos CRUD: crear, editar y eliminar desde frontend
- [ ] Marcas CRUD: crear, editar y eliminar entidades Propia desde frontend
- [ ] SeguridadPage reemplazada por dashboard con KPIs funcionales
- [ ] Todos los tests nuevos pasan (backend)
- [ ] SlidePanel + mutations con invalidación funcionan en todos los CRUDs
- [ ] Dark theme consistente con el resto del CRM
