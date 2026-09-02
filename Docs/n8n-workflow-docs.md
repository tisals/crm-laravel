# n8n Workflow: Pipeline Etapa Changed → Mailrelay

> Flujo automatizado que envía emails via Mailrelay cuando una oportunidad cambia de etapa en un pipeline.

## Archivo JSON

`Docs/n8n-workflow-pipeline-etapa-changed.json` — importar directamente en n8n.

## Flujo

```
CRM (webhook POST)
    │
    ▼
[Webhook Trigger] ─── POST /pipeline-etapa-changed
    │
    ▼
[Filter: Pipeline] ─── Solo LLEGADA o RECUPERACION
    │
    ├──[Is LLEGADA?]──[Set LLEGADA Params]──┐
    │                                        │
    └──[Is RECUPERACION?]──[Set RECUPERACION]──┤
                                              │
                                              ▼
                                   [Lookup Contact in Mailrelay]
                                              │
                                              ▼
                                   [Send via Mailrelay]
                                              │
                                              ▼
                                   [Log Send Status to CRM]
```

## Payload del webhook (desde CRM)

```json
{
  "event": "pipeline.etapa.changed",
  "oportunidad_id": 123,
  "oportunidad_codigo": "OP-001",
  "pipeline_id": 1,
  "pipeline_nombre": "Cotización",
  "pipeline_codigo": "LLEGADA",
  "previous_etapa_id": 1,
  "previous_etapa_nombre": "Borrador",
  "current_etapa_id": 2,
  "current_etapa_nombre": "Enviada",
  "contacto_nombre": "María García",
  "contacto_email": "maria@ejemplo.com",
  "entidad_nombre": "Distribuidora El Carmen",
  "user_id": 5,
  "timestamp": "2026-06-09T15:30:00Z"
}
```

## Environment Variables (n8n)

| Variable | Descripción | Ejemplo |
|----------|-------------|---------|
| `MAILRELAY_API_URL` | Base URL de Mailrelay API | `https://api.mailrelay.com` |
| `MAILRELAY_API_KEY` | API key de Mailrelay | `abc123...` |
| `MAILRELAY_TEMPLATE_LLEGADA` | ID template Llegada | `tpl_001` |
| `MAILRELAY_TEMPLATE_RECUPERACION` | ID template Recuperación | `tpl_002` |
| `MAILRELAY_CAMPAIGN_ID` | ID de campaña para envío | `camp_001` |

## Mapping Etapa → Subject Variant

### Pipeline LLEGADA

| Etapa | Subject Variant |
|-------|----------------|
| Borrador | Default: "{{nombre}}, avanzamos en tu proceso de SG-SST — {{empresa}}" |
| Enviada | Social proof: "+200 empresas en Colombia ya avanzaron con nosotros" |
| En Negociación | Urgency: "Quedan pocos espacios este mes, {{nombre}}" |
| Aprobado | Default (success) |
| Rechazado | Default (respetuoso) |

### Pipeline RECUPERACION

| Etapa | Subject Variant |
|-------|----------------|
| Rescate_Inicial | Urgency: "{{nombre}}, tu empresa {{empresa}} puede estar en riesgo" |
| Rescate_En_Progreso | Value: "Consultoría gratuita SG-SST para {{empresa}}" |
| Rescate_Exitoso | Default (success) |
| Rescate_Fallido | Social proof: "+200 empresas reactivaron su SG-SST" |

## Merge Tags

| Tag | Descripción | Pipeline |
|-----|-------------|----------|
| `{{nombre}}` | Nombre del contacto | Ambos |
| `{{empresa}}` | Nombre de la organización | Ambos |
| `{{pipeline}}` | Nombre del pipeline | Ambos |
| `{{etapa}}` | Nombre de la etapa | Ambos |
| `{{oportunidad_id}}` | ID de la oportunidad | Ambos |
| `{{asesor_nombre}}` | Nombre del asesor | Ambos |
| `{{asesor_telefono}}` | Teléfono del asesor | Ambos |
| `{{url_cotizacion}}` | Link a cotización | LLEGADA |
| `{{dias_inactividad}}` | Días sin contacto | RECUPERACION |
| `{{url_consultoria}}` | Link a consultoría | RECUPERACION |

## Setup en n8n

1. Importar `Docs/n8n-workflow-pipeline-etapa-changed.json`
2. Configurar credenciales HTTP Header Auth con Mailrelay API key
3. Setear environment variables en n8n
4. Activar el workflow
5. Copiar la webhook URL (ej: `https://tu-n8n.com/webhook/pipeline-etapa-changed`)
6. Setear en el CRM: `N8N_PIPELINE_WEBHOOK_URL=https://tu-n8n.com/webhook/pipeline-etapa-changed`

## Testing

```bash
# Test webhook locally
curl -X POST http://localhost:5678/webhook/pipeline-etapa-changed \
  -H "Content-Type: application/json" \
  -d '{
    "event": "pipeline.etapa.changed",
    "oportunidad_id": 1,
    "oportunidad_codigo": "OP-001",
    "pipeline_codigo": "LLEGADA",
    "current_etapa_nombre": "Enviada",
    "contacto_nombre": "María García",
    "contacto_email": "test@ejemplo.com",
    "entidad_nombre": "Distribuidora El Carmen"
  }'
```

## Referencias

- **Email Llegada**: `Docs/phase-18-email-draft.md`
- **Email Recuperación**: `Docs/phase-18-reactivation-draft.md`
- **Event payload**: `app/Events/PipelineEtapaChanged.php`
- **Webhook listener**: `app/Listeners/SendPipelineChangeToN8n.php`
