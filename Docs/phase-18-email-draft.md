# Phase 18: Email Draft — Mailrelay Autoresponder

> Pipeline `pipelines-crud-webhook` — Phase 18 (renumbered from 17.5)
> 
> Template de correo que **n8n enviará vía Mailrelay** cuando una oportunidad cambie de etapa en un pipeline.
> El CRM solo dispara el webhook `PipelineEtapaChanged`. El contenido del email vive en la librería de
> plantillas de Mailrelay, pero este archivo es la fuente de verdad canónica para los equipos de n8n y Mailrelay.

---

## Tasks

- [ ] TASK-18.1: Revisar el draft de email con el equipo de marketing
- [ ] TASK-18.2: Importar el template HTML en la librería de plantillas de Mailrelay
- [ ] TASK-18.3: Configurar merge tags en Mailrelay
- [ ] TASK-18.4: Crear 3 variantes de subject line en Mailrelay (Default, Variant B urgency, Variant C social proof)
- [ ] TASK-18.5: Configurar test A/B en Mailrelay (split 40/40/20)
- [ ] TASK-18.6: Test send a email interno — verificar que los merge tags renderizan correctamente
- [ ] TASK-18.7: Documentar el workflow de n8n en `docs/n8n/pipeline-etapa-changed-workflow.md`
- [ ] TASK-18.8: Agregar link de unsubscribe en el footer (cumplimiento RDLC)

---

## Variable Substitution (Mailrelay merge tags)

| Tag | Descripción | Ejemplo |
|-----|-------------|---------|
| `{{nombre}}` | Nombre del contacto | "María" |
| `{{empresa}}` | Nombre de la organización | "Distribuidora El Carmen" |
| `{{pipeline}}` | Nombre del pipeline actual | "Cotización" |
| `{{etapa}}` | Nombre de la etapa actual | "En Negociación" |
| `{{oportunidad_id}}` | ID interno de la oportunidad | "OP-1234" |
| `{{asesor_nombre}}` | Nombre del asesor asignado | "Carlos Ramírez" |
| `{{asesor_telefono}}` | Teléfono del asesor con código de país | "+57 311 555 1234" |
| `{{url_cotizacion}}` | Link directo a la cotización (cuando disponible) | "https://app.tecnoinnsoft.com/cotizacion/abc123" |

---

## Subject Lines (Variantes para A/B testing)

1. **Default**: `{{nombre}}, avanzamos en tu proceso de SG-SST — {{empresa}}`
2. **Variant B (urgency)**: `Quedan pocos espacios este mes, {{nombre}}`
3. **Variant C (social proof)**: `+200 empresas en Colombia ya avanzaron con nosotros`

---

## Mapping: Etapa → Subject Variant

| Etapa | Subject Variant | Razón |
|-------|----------------|-------|
| Borrador | Default | Welcome / first touch |
| Enviado | Variant C (social proof) | After quote sent, leverage trust |
| En Negociación | Variant B (urgency) | Mid-funnel, create urgency |
| Aprobado | Default (success) | Confirmation tone |
| Rechazado | Default (no aggressive) | Respectful follow-up |

---

## Body Template (Plain Text)

```
Hola {{nombre}} 👋

Gracias por tu interés en los servicios de Seguridad y Salud en el Trabajo para {{empresa}}.

Tu solicitud ha avanzado a la etapa "{{etapa}}" dentro de nuestro proceso. Esto significa que estamos cada vez más cerca de acompañarte en el cumplimiento del SG-SST (Decreto 1072 de 2015 y Resolución 0312 de 2019).

🛡️ ¿Por qué es importante avanzar ahora?
• El SG-SST es OBLIGATORIO para todas las empresas en Colombia, sin importar su tamaño.
• Las multas por incumplimiento pueden superar los $500 millones COP.
• Un sistema bien implementado reduce accidentes laborales hasta en un 40%.
• Mejora la productividad y el clima organizacional desde el primer mes.

📋 Próximos pasos
1. Tu asesor asignado, {{asesor_nombre}}, revisará tu caso en las próximas 24 horas.
2. Te contactaremos al {{asesor_telefono}} para agendar una llamada de 15 minutos.
3. Si ya tienes la cotización lista, descárgala aquí: {{url_cotizacion}}

💬 ¿Tienes dudas rápidas?
Responde este correo o escríbenos por WhatsApp: +57 311 555 1234

— Equipo Tecnoinnsoft
"Acompañamos a tu empresa a cumplir el SG-SST sin complicaciones"

—
Si prefieres no recibir más comunicaciones sobre este proceso, responde con la palabra BAJA y te removemos de la lista en 24 horas.
```

---

## Body Template (HTML)

```html
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Avanzamos en tu proceso SG-SST</title>
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background-color: #f8fafc; margin: 0; padding: 24px;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
    <!-- Header -->
    <tr>
      <td style="background: linear-gradient(135deg, #0f766e 0%, #14b8a6 100%); padding: 32px 24px; text-align: center;">
        <h1 style="color: #ffffff; margin: 0; font-size: 24px; font-weight: 600;">Hola, {{nombre}} 👋</h1>
      </td>
    </tr>
    
    <!-- Greeting -->
    <tr>
      <td style="padding: 32px 24px 16px; color: #1e293b; font-size: 16px; line-height: 1.6;">
        <p>Gracias por tu interés en los servicios de <strong>Seguridad y Salud en el Trabajo</strong> para <strong>{{empresa}}</strong>.</p>
        <p>Tu solicitud ha avanzado a la etapa <strong>"{{etapa}}"</strong> dentro de nuestro proceso.</p>
      </td>
    </tr>
    
    <!-- Why now -->
    <tr>
      <td style="padding: 16px 24px;">
        <h2 style="color: #0f766e; font-size: 18px; margin: 0 0 16px;">🛡️ ¿Por qué es importante avanzar ahora?</h2>
        <ul style="color: #475569; font-size: 15px; line-height: 1.7; padding-left: 20px;">
          <li>El SG-SST es <strong>obligatorio</strong> para todas las empresas en Colombia.</li>
          <li>Las multas por incumplimiento pueden superar los <strong>$500 millones COP</strong>.</li>
          <li>Un sistema bien implementado reduce accidentes laborales hasta en un <strong>40%</strong>.</li>
        </ul>
      </td>
    </tr>
    
    <!-- Next steps -->
    <tr>
      <td style="padding: 16px 24px;">
        <h2 style="color: #0f766e; font-size: 18px; margin: 0 0 16px;">📋 Próximos pasos</h2>
        <ol style="color: #475569; font-size: 15px; line-height: 1.7; padding-left: 20px;">
          <li>Tu asesor <strong>{{asesor_nombre}}</strong> revisará tu caso en 24 horas.</li>
          <li>Te contactaremos al <strong>{{asesor_telefono}}</strong>.</li>
          <li>Si ya tienes la cotización: <a href="{{url_cotizacion}}" style="color: #14b8a6;">descárgala aquí</a></li>
        </ol>
      </td>
    </tr>
    
    <!-- CTA -->
    <tr>
      <td style="padding: 24px; text-align: center;">
        <a href="{{url_cotizacion}}" style="display: inline-block; background-color: #0f766e; color: #ffffff; padding: 14px 32px; text-decoration: none; border-radius: 6px; font-weight: 600;">Ver mi cotización</a>
      </td>
    </tr>
    
    <!-- Footer -->
    <tr>
      <td style="background-color: #f1f5f9; padding: 24px; text-align: center; color: #64748b; font-size: 13px;">
        <p style="margin: 0 0 8px;"><strong>Tecnoinnsoft</strong> — Acompañamos a tu empresa a cumplir el SG-SST sin complicaciones</p>
        <p style="margin: 0;">WhatsApp: +57 311 555 1234 · <a href="mailto:contacto@tecnoinnsoft.com" style="color: #0f766e;">contacto@tecnoinnsoft.com</a></p>
        <p style="margin: 16px 0 0; font-size: 12px;">Si prefieres no recibir más comunicaciones, responde con la palabra BAJA.</p>
      </td>
    </tr>
  </table>
</body>
</html>
```

---

## Send Triggers (n8n Flow)

El workflow de n8n escucha el webhook `PipelineEtapaChanged` del CRM y:

1. **Valida** que el `pipeline_etapa_id` pertenezca a {Cotización, Recuperación} (no etapas internas).
2. **Busca** el contacto en Mailrelay por email.
3. **Selecciona** la variante de subject según la etapa (mapping arriba).
4. **Renderiza** el template con los merge tags.
5. **Envía** vía Mailrelay API (`POST /v1/campaigns/{id}/send` o autoresponder trigger).
6. **Registra** el estado de envío en el CRM vía `POST /api/v1/oportunidades/{id}/email-sent` (mejora futura, opcional).

---

## Referencias

- **Spec**: `openspec/changes/pipelines-crud-webhook/spec.md`
- **Design**: `openspec/changes/pipelines-crud-webhook/design.md`
- **Tasks**: `openspec/changes/pipelines-crud-webhook/tasks.md`
- **Event payload**: `app/Events/PipelineEtapaChanged.php`
- **Webhook listener**: `app/Listeners/SendPipelineChangeToN8n.php`
