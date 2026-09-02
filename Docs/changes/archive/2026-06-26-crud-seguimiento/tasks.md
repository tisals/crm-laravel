# Tasks: crud-seguimiento

## Backend tasks (crm-laravel)

### Phase 1: Canonical model

#### T-BE-01 · Create canonical Seguimiento model
- **Files**: `Modules/CRM/app/Models/Seguimiento.php` (NEW)
- **Action**: Create Eloquent model with `SoftDeletes`, `$table = 'seguimiento'`, `$fillable` matching the migration, `$casts` for `fecha` (date), `fecha_fin` (datetime), `hora` (string). Add `belongsTo` relations: `oportunidad()`, `contacto()`, `entidad()`, `autor()` (FK to `usuarios`).
- **Acceptance**: File exists, model is loadable via `Modules\CRM\Models\Seguimiento`, all 4 relations return the correct Eloquent models.

#### T-BE-02 · Confirm backward-compat alias
- **Files**: `app/Models/Seguimiento.php`
- **Action**: Update deprecation comment. Verify it still extends `Modules\CRM\Models\Seguimiento` (so existing code works).
- **Acceptance**: `app/Models\Seguimiento::find(1)` works without changes.

#### T-BE-03 · Test canonical model relations
- **Files**: `tests/Unit/Modules/CRM/Models/SeguimientoTest.php` (NEW)
- **Action**: PHPUnit test: create entidad, contacto, oportunidad, usuario, seguimiento. Load seguimiento and assert each relation returns the right model. Test soft-deletes work.
- **Acceptance**: Test passes.

### Phase 2: Autor auto-set

#### T-BE-04 · Modify StoreSeguimientoUseCase
- **Files**: `app/Application/UseCases/Seguimiento/StoreSeguimientoUseCase.php`
- **Action**: At the start of `execute()`, capture `Auth::id()`. Merge into the data passed to `repository->create()`: `autor_id` and `created_by`. Use `array_merge($data, [...])` so caller-provided values are overridden (defense-in-depth — but tests should also assert this).
- **Acceptance**: All seguimientos created via this use case have `autor_id` populated.

#### T-BE-05 · Test autor_id auto-set
- **Files**: `tests/Feature/UseCases/StoreSeguimientoUseCaseTest.php` (NEW) — or extend an existing test.
- **Action**: Test that creating a seguimiento with `Auth::login($user)` populates `autor_id = $user->id`. Test that providing `autor_id` in the request data is ignored (the server-side value wins).
- **Acceptance**: Test passes.

### Phase 3: Date validation

#### T-BE-06 · Modify SeguimientoRequest with fecha_fin rule
- **Files**: `app/Http/Requests/SeguimientoRequest.php`
- **Action**: Change the `fecha_fin` rule from `'nullable|date'` to `'nullable|date|after_or_equal:fecha'`. Add a Spanish error message: `'fecha_fin.after_or_equal' => 'La fecha de fin debe ser igual o posterior a la fecha de inicio.'`
- **Acceptance**: POST with `fecha_fin < fecha` returns 422.

#### T-BE-07 · Test fecha_fin validation
- **Files**: `tests/Feature/API/SeguimientoRequestValidationTest.php` (NEW)
- **Action**: Three cases: valid range (201 + stored), invalid range (422 + message), only fecha provided (201 + fecha_fin null).
- **Acceptance**: All 3 tests pass.

### Phase 4: Notification routing

#### T-BE-08 · Add resolveRecipients helper
- **Files**: `app/Http/Controllers/API/ContactoAccionController.php`
- **Action**: Add a private `resolveRecipients(Seguimiento $seguimiento): Collection` method. Query 1: `Usuario::where('rol_id', rol_nombre=Comercial)->whereIn('id', entidad_usuario WHERE entidad_id=?)->where('estado', 'Activo')`. If empty, query 2: `Usuario::admins()->get()`. If both empty, return empty collection.
- **Acceptance**: Method returns the right users per the spec.

#### T-BE-09 · Update ContactoAccionController to use resolveRecipients
- **Files**: `app/Http/Controllers/API/ContactoAccionController.php`
- **Action**: Replace `Notification::send(Usuario::admins()->get(), new FollowUpNotification($seguimiento))` with: `resolveRecipients()` → if empty, `Log::warning(...)` and return → else `Notification::send($recipients, new FollowUpNotification($seguimiento))`.
- **Acceptance**: Notifications only go to mapped comercials (or admins as fallback).

#### T-BE-10 · Test notification routing
- **Files**: `tests/Feature/Notifications/FollowUpNotificationRoutingTest.php` (NEW)
- **Action**: Use `Notification::fake()`. Create escenario with 1 comercial mapped → assert notification sent to that comercial, NOT to others. Create escenario with NO comercial mapped, 2 admins → assert sent to admins. Create escenario with no recipients → assert warning log + no notification.
- **Acceptance**: All 3 scenarios pass.

### Phase 5: Repository extensions

#### T-BE-11 · Extend repository interface
- **Files**: `app/Domain/Repositories/SeguimientoRepositoryInterface.php`
- **Action**: Add 2 method signatures:
  - `findForUser(int $userId, int $perPage, ?string $search, array $filters): LengthAwarePaginator`
  - `findCalendarForUser(int $userId, string $fechaDesde, string $fechaHasta): Collection`
- **Acceptance**: Interface compiles.

#### T-BE-12 · Implement new repository methods
- **Files**: `app/Infrastructure/Persistence/EloquentSeguimientoRepository.php`
- **Action**: Implement the two new methods. For `findForUser`: same query builder pattern as the existing `paginate()`, with an additional `whereIn('entidad_id', $user->entidades()->pluck('id'))` clause. For `findCalendarForUser`: SELECT seguimientos WHERE fecha BETWEEN ? AND ? AND entidad_id IN (...) ORDER BY fecha, hora. Use Carbon for date handling.
- **Acceptance**: Both methods return expected types.

#### T-BE-13 · Test new repository methods
- **Files**: `tests/Unit/Infrastructure/Persistence/EloquentSeguimientoRepositoryTest.php` (extend)
- **Action**: Test `findForUser` returns seguimientos for the user's entidades only. Test `findCalendarForUser` filters by date range and entities.
- **Acceptance**: Tests pass.

### Phase 6: New endpoints + use cases

#### T-BE-14 · Create ListarMisSeguimientosUseCase
- **Files**: `app/Application/UseCases/Seguimiento/ListarMisSeguimientosUseCase.php` (NEW)
- **Action**: Thin wrapper around `repository->findForUser($userId, $perPage, $search, $filters)`. Inject the user via `Auth::id()` or accept it as parameter.
- **Acceptance**: Use case returns paginated result scoped to user's entidades.

#### T-BE-15 · Create ObtenerCalendarioUsuarioUseCase
- **Files**: `app/Application/UseCases/Seguimiento/ObtenerCalendarioUsuarioUseCase.php` (NEW)
- **Action**: Calls `repository->findCalendarForUser($userId, $fechaDesde, $fechaHasta)`. Returns the collection (caller groups by day).
- **Acceptance**: Returns seguimientos in date range.

#### T-BE-16 · Add `misSeguimientos` controller action
- **Files**: `app/Http/Controllers/API/SeguimientoController.php`
- **Action**: Add `misSeguimientos(Request $request): JsonResponse`. Determines scope by rol: Comercial → uses `ListarMisSeguimientosUseCase` with `entidad_ids` filter; Admin/SuperAdmin → uses existing `indexUseCase`. Returns the paginator.
- **Acceptance**: Endpoint responds correctly for both roles.

#### T-BE-17 · Add `calendarioUsuario` controller action
- **Files**: `app/Http/Controllers/API/SeguimientoController.php`
- **Action**: Add `calendarioUsuario(Request $request, int $usuarioId): JsonResponse`. Authorization check: if `$authUser->id !== $usuarioId` AND not admin, return 403. Otherwise, call use case, group by day, return `{ mes, dias }`.
- **Acceptance**: Endpoint responds 200 for self/admin, 403 for cross-user without admin.

#### T-BE-18 · Test GET /seguimientos/mios
- **Files**: `tests/Feature/API/SeguimientosMiosEndpointTest.php` (NEW)
- **Action**: 3 cases: Comercial with assigned entidades → only own; Admin → all; filters compose (estado + fecha_desde).
- **Acceptance**: Tests pass.

#### T-BE-19 · Test GET /usuarios/{id}/calendario
- **Files**: `tests/Feature/API/SeguimientoCalendarioEndpointTest.php` (NEW)
- **Action**: 4 cases: self → 200 grouped by day; cross-user without admin → 403; admin → 200 for any user; invalid mes param → 422.
- **Acceptance**: Tests pass.

#### T-BE-20 · Verify existing tests still pass
- **Files**: (no change)
- **Action**: Run `docker exec crm-laravel-dev php artisan test --filter=SeguimientoControllerTest`. All 11 existing tests must pass.
- **Acceptance**: 11 tests pass.

## Frontend tasks (dashboard-crm)

### Phase 7: API client

#### T-FE-01 · Verify Seguimiento type
- **Files**: `src/api/types.ts`
- **Action**: Confirm `Seguimiento` type has `entidad_nombre?`, `contacto_nombre?`, `oportunidad_codigo?`. If not present, add them as optional fields.
- **Acceptance**: Type compiles.

#### T-FE-02 · Add getMisSeguimientos
- **Files**: `src/api/crmApi.ts`
- **Action**: Add `getMisSeguimientos(params)` method. Calls `GET /seguimientos/mios` with the params. Returns paginated response.
- **Acceptance**: Method exists and types check.

#### T-FE-03 · Add getCalendarioUsuario
- **Files**: `src/api/crmApi.ts`
- **Action**: Add `getCalendarioUsuario(usuarioId, mes)` method. Calls `GET /usuarios/{usuarioId}/calendario?mes=YYYY-MM`. Returns `{ mes, dias }`.
- **Acceptance**: Method exists and types check.

#### T-FE-04 · Add buildSeguimientoIcsUrl
- **Files**: `src/api/crmApi.ts`
- **Action**: Export `buildSeguimientoIcsUrl(id: number): string`. Returns `/api/v1/seguimientos/${id}/ics` (relative; axios baseURL is already set).
- **Acceptance**: Returns a string.

### Phase 8: Calendar utility

#### T-FE-05 · Create getMonthMatrix utility
- **Files**: `src/utils/getMonthMatrix.ts` (NEW)
- **Action**: Pure function `getMonthMatrix(year: number, month: number): (Date | null)[][]`. Returns 6 rows of 7 columns. Days outside the month are `null`. Monday-first week. Handle leap years (Feb 2028 has 29 days).
- **Acceptance**: Function compiles.

#### T-FE-06 · Test getMonthMatrix
- **Files**: `src/utils/getMonthMatrix.test.ts` (NEW)
- **Action**: Tests:
  - June 2026 starts on Monday (6 rows of 7 = 42 cells, June occupies 30, last 12 are null)
  - Feb 2028 (leap) has 29 days
  - December 2026 starts on Tuesday
  - Returns exactly 6 rows × 7 columns always
- **Acceptance**: Tests pass.

### Phase 9: SeguimientosPage upgrades

#### T-FE-07 · Add debounce to SeguimientosPage
- **Files**: `src/pages/SeguimientosPage.tsx`
- **Action**: Add local `searchInput` state. `const debouncedSearch = useDebouncedValue(searchInput, 1000)`. Use `debouncedSearch` in the `useQuery` queryKey and the queryFn's `search` param. Sync `searchInput` to `filters.search` via useEffect when debouncedSearch changes.
- **Acceptance**: Rapid typing fires the query once after 1s of inactivity.

#### T-FE-08 · Refactor SeguimientosPage to cards view
- **Files**: `src/pages/SeguimientosPage.tsx`
- **Action**: Group seguimientos by `estado` (Pendiente, Agendado, Completado, Cancelado). Render each group as a section. Each card displays: tipo badge, fecha+hora, notas (line-clamp-2), `entidad_nombre` (if present), `oportunidad_codigo` (if present), estado badge, action buttons.
- **Acceptance**: Cards render correctly grouped by estado, with empresa + oportunidad visible.

#### T-FE-09 · Add "Exportar a calendario (.ics)" button per card
- **Files**: `src/pages/SeguimientosPage.tsx`
- **Action**: Add a button next to edit/delete. On click: `window.open(buildSeguimientoIcsUrl(s.id), '_blank')`. Button icon: `Download` or `Calendar` from lucide-react.
- **Acceptance**: Clicking opens the .ics file in a new tab.

### Phase 10: Calendar page

#### T-FE-10 · Create SeguimientoCalendarioPage
- **Files**: `src/pages/SeguimientoCalendarioPage.tsx` (NEW)
- **Action**: Page that renders the month grid for the current user. State: `currentMes: string = currentYYYY-MM`. Query: `useQuery(['calendario', userId, currentMes], () => getCalendarioUsuario(userId, currentMes))`. Render: prev/next month buttons + 7-column grid + day click → side panel.
- **Acceptance**: Page loads, shows the current month, navigation works.

#### T-FE-11 · Add User selector to calendar (admin only)
- **Files**: `src/pages/SeguimientoCalendarioPage.tsx`
- **Action**: If current user is admin, render a `<select>` with all comercials. Default: current user. On change, refetch with new usuarioId.
- **Acceptance**: Admin can switch between comercials' calendars; Comercial sees only their own (no selector).

### Phase 11: "+ Seguimiento" button

#### T-FE-12 · Add "+ Seguimiento" button to kanban card
- **Files**: `src/pages/CRMPage.tsx`
- **Action**: In the kanban card component, add a small icon button `<CalendarPlus size={14} />` with tooltip "Registrar seguimiento". On click: `e.stopPropagation(); setSeguimientoModal({ oportunidadId: opp.id, entidadId: opp.entidad_id })`. State: `const [seguimientoModal, setSeguimientoModal] = useState<{ oportunidadId: number; entidadId?: number } | null>(null)`. When set, render `<SeguimientoModal {...seguimientoModal} ... />`.
- **Acceptance**: Button visible on hover, click opens modal pre-filled.

#### T-FE-13 · Add "+ Seguimiento" button to detail side panel
- **Files**: `src/pages/CRMPage.tsx`
- **Action**: In the detail side panel header (where versionar, clonar, eliminar are), add the same "+ Seguimiento" button.
- **Acceptance**: Button visible in both kanban card and detail panel.

#### T-FE-14 · Modify SeguimientoModal to accept pre-fill props
- **Files**: `src/components/SeguimientoModal.tsx`
- **Action**: Add props `oportunidadId?: number; contactoId?: number; entidadId?: number`. Initialize the corresponding form fields. Show in title: "Nuevo seguimiento para {codigo}" or "Nuevo seguimiento para {nombre}".
- **Acceptance**: Modal opens pre-filled when called from kanban/contacto/entity context.

### Phase 12: Routing

#### T-FE-15 · Add /seguimientos/calendario route
- **Files**: `src/App.tsx` (or wherever routes are defined)
- **Action**: Add `<Route path="/seguimientos/calendario" element={<SeguimientoCalendarioPage />} />`. Add a nav link from `SeguimientosPage` (tab switcher).
- **Acceptance**: Navigating to `/seguimientos/calendario` shows the calendar page.

## Frontend tests

#### T-FE-16 · Test debounced search in SeguimientosPage
- **Files**: `src/pages/SeguimientosPage.test.tsx` (NEW or extend existing)
- **Action**: Render with mocked `getSeguimientos`. Type 5 chars in 200ms. Advance timers by 1000ms. Assert `getSeguimientos` was called ONCE with the final search term.
- **Acceptance**: Test passes.

#### T-FE-17 · Test SeguimientoCalendarioPage renders month grid
- **Files**: `src/pages/SeguimientoCalendarioPage.test.tsx` (NEW)
- **Action**: Mock `getCalendarioUsuario` to return a sample month payload. Assert the grid renders 6 rows × 7 columns, day cells show day numbers, and clicking a day opens the side panel with that day's seguimientos.
- **Acceptance**: Test passes.

#### T-FE-18 · Test kanban "+ Seguimiento" button opens modal
- **Files**: `src/pages/CRMPage.test.tsx` (NEW or extend)
- **Action**: Render the kanban with a sample oportunidad. Click the "+ Seguimiento" button on the card. Assert the modal opens with `oportunidad_id` pre-filled.
- **Acceptance**: Test passes.

## Integration tasks

#### T-INT-01 · Run full test suite
- **Action**: Run `docker exec crm-laravel-dev php artisan test` (PHPUnit, all tests). Run `cd dashboard-crm && npm run typecheck && npm run test && npm run build`. All must be green.
- **Acceptance**: 0 failures.

#### T-INT-02 · Manual end-to-end test in Docker
- **Action**: Run `docker exec crm-laravel-dev php artisan migrate:fresh --seed --force`. Use the frontend dev server (already running) or curl the API. Verify:
  1. Create seguimiento via UI → `autor_id` populated
  2. Create seguimiento with `fecha_fin < fecha` → 422
  3. Notification goes only to mapped comercial
  4. `/seguimientos/mios` as Comercial → only own
  5. Cards view shows empresa + oportunidad
  6. Calendar view renders month grid
  7. ICS export downloads valid .ics
- **Acceptance**: All 7 verifications pass.

#### T-INT-03 · Commit backend changes
- **Action**: `git add` all backend files. Conventional commit: `feat(crm): close 8 seguimiento gaps + add cards/calendar views API`.
- **Acceptance**: Commit on `main`, pushed.

#### T-INT-04 · Commit frontend changes
- **Action**: `git add` all frontend files (in dashboard-crm). Conventional commit: `feat(crm): cards + calendar view for seguimiento with debounced search`.
- **Acceptance**: Commit on `main`, pushed.

## Task summary

| Phase | Backend | Frontend | Tests |
|-------|---------|----------|-------|
| 1. Canonical model | T-BE-01..02 | — | T-BE-03 |
| 2. Autor auto-set | T-BE-04 | — | T-BE-05 |
| 3. Date validation | T-BE-06 | — | T-BE-07 |
| 4. Notification routing | T-BE-08..09 | — | T-BE-10 |
| 5. Repository extensions | T-BE-11..12 | — | T-BE-13 |
| 6. New endpoints | T-BE-14..17 | — | T-BE-18..19, T-BE-20 |
| 7. API client | — | T-FE-01..04 | — |
| 8. Calendar utility | — | T-FE-05 | T-FE-06 |
| 9. Cards view | — | T-FE-07..09 | T-FE-16 |
| 10. Calendar page | — | T-FE-10..11 | T-FE-17 |
| 11. "+ Seguimiento" | — | T-FE-12..14 | T-FE-18 |
| 12. Routing | — | T-FE-15 | — |
| Integration | T-INT-01..03 | T-INT-04 | T-INT-02 |

**Total**: 38 tasks (20 backend, 14 frontend, 4 integration).

**Critical path** (must finish in order): T-BE-01 → T-BE-03 → T-BE-04 → T-BE-05 → T-BE-06 → T-BE-07 → T-BE-08 → T-BE-09 → T-BE-10 → T-BE-11 → T-BE-12 → T-BE-13 → T-BE-14..17 → T-BE-18..19 → T-BE-20 → T-FE-* → T-INT-01..04.

**Parallelizable**:
- T-BE-08..09 can be parallel with T-BE-11..13 (different files)
- T-FE-01..04 can be parallel with T-FE-05..06 (different files)
- T-FE-12..14 can be parallel with T-FE-10..11
