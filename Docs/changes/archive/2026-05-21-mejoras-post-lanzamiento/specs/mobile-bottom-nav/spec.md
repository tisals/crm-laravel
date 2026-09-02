# mobile-bottom-nav Specification

## Purpose

Replace the flat 5-item bottom navigation with a 3-group navigation (CRM, ERP, Seguridad). Each group button opens a hamburger-style submenu listing that group's pages, filtered by the user's role.

## Requirements

### Requirement: Bottom nav shows 3 group buttons

The BottomNav component MUST display exactly 3 buttons: CRM, ERP, Seguridad — regardless of the user's role.

#### Scenario: Three buttons visible

- GIVEN a mobile viewport (< md breakpoint)
- WHEN the BottomNav renders
- THEN it shows 3 buttons: "CRM", "ERP", "Seguridad"
- AND each button has a distinct icon

#### Scenario: Desktop hidden

- GIVEN a desktop viewport (>= md breakpoint)
- WHEN the page renders
- THEN the BottomNav is hidden

### Requirement: Group button opens submenu

Tapping a group button MUST open a submenu (slide-up panel) showing that group's pages, filtered by the user's role.

#### Scenario: CRM submenu (comercial)

- GIVEN a user with role `comercial` on mobile
- WHEN they tap "CRM"
- THEN a submenu slides up showing: Dashboard, Directorio, Contactos, Oportunidades
- AND each item is a nav link that navigates on tap and closes the submenu

#### Scenario: Seguridad submenu (superadmin)

- GIVEN a user with role `super_admin` on mobile
- WHEN they tap "Seguridad"
- THEN the submenu shows: Seguridad, Maestros, Ciudades, Productos, Usuarios

#### Scenario: Role-filtered submenu

- GIVEN a user with role `comercial`
- WHEN they tap "ERP"
- THEN the submenu shows a message like "No tienes acceso a esta sección"
- AND no navigation items are shown

### Requirement: Submenu closes on navigation

Tapping any nav item in a submenu MUST navigate to that page AND close the submenu.

#### Scenario: Navigate and close

- GIVEN the CRM submenu is open
- WHEN the user taps "Directorio"
- THEN the app navigates to `/directorio`
- AND the bottom nav submenu closes
