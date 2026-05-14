<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 20px; }
        .header { background: #1a73e8; color: white; padding: 20px; text-align: center; }
        .content { padding: 20px; }
        .section { margin-bottom: 20px; }
        .section h3 { color: #1a73e8; border-bottom: 2px solid #1a73e8; padding-bottom: 5px; }
        .info-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #eee; }
        .label { font-weight: bold; color: #555; }
        .value { color: #333; }
        .diagnosis { background: #f5f5f5; padding: 15px; border-radius: 5px; }
        .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
        .badge { display: inline-block; padding: 4px 8px; background: #1a73e8; color: white; border-radius: 3px; margin-right: 5px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Reporte Técnico</h1>
        <p>Tecnoinnsoft - Diagnóstico Digital</p>
    </div>

    <div class="content">
        <div class="section">
            <h3>Información del Contacto</h3>
            <div class="info-row">
                <span class="label">Nombre:</span>
                <span class="value">{{ $contact->name }}</span>
            </div>
            <div class="info-row">
                <span class="label">Email:</span>
                <span class="value">{{ $contact->email }}</span>
            </div>
            @if($contact->phone)
            <div class="info-row">
                <span class="label">Teléfono:</span>
                <span class="value">{{ $contact->phone }}</span>
            </div>
            @endif
        </div>

        @if(!empty($diagnostico))
        <div class="section">
            <h3>Resultados del Diagnóstico</h3>
            <div class="diagnosis">
                @if(isset($diagnostico['dominante']))
                <div class="info-row">
                    <span class="label">Eje Dominante:</span>
                    <span class="value"><span class="badge">{{ $diagnostico['dominante'] }}</span></span>
                </div>
                @endif
                @if(isset($diagnostico['presupuesto']))
                <div class="info-row">
                    <span class="label">Rango Presupuestario:</span>
                    <span class="value">{{ $diagnostico['presupuesto'] }}</span>
                </div>
                @endif
                @if(isset($diagnostico['score']))
                <div class="info-row">
                    <span class="label">Puntuación:</span>
                    <span class="value">{{ $diagnostico['score'] }}/100</span>
                </div>
                @endif
            </div>
        </div>

        @if(isset($diagnostico['recomendaciones']))
        <div class="section">
            <h3>Recomendaciones</h3>
            <ul>
                @foreach($diagnostico['recomendaciones'] as $recomendacion)
                <li>{{ $recomendacion }}</li>
                @endforeach
            </ul>
        </div>
        @endif
        @endif

        <div class="section">
            <h3>Próximos Pasos</h3>
            <ol>
                <li>Revisá este reporte con tu equipo técnico</li>
                <li>Agendá una reunión de evaluación</li>
                <li>Recibirás una propuesta personalizada en 48 horas</li>
            </ol>
        </div>
    </div>

    <div class="footer">
        <p>Este reporte fue generado automáticamente por Tecnoinnsoft</p>
        <p>© 2026 Tecnoinnsoft - Todos los derechos reservados</p>
    </div>
</body>
</html>
