## ADD paso 3 Refinar elemento CQRS:
### Estado actual del proyecto
|Capa | Stack
|---|----|
|Backend|Laravel 12 monolith, Clean Architecture (Domain/Application/Infrastructure/Interfaces)|
|DB|MariaDB prod, dev (con :memory: para tests)|
|Auth|Sanctum 4.3 (token-based)|
|Cache/Queue/Session|driver database (default)|
|Módulos|nwidart/laravel-modules (CRM, Shared, Administrativo, Proyectos)|
|Team|1 dev full-stack|
|SLOs|login < 1s, OS de calidad (99.5%+ availability)|
-----
### Análisis CQRS — Fit para este proyecto
#### Definición operativa
CQRS puro = separar write model (commands, aggregates, transactional) de read model (queries, projections, eventualmente consistentes). A veces acompañado de Event Sourcing (el estado se reconstruye desde eventos).
No es solo "separar repos". Implica:
- Dos modelos de datos distintos (write DB vs read DB)
- Eventos de dominio como contrato entre ambos
- Proyecciones que actualizan el read model desde eventos
- Eventual consistency (lecturas ven datos viejos hasta que la proyección procesa el evento)
#### Lo que CQRS resuelve bien
|Problema| Cómo lo resuelve CQRS|
|---|---|
|Latencia de lectura <100ms con DB grande| Read model desnormalizado sin JOINs|
|Workloads asimétricos (muchas lecturas, pocas escrituras)| Escalar reads independientemente|
|Multiples vistas del mismo dato| Cada consumer tiene su proyección|
|Audit trail natural| Event store es append-only|
|Resiliencia de lectura| Reads siguen funcionando si write DB está caído|
----------

#### Fit con tu SLO de login < 1s
Login involucra:
1. Validar credenciales (1 query)
2. Generar token Sanctum (1 INSERT)
3. Cachear info de usuario (1 SET)
4. Devolver respuesta

**Con CQRS**, esto se ve así:
- Login escribe UserLoggedIn event
- Auth projection actualiza auth_cache (Redis)
- Validaciones futuras (validate-token) leen de Redis con 0 queries

**Beneficio real**: validación de token (la operación caliente) baja de ~80ms a ~5ms. Login no cambia mucho.

Los costos reales de CQRS puro

|Costo| Impacto en este proyecto|
|----|----|
|2x infra (write DB + read DB + event bus + Redis + projection workers)| $$$ mensuales, mantenimiento|
|Eventual consistency (5-60s de lag entre write y read)| UX bug clásico: usuario crea un contacto, no aparece en su lista hasta que se reprocesa el evento. ¿Tu SLO de calidad lo banca?|
|Debugging (split brain — el bug puede estar en el write model, en el evento, en la proyección, o en el read model)| 1 dev = 4x tiempo de resolución|
|Refactor masivo (Limpia el código, pero rompe tests y oculta bugs en reescritura de handlers)| Meses de riesgo|
|Migración del código existente (todo el Clean Architecture actual se vuelve "legacy" o se reescribe)| Alto riesgo de regresiones|
|Onboarding futuro (si crecés el equipo)| Cualquier dev nuevo tiene que aprender el modelo mental|
|Pruebas E2E (eventual consistency rompe los tests determinísticos)| Tienes que reescribir RefreshDatabase|

**Cálculo**: para un dev solo, el costo de mantener CQRS puro es ~30% del tiempo de desarrollo dedicado a "mantener la infraestructura" en lugar de "entregar features".

#### Lo que el proyecto realmente necesita
El SLO principal es login < 1s. ¿Dónde está el cuello de botella real?

|Operación| Latencia actual probable| Cuello de botella|
|----|----|
|Login| 200-500ms | bcrypt cost factor + 1-2 queries
|validate-token | 80-200ms | 1-2 queries en cada hit|
|Dashboard | 300-800ms | Agregaciones sobre tablas grandes|
|Contactos list| 100-300ms | Query con N joins|

Conclusión: el 80% del beneficio de CQRS se obtiene con tácticas más simples. CQRS puro es sobredimensionado.

CQRS Táctico (no CQRS puro)
En lugar de "separar write de read completamente":

Capa 1: Cache estratégica (sin consistencia eventual)
- Redis (o database cache driver si no querés infra nueva) para:
- auth:validate_token:{sha256} → TTL 5min (ya estaba en el PRD)
- auth:user_apps:{user_id} → TTL 5min
- dashboard:kpis:{entidad_id} → TTL 30s
- planes:public → TTL 1h
- Invalidación explícita en eventos de dominio (login, logout, role change, app assignment)

Capa 2: Read replica + read/write split
- MariaDB con 1 replica (mariadb-read-replica)
- Conexión mysql_read en config/database.php
- Solo lecturas de dashboard, listados grandes, reportes
- Escrituras siguen al master (single source of truth)

Capa 3: Proyecciones pre-computadas (CQRS-Lite, no puro)
- Tabla dashboard_kpi_snapshot (entidad_id, fecha, total_leads, total_opps, etc.)
- Job nightly (o cada 5min) que recalcula
- Dashboard lee siempre de la snapshot (sub-50ms)
- NO eventual consistency, NO event bus — es un job batch

Capa 4: Optimizaciones quirúrgicas
- bcrypt cost factor: bajar de 12 a 10 → login -200ms (seguro todavía, OWASP lo permite)
- Índices compuestos en contacto(entidad_id, estado), oportunidad(entidad_id, etapa_id), seguimiento(usuario_id, fecha)
- Eager loading específico (no global) para evitar N+1
Costo estimado: 1-2 semanas, NO refactor del Clean Architecture existente.