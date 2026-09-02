# Phase 18: Email Draft — Reactivación de Clientes Fríos (Pipeline Recuperación)

> Pipeline `pipelines-crud-webhook` — Phase 18 (Recuperación)
> 
> Template de correo para **reactivar clientes fríos** (3+ meses sin contacto).
> El CRM dispara el webhook `PipelineEtapaChanged` cuando la oportunidad entra al pipeline de Recuperación.
> El objetivo es ofrecer una **consultoría gratuita** de servicios TIS Plus para reconectar.

---

## Contexto: Perfil del Cliente Frío

- **Definición**: Cliente o lead que no ha tenido interacción en 3+ meses
- **Perfil**: Empresa que ya mostró interés previamente pero se detuvo en el proceso
- **Objetivo**: Reactivar con una propuesta de valor concreta (consultoría gratuita)
- **Servicio ofertado**: Consultoría gratuita de diagnóstico SG-SST por TIS Plus

---

## Variable Substitution (Mailrelay merge tags)

| Tag | Descripción | Ejemplo |
|-----|-------------|---------|
| `{{nombre}}` | Nombre del contacto | "María" |
| `{{empresa}}` | Nombre de la organización | "Distribuidora El Carmen" |
| `{{dias_inactividad}}` | Días desde la última interacción | "97" |
| `{{asesor_nombre}}` | Nombre del asesor asignado | "Carlos Ramírez" |
| `{{asesor_telefono}}` | Teléfono del asesor con código de país | "+57 311 555 1234" |
| `{{url_consultoria}}` | Link para agendar consultoría gratuita | "https://app.tecnoinnsoft.com/consultoria/abc123" |
| `{{ultima_interaccion}}` | Fecha de la última interacción | "15 de marzo de 2026" |

---

## Subject Lines (Variantes para A/B testing)

1. **Variant A (urgency)**: `{{nombre}}, tu empresa {{empresa}} puede estar en riesgo — consultoría gratuita`
2. **Variant B (value)**: `Consultoría gratuita SG-SST para {{empresa}} — por tiempo limitado`
3. **Variant C (social proof)**: `+200 empresas en Colombia reactivaron su SG-SST con nosotros este año`

---

## Mapping: Etapa → Subject Variant

| Etapa | Subject Variant | Razón |
|-------|----------------|-------|
| Rescate_Inicial | Variant A (urgency) | First reactivation touch — create urgency |
| Rescate_En_Progreso | Variant B (value) | Already engaged — reinforce value |
| Rescate_Exitoso | Default (success) | Won back — confirmation |
| Rescate_Fallido | Variant C (social proof) | Last attempt — leverage social proof |

---

## Body Template (Plain Text)

```
Hola {{nombre}} 👋

Te contactamos por que te has contactado con deseguridad.net sobre los servicios de Seguridad y Salud en el Trabajo para {{empresa}}, y queríamos saber cómo te ha ido con el cumplimiento del SG-SST.

🛡️ Sabemos que el tiempo vuela, pero el SG-SST sigue siendo OBLIGATORIO.
• Las multas por incumplimiento pueden superar los $500 millones COP.
• Desde 2025, El ministerio de Trabajo intensificó las fiscalizaciones.
• El Decreto 1072 de 2015 y la Resolución 0312 de 2019 exigen un sistema documentado.

📋 Lo que queremos ofrecer por habernos contactado (GRATIS):
Una consultoría de diagnóstico de 30 minutos donde:
1. Revisamos el estado actual de tu SG-SST
2. Identificamos los puntos críticos de incumplimiento
3. Te damos un plan de acción concreto para cerrar brechas

Normalmente, este diagnóstico tiene un valor de $300.000 COP, pero queremos dártelo sin costo como cortesía del lanzamiento de TIS Plus.

🔗 Agenda tu consultoría aquí: {{url_consultoria}}

💬 ¿Prefieres hablar directamente?
Llámanos al {{asesor_telefono}} o responde este correo.

Tu asesor,
{{asesor_nombre}}

— Equipo deseguridad / TIS Plus
"Acompañamos a tu empresa a cumplir el SG-SST sin complicaciones"

—
Si prefieres no recibir más comunicaciones, responde con la palabra BAJA y te removemos de la lista en 24 horas.
```

---

## Body Template (HTML)

```html
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Tu SG-SST puede estar en riesgo</title>
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background-color: #f8fafc; margin: 0; padding: 24px;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
    <!-- Header -->
    <tr>
      <td style="background: linear-gradient(135deg, #b45309 0%, #f59e0b 100%); padding: 32px 24px; text-align: center;">
        <h1 style="color: #ffffff; margin: 0; font-size: 24px; font-weight: 600;">Hola, {{nombre}} 👋</h1>
        <p style="color: #fef3c7; margin: 8px 0 0; font-size: 14px;">Hacemos seguimiento de tu caso</p>
      </td>
    </tr>
    
    <!-- Context -->
    <tr>
      <td style="padding: 32px 24px 16px; color: #1e293b; font-size: 16px; line-height: 1.6;">
        <p>Hace <strong>{{dias_inactividad}} días</strong> tuvimos contacto sobre los servicios de SG-SST para <strong>{{empresa}}</strong>.</p>
        <p>Queríamos saber cómo vas con el cumplimiento, porque <strong>el SG-SST sigue siendo obligatorio</strong>.</p>
      </td>
    </tr>
    
    <!-- Warning -->
    <tr>
      <td style="padding: 16px 24px;">
        <div style="background-color: #fef2f2; border-left: 4px solid #ef4444; padding: 16px; border-radius: 4px;">
          <h2 style="color: #991b1b; font-size: 16px; margin: 0 0 8px;">⚠️ Riesgos de incumplimiento</h2>
          <ul style="color: #7f1d1d; font-size: 14px; line-height: 1.7; padding-left: 20px; margin: 0;">
            <li>Multas que pueden superar los <strong>$500 millones COP</strong></li>
            <li>Fiscalizaciones intensificadas desde 2025</li>
            <li>Decreto 1072 de 2015 y Resolución 0312 de 2019 exigen sistema documentado</li>
          </ul>
        </div>
      </td>
    </tr>
    
    <!-- Offer -->
    <tr>
      <td style="padding: 16px 24px;">
        <div style="background-color: #f0fdf4; border-left: 4px solid #22c55e; padding: 16px; border-radius: 4px;">
          <h2 style="color: #166534; font-size: 16px; margin: 0 0 8px;">🎁 Consultoría gratuita (valor: $800.000 COP)</h2>
          <ol style="color: #14532d; font-size: 14px; line-height: 1.7; padding-left: 20px; margin: 0;">
            <li>Revisamos el estado actual de tu SG-SST</li>
            <li>Identificamos los puntos críticos de incumplimiento</li>
            <li>Te damos un plan de acción concreto para cerrar brechas</li>
          </ol>
        </div>
      </td>
    </tr>
    
    <!-- CTA -->
    <tr>
      <td style="padding: 24px; text-align: center;">
        <a href="{{url_consultoria}}" style="display: inline-block; background-color: #b45309; color: #ffffff; padding: 14px 32px; text-decoration: none; border-radius: 6px; font-weight: 600; font-size: 16px;">Agendar consultoría gratuita</a>
        <p style="color: #64748b; font-size: 13px; margin: 12px 0 0;">O llámanos al {{asesor_telefono}}</p>
      </td>
    </tr>
    
    <!-- Advisor -->
    <tr>
      <td style="padding: 0 24px 16px;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color: #f8fafc; border-radius: 8px; padding: 16px;">
          <tr>
            <td style="padding: 16px; color: #475569; font-size: 14px;">
              <p style="margin: 0;">Tu asesor asignado:</p>
              <p style="margin: 4px 0 0; font-weight: 600; color: #1e293b;">{{asesor_nombre}}</p>
              <p style="margin: 4px 0 0; color: #0f766e;">{{asesor_telefono}}</p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
    
    <!-- Footer -->
    <tr>
      <td style="background-color: #f1f5f9; padding: 24px; text-align: center; color: #64748b; font-size: 13px;">
        <p style="margin: 0 0 8px;"><strong>Tecnoinnsoft / TIS Plus</strong> — Acompañamos a tu empresa a cumplir el SG-SST sin complicaciones</p>
        <p style="margin: 0;">WhatsApp: {{asesor_telefono}}</p>
        <p style="margin: 16px 0 0; font-size: 12px;">Si prefieres no recibir más comunicaciones, responde con la palabra BAJA.</p>
      </td>
    </tr>
  </table>
</body>
</html>
```

---

## Send Triggers (n8n Flow — Recuperación)

El workflow de n8n escucha el webhook `PipelineEtapaChanged` del CRM y:

1. **Valida** que el `pipeline_etapa_id` pertenezca al pipeline de Recuperación.
2. **Verifica** que la última interacción sea > 90 días (filtro de cliente frío).
3. **Busca** el contacto en Mailrelay por email.
4. **Selecciona** la variante de subject según la etapa (mapping arriba).
5. **Renderiza** el template con los merge tags.
6. **Envía** vía Mailrelay API (`POST /v1/campaigns/{id}/send` o autoresponder trigger).

---

## Referencias

- **Spec**: `openspec/changes/pipelines-crud-webhook/spec.md`
- **Design**: `openspec/changes/pipelines-crud-webhook/design.md`
- **Pipeline Llegada draft**: `Docs/phase-18-email-draft.md`
- **Event payload**: `app/Events/PipelineEtapaChanged.php`
- **Webhook listener**: `app/Listeners/SendPipelineChangeToN8n.php`
