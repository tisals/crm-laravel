<!DOCTYPE html>
<html>
<body style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; color: #333333;">
    <h2 style="color: #2563eb;">¡Bienvenido a SAIlus, {{ $data['customer_name'] }}!</h2>
    <p>Tu compra ha sido procesada exitosamente. Para activar tu plugin WordPress, usa el siguiente token de activación:</p>
    <div style="background: #f3f4f6; padding: 16px; border-radius: 8px; text-align: center; margin: 24px 0; border: 1px solid #e5e7eb;">
        <code style="font-size: 20px; letter-spacing: 2px; font-weight: bold; color: #111827;">
            {{ $data['activation_token'] }}
        </code>
    </div>
    <p>
        <strong>Plan:</strong> {{ $data['plan_name'] }}<br>
        <strong>Vigente hasta:</strong> {{ $data['expires_at'] }}
    </p>
    <p>Ingresa este token en la configuración de tu plugin WordPress en:
       <a href="{{ $data['site_url'] }}/wp-admin/" style="color: #2563eb; text-decoration: underline;">{{ $data['site_url'] }}/wp-admin/</a>
    </p>
    <hr style="border: none; border-top: 1px solid #e5e7eb; margin: 24px 0;">
    <p style="color: #6b7280; font-size: 14px;">
        Si tenés preguntas, respondé directamente a este email.<br>
        — Equipo SAIlus
    </p>
</body>
</html>
