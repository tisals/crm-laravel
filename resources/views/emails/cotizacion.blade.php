<!DOCTYPE html>
<html>
<head><meta charset="utf-8"></head>
<body style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background: #009188; color: white; padding: 20px; border-radius: 8px 8px 0 0;">
        <h1 style="margin: 0;">Cotización {{ $codigo }}</h1>
    </div>
    <div style="padding: 20px; border: 1px solid #e5e7eb;">
        <p>Hola <strong>{{ $entidad }}</strong>,</p>
        @if(!empty($mensajePersonalizado))
            <div style="background: #f9fafb; padding: 15px; border-left: 3px solid #009188; margin: 15px 0;">
                {{ $mensajePersonalizado }}
            </div>
        @else
            <p>Adjuntamos la cotización <strong>{{ $codigo }}</strong> para tu revisión.</p>
        @endif
        <p>Puedes descargarla directamente desde este enlace:</p>
        <p style="text-align: center; margin: 30px 0;">
            <a href="{{ $url }}" 
               style="background: #009188; color: white; padding: 12px 30px; text-decoration: none; border-radius: 6px; font-weight: bold;">
                Descargar PDF
            </a>
        </p>
        <p style="color: #666; font-size: 12px;">Tecnoinnsoft SAS · Soluciones en SST</p>
    </div>
</body>
</html>
