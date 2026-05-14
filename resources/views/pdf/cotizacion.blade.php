<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cotización {{ $cotizacion_no }}</title>
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; color: #333333; }
        h1, h2, h3 { font-family: 'DejaVu Sans', sans-serif; font-weight: 700; }
        .rounded-brand { border-radius: 2.5rem; }
        @page { margin: 20mm 15mm; }
    </style>
</head>
<body style="background-color: #F5F5F5; min-height: 100vh; padding: 40px;">
    <div class="main-container" style="max-width: 640px; margin: 0 auto; background-color: white; padding: 48px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); border-radius: 2.5rem; border-top: 8px solid #009188;">
        
        <!-- Header -->
        <header style="display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 1px solid #e5e7eb; padding-bottom: 32px; margin-bottom: 32px;">
            <div style="flex: 1;">
                <img src="https://deseguridad.net/wp-content/uploads/2026/05/Logo-tecnoinnsoft-183x90-1.png" 
                     alt="deseguridad.net" 
                     style="height: 64px; width: auto; object-fit: contain; margin-bottom: 16px;">
                <p style="color: #004843; font-size: 14px; font-weight: bold; letter-spacing: 0.025em; font-style: italic; margin: 0;">
                    "{{ $brand['slogan'] }}"
                </p>
            </div>
            <div style="text-align: right; flex: 1;">
                <h1 style="font-size: 30px; font-weight: 900; color: #333333; text-transform: uppercase; line-height: 1; margin: 0;">Cotización</h1>
                <p style="color: #009188; font-weight: bold; font-size: 20px; margin: 8px 0 0 0;">N° {{ $opportunity['id'] }}-v{{ $opportunity['version'] }}</p>
                <div style="margin-top: 8px; font-size: 12px; color: #6b7280;">
                    <p style="margin: 0;">Fecha: {{ $opportunity['sent_at'] }}</p>
                    <p style="margin: 0;">Validez: {{ $opportunity['due_date'] }} días</p>
                </div>
            </div>
        </header>

        <!-- Entity and Contact Info -->
        <section style="display: grid; grid-template-columns: 1fr 1fr; gap: 32px; margin-bottom: 40px;">
            <div style="background-color: rgba(175, 255, 249, 0.2); padding: 24px; border-radius: 16px; border: 1px solid #AFFFF9;">
                <h3 style="color: #004843; font-size: 12px; font-weight: 900; text-transform: uppercase; letter-spacing: 0.1em; margin: 0 0 8px 0;">Dirigida a:</h3>
                <p style="font-size: 18px; font-weight: bold; color: #333333; margin: 0;">{{ $entity['name'] }}</p>
                <p style="font-size: 18px; font-weight: bold; color: #333333; margin: 0;">{{ $entity['city'] }}</p>
            </div>
            <div style="padding: 24px;">
                <h3 style="color: #004843; font-size: 12px; font-weight: 900; text-transform: uppercase; letter-spacing: 0.1em; margin: 0 0 8px 0;">Responsable:</h3>
                <p style="font-size: 18px; font-weight: bold; color: #333333; margin: 0;">{{ $contact['user']['name'] }}</p>
                <p style="font-size: 14px; color: #4b5563; margin: 0;">{{ $contact['user']['email'] }}</p>
            </div>
        </section>

        <!-- Table -->
        <div style="overflow: hidden; border: 1px solid #e5e7eb; border-radius: 12px; margin-bottom: 32px;">
            <table style="width: 100%; text-align: left;">
                <thead>
                    <tr style="background-color: #009188; color: white;">
                        <th style="padding: 16px; font-weight: bold; text-transform: uppercase; font-size: 12px;">Descripción del Servicio / Recurso</th>
                        <th style="padding: 16px; font-weight: bold; text-transform: uppercase; font-size: 12px; text-align: center;">Unidad</th>
                        <th style="padding: 16px; font-weight: bold; text-transform: uppercase; font-size: 12px; text-align: right;">Cant.</th>
                        <th style="padding: 16px; font-weight: bold; text-transform: uppercase; font-size: 12px; text-align: right;">Vr. Unitario</th>
                        <th style="padding: 16px; font-weight: bold; text-transform: uppercase; font-size: 12px; text-align: right;">Total</th>
                    </tr>
                </thead>
                <tbody style="border-top: 1px solid #f3f4f6;">
                    @foreach($detalle_oportunidad as $item)
                    <tr style="border-bottom: 1px solid #f3f4f6;">
                        <td style="padding: 16px;">{{ $item['name'] }}</td>
                        <td style="padding: 16px; text-align: center;">{{ $item['unidad'] }}</td>
                        <td style="padding: 16px; text-align: right;">{{ $item['qty'] }}</td>
                        <td style="padding: 16px; text-align: right;">{{ $item['unit_value'] }}</td>
                        <td style="padding: 16px; text-align: right;">{{ $item['total'] }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <!-- Observations and Totals -->
        <div style="display: flex; gap: 32px;">
            <div style="flex: 1; font-size: 14px; color: #4b5563;">
                <p style="font-weight: bold; color: #009188; margin: 0 0 8px 0;">Observaciones</p>
                <p style="font-style: italic; margin: 0 0 16px 0;">{{ $opportunity['observations'] }}</p>
                <div style="height: 1px; border-bottom: 1px solid #9ca3af; margin: 16px 0;"></div>
                <p style="font-weight: bold; color: #009188; margin: 0 0 8px 0;">Notas Aclaratorias</p>
                <p style="font-style: italic; margin: 0;">{{ $opportunity['aclarations'] }}</p>
            </div>
            
            <div style="width: 288px; background-color: #f9fafb; padding: 24px; border-radius: 12px;">
                <div style="display: flex; justify-content: space-between; color: #4b5563; margin-bottom: 8px;">
                    <span>Subtotal:</span>
                    <span>{{ $subtotal }}</span>
                </div>
                <div style="display: flex; justify-content: space-between; color: #4b5563; margin-bottom: 8px;">
                    <span>IVA (19%):</span>
                    <span>{{ $iva }}</span>
                </div>
                <div style="display: flex; justify-content: space-between; align-items: center; padding-top: 12px; border-top: 1px solid #e5e7eb;">
                    <span style="font-weight: 900; color: #333333; text-transform: uppercase;">Total:</span>
                    <span style="font-size: 24px; font-weight: 900; color: #B35524;">{{ $total_general }}</span>
                </div>

                <p style="font-weight: 900; color: #6b7280; margin: 16px 0 4px 0; font-size: 12px;">Forma de Pago</p>
                <p style="margin: 0; font-size: 14px;">{{ $opportunity['payment_conditions'] }}</p>
            </div>
        </div>

        <!-- Footer -->
        <footer style="display: grid; grid-template-columns: 1fr 1fr; margin-top: 48px; padding-top: 32px; border-top: 1px solid #e5e7eb; gap: 24px; font-size: 11px; color: #9ca3af; text-transform: uppercase; letter-spacing: 0.05em;">
            <div style="width: 50%; padding-right: 20px;">
                <div style="text-align: center; margin-bottom: 24px;">
                    <p style="font-weight: 900; color: #6b7280; margin: 0 0 4px 0;">Garantía</p>
                    <p style="margin: 0;">{{ $opportunity['guarantees'] }}</p>
                </div>
                <div style="text-align: center; padding-top: 24px; border-top: 1px solid #e5e7eb;">
                    <p style="font-weight: 900; color: #6b7280; margin: 0 0 4px 0;">Tiempo de entrega</p>
                    <p style="font-size: 12px; margin: 0;">{{ $opportunity['delivery_time'] }} días</p>
                </div>
            </div>
            <div style="text-align: center; width: 50%;">
                <p style="color: #009188; font-size: 20px; font-weight: 900; margin: 0;">{{ $brand['name'] }}</p>
                <p style="font-size: 11px; margin: 0;">{{ $brand['business_sign'] }}</p>
            </div>
        </footer>
    </div>
</body>
</html>
