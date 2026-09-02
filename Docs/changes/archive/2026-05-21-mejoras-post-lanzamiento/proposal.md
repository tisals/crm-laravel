# Proposal: Mejoras Post-Lanzamiento

## Intent

Resolver 7 tickets de mejora post-MVP: seeders desde CSV reales, filtros backend correctos, reorganización de menú lateral, navegación mobile rediseñada, y fixes del módulo de oportunidades (cards, seguimiento, IVA, ICS, envío cotización).

## Scope

### In Scope
1. **CSV→Seeders**: Reemplazar seeders dummy con datos reales desde Docs/*.csv (ciudades, contactos, entidades, productos, maestros)
2. **Directorio Filter**: Corregir filtro backend (estado case-insensitive), enviar estado desde frontend
3. **Menu Reorganization**: Nuevos módulos CONTACTOS, CIUDADES, PRODUCTOS, MAESTROS en sidebar
4. **Mobile Bottom Nav**: 3 botones de grupo (CRM, ERP, Seguridad) con submenús hamburger
5. **Opportunity Fixes**: 7a (cards UX), 7b (seguimiento refresh), 7c (IVA en save), 7d (ICS param), 7e (send quote UI)

### Out of Scope
- Roles/permisos completos (solo definir necesarios para nuevo menú)
- Tests E2E
- Migración de esquema legacy contacts/organizations
- Módulo de reporting

## Capabilities

### New Capabilities
- `csv-seeder`: Seeders que importan datos reales desde CSV con sanitización (Excel artifacts, fechas)
- `directorio-filter`: Filtro backend por estado con case-insensitive match + frontend envía estado
- `menu-reorganization`: Sidebar con módulos CONTACTOS, CIUDADES, PRODUCTOS, MAESTROS
- `mobile-bottom-nav`: 3 group buttons con submenús hamburger
- `send-quote-ui`: Email composition UI con selección de contacto y texto personalizado guardado en seguimiento

### Modified Capabilities
- `cotizacion-editor`: IVA auto-calculado al crear línea (no requiere POST extra), seguimiento timeline refresca tras quick-save, DetalleLineEditor corrige UX de valor 0
- `email-cotizacion`: Endpoint `/enviar` acepta JSON con `contacto_id`, `mensaje_personalizado`; crea seguimiento con el texto personalizado

## Approach

Backend-first: seeders → fixes de filtro/lógica → frontend restructuring → fixes de UI. Cada ticket se implementa como PR independiente dentro del mismo change. Seeders se ejecutan con `db:seed --class=RealDataSeeder` (nuevo, no modifica DatabaseSeeder). Frontend restructuring usa el patrón `moduleConfig` existente en el sidebar.

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `database/seeders/RealDataSeeder.php` | New | Seeders desde CSV con sanitización |
| `app/Infrastructure/Persistence/*` | Modified | Filtro estado case-insensitive en repositorios |
| `dashboard-crm/src/components/Sidebar.tsx` | Modified | Nuevos módulos CONTACTOS, CIUDADES, PRODUCTOS |
| `dashboard-crm/src/components/MobileNav.tsx` | New | Bottom nav con grupos y submenús |
| `dashboard-crm/src/pages/CRMPage.tsx` | Modified | Fix cards UX, seguimiento refresh key |
| `dashboard-crm/src/components/DetalleLineEditor.tsx` | Modified | IVA auto-calc, UX valor 0 |
| `app/Http/Controllers/API/*` | Modified | ICS param fix, send quote payload |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| CSV Excel artifacts corrupt seed | Medium | Sanitize en seeder: strip `#¿NOMBRE?`, trim empty rows, parse dd/mm/YYYY |
| Menu reorg rompe navegación existente | Medium | Mantener rutas viejas como redirects; probar c/ módulo individual |
| Mobile nav sin diseño previo | Low | Baseline funcional primero; iterar diseño después |
| Send quote 422 por validación | Low | Validar `contacto_id` existe y `contacto.email_contacto` no vacío antes de enviar |

## Rollback Plan

Por ticket: revertir commits individualmente (`git revert <sha>`). Seeders no破坏 datos existentes (solo insertan). Mobile nav es archivo nuevo — eliminar. Menu reorg: restaurar `Sidebar.tsx` + `moduleConfig` anterior.

## Dependencies

- CSVs en `Docs/` con formato semicolon-delimited (verificar delimitador real)
- Endpoint `GET /api/v1/productos` existente para DetalleLineEditor
- Endpoint `POST /api/v1/oportunidades/{id}/enviar` existente (modificar payload)

## Success Criteria

- [ ] `php artisan db:seed --class=RealDataSeeder` inserta ~2000 ciudades, ~296 contactos, 144 entidades, 37 productos sin errores
- [ ] Directorio filter retorna entidades por estado correctamente (case-insensitive)
- [ ] Sidebar muestra CONTACTOS, CIUDADES, PRODUCTOS, MAESTROS y navega correctamente
- [ ] Mobile nav muestra 3 botones de grupo con submenús funcionales
- [ ] DetalleLineEditor calcula IVA automáticamente al crear línea
- [ ] Seguimiento timeline refresca tras quick-save
- [ ] ICS calendar recibe entidad_id correcto
- [ ] Send quote permite redactar email y guarda texto en seguimiento
