# menu-reorganization Specification

## Purpose

Reorganize the sidebar into 3 groups (CRM, ERP, Seguridad) with the correct module-to-role mapping, add new modules CONTACTOS, CIUDADES, PRODUCTOS, and enable MAESTROS.

## Requirements

### Requirement: Sidebar has 3 groups

The sidebar MUST display navigation in 3 groups with the following modules:

- **CRM**: Dashboard CRM, Directorio, Contactos, Oportunidades → visible for Comercial, Superadmin
- **ERP**: Dashboard ERP, Talento, Finanzas, Operaciones → visible for Admin, Superadmin
- **Seguridad**: Seguridad, Maestros, Ciudades, Productos, Usuarios → visible for Superadmin, Admin

#### Scenario: Superadmin sees all modules

- GIVEN a user with role `super_admin`
- WHEN the sidebar renders
- THEN all 3 groups are visible with all 12+ modules
- AND each module links to the correct route

#### Scenario: Comercial sees only CRM group

- GIVEN a user with role `comercial`
- WHEN the sidebar renders
- THEN only the CRM group is visible
- AND it shows: Dashboard, Directorio, Contactos, Oportunidades
- AND the ERP and Seguridad groups are hidden

#### Scenario: Admin sees ERP and Seguridad

- GIVEN a user with role `admin`
- WHEN the sidebar renders
- THEN CRM group is hidden
- AND ERP group shows: Dashboard, Talento, Finanzas, Operaciones
- AND Seguridad group shows: Seguridad, Maestros, Ciudades, Productos, Usuarios

### Requirement: New modules have page routes

The system MUST provide routes and placeholder pages for CONTACTOS, CIUDADES, PRODUCTOS, MAESTROS.

#### Scenario: Routes exist

- GIVEN a user navigates to `/contactos`, `/ciudades`, `/productos`, `/maestros`
- WHEN the route resolves
- THEN the corresponding page component renders
- AND no 404 or blank screen is shown

### Requirement: MAESTROS module enabled

The sidebar MUST NOT filter out MAESTROS (remove the `m !== MODULES.MAESTROS` filter in Sidebar.tsx).

#### Scenario: Maestros visible for super_admin

- GIVEN a user with role `super_admin`
- WHEN the sidebar renders
- THEN "Maestros" appears in the Seguridad group

### Requirement: Roles updated in roles.ts

The `roles.ts` MUST include modules lists for each role that match the new group structure.

#### Scenario: Role modules match groups

- GIVEN the `super_admin` role
- THEN its modules array includes ALL modules including CONTACTOS, CIUDADES, PRODUCTOS, MAESTROS
- GIVEN the `comercial` role
- THEN its modules include only: DASHBOARD, DIRECTORIO, CRM, plus new CONTACTOS
- GIVEN the `admin` role
- THEN its modules include: DASHBOARD, SEGURIDAD, MAESTROS, DIRECTORIO, TALENTO, FINANZAS, OPERACIONES, plus new CIUDADES, PRODUCTOS, USUARIOS
