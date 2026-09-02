# Spec: Multi-Pipeline Support & Lead Ingestion Pipeline

## 1. Multi-Pipeline Support in Oportunidades

### Description
El modelo `Oportunidad` debe ser capaz de clasificarse en diferentes pipelines para separar las oportunidades del flujo comercial inicial de los flujos de rescate o reactivación.

### Fields and Schema
- `pipeline`: String. MUST be one of: `Llegada`, `Rescate`. Default: `Llegada`.
- `estado`: String/Enum. MUST support the following values depending on the pipeline:
  - For `Llegada` pipeline: `Borrador`, `Enviada`, `Aceptada`, `Rechazada`, `Ganada`, `Perdida`.
  - For `Rescate` pipeline: `Rescate_Inicial`, `Rescate_En_Progreso`, `Rescate_Exitoso`, `Rescate_Fallido`.

### Acceptance Criteria
- Una oportunidad nueva creada sin especificar `pipeline` SHALL pertenecer por defecto al pipeline `Llegada`.
- La base de datos MUST validar la transición de estados y asegurar integridad referencial.
- Si se intenta asignar un estado que no pertenece al pipeline actual de la oportunidad, se MUST arrojar una excepción de validación.

### Scenarios

#### S1: Transición válida dentro de un Pipeline
- GIVEN una oportunidad en el pipeline `Llegada` con estado `Borrador`
- WHEN el usuario cambia el estado a `Enviada`
- THEN el cambio SHALL ser exitoso
- AND el pipeline se mantendrá como `Llegada`

#### S2: Transición inválida entre estados de distintos Pipelines
- GIVEN una oportunidad en el pipeline `Llegada`
- WHEN el usuario intenta asignar el estado `Rescate_Inicial`
- THEN la operación MUST fallar con un error de validación (422)

---

## 2. Lead Scoring (AssignScoreAction)

### Description
Acción de dominio encargada de calcular el score del contacto/lead basado en la información provista. El score resultante se guarda en la entidad o contacto correspondiente.

### Scoring Rules
- Cargo del contacto contiene "CEO", "Gerente", "Director", "Director General", "Fundador" -> Suma 30 puntos.
- Fuente/Canal es "Web", "Formulario", o "Recomendado" -> Suma 20 puntos.
- Si el dominio de la entidad es corporativo (no gmail/yahoo/etc) -> Suma 20 puntos.
- El score base inicial es 10 puntos.
- El score total calculado MUST estar en el rango de [0, 100].

---

## 3. Laravel Ingestion Pipeline Pattern

### Description
El proceso de ingesta de un lead a través de los webhooks de SAIlus (FastAPI) o APIs directas se procesará utilizando el patrón `Illuminate\Pipeline\Pipeline` de Laravel para estructurar limpiamente las operaciones secuenciales.

### Pipeline Stages
1. `NormalizeLeadData`: Asegura la consistencia de caracteres, limpia teléfonos y normaliza campos UTM.
2. `ResolveOrCreateEntidad`: Busca si la empresa ya existe (por identificación o dominio) o la crea.
3. `ResolveOrCreateContacto`: Busca si el contacto existe por email en la entidad o lo crea.
4. `AssignLeadScore`: Ejecuta la acción `AssignScoreAction` sobre el contacto y actualiza su score.
5. `CreateOportunidad`: Si no existe una oportunidad activa para esta entidad/contacto, crea una nueva oportunidad en el pipeline `Llegada` con estado `Borrador`.

### Acceptance Criteria
- El endpoint `POST /api/v1/webhook/registration` y `POST /api/v1/contacto` MUST procesar las peticiones a través de este pipeline.
- Cualquier fallo en un paso del pipeline MUST abortar la transacción de base de datos y retornar un error formateado adecuado.
