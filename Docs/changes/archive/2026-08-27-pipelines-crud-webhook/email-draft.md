# Email Draft — Mailrelay Autoresponder (Pipeline Etapa Changed)

> Este es el template de correo que **n8n enviará vía Mailrelay** cuando una oportunidad cambie de etapa en un pipeline.
> El CRM solo dispara el webhook `PipelineEtapaChanged`. El contenido del email vive en la librería de
> plantillas de Mailrelay, pero este archivo es la fuente de verdad canónica para los equipos de n8n y Mailrelay.

## Variable Substitution (Mailrelay merge tags)

| Tag | Description | Example |
|-----|-------------|---------|
| `{{nombre}}` | Contact's first name | "María" |
| `{{empresa}}` | Organization name | "Distribuidora El Carmen" |
| `{{pipeline}}` | Current pipeline name | "Cotización" |
| `{{etapa}}` | Current etapa name | "En Negociación" |
| `{{oportunidad_id}}` | Internal opportunity ID | "OP-1234" |
| `{{asesor_nombre}}` | Assigned advisor name | "Carlos Ramírez" |
| `{{asesor_telefono}}` | Advisor phone with country code | "+57 311 555 1234" |
| `{{url_cotizacion}}` | Direct link to quote (when available) | "https://app.tecnoinnsoft.com/cotizacion/abc123" |

## Subject Lines (A/B testing variants)

1. **Default**: `{{nombre}}, avanzamos en tu proceso de SG-SST — {{empresa}}`
2. **Variant B (urgency)**: `Quedan pocos espacios este mes, {{nombre}}`
3. **Variant C (social proof)**: `+200 empresas en Colombia ya avanzaron con nosotros`

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

## Mapping: Etapa → Subject Variant

| Etapa | Subject Variant | Reason |
|-------|----------------|--------|
| Borrador | Default | Welcome / first touch |
| Enviado | Variant C (social proof) | After quote sent, leverage trust |
| En Negociación | Variant B (urgency) | Mid-funnel, create urgency |
| Aprobado | Default (success) | Confirmation tone |
| Rechazado | Default (no aggressive) | Respectful follow-up |

## Send Triggers (n8n Flow)

The n8n workflow listens to the `PipelineEtapaChanged` webhook from the CRM and:

1. **Validates** the `pipeline_etapa_id` is in {Cotización, Recuperación} (not internal stages).
2. **Looks up** the contact in Mailrelay by email.
3. **Selects** the subject variant based on etapa (mapping above).
4. **Renders** the template with merge tags.
5. **Sends** via Mailrelay API (`POST /v1/campaigns/{id}/send` or autoresponder trigger).
6. **Logs** send status back to CRM via `POST /api/v1/oportunidades/{id}/email-sent` (optional, future enhancement).

## Why This Draft Lives in the SDD Change

- Single source of truth for copy across CRM, n8n, and Mailrelay teams.
- Future regressions in copy can be caught by reviewing this artifact.
- The merge tag contract MUST match the `PipelineEtapaChanged` event payload fields.
