<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Cotizacion {{ $cotizacion_no }}</title>
    <style>
        @page { size: letter; margin: 15mm; }
        body {
            margin: 0;
            padding: 0;
            font-family: Arial, Helvetica, sans-serif;
            color: #333333;
            font-size: 9pt;
        }
        h1 { margin: 0; font-size: 18pt; font-weight: bold; text-transform: uppercase; color: #333333; }
        h2 { margin: 0; font-size: 8pt; font-weight: bold; text-transform: uppercase; color: #004843; letter-spacing: 1px; }
        p { margin: 0; }
        a { color: inherit; text-decoration: none; }
        .teal { color: #009188; }
        .orange { color: #B35524; }
        .dark-teal { color: #004843; }
        .gray { color: #6b7280; }
        .light-gray { color: #9ca3af; }
        .footer {
            position: fixed;
            bottom: 0;
            left: 30px;
            right: 30px;
        }
        .content {
            padding-bottom: 70px;
        }
    </style>
</head>
<body style="background-color: #ffffff;">

<!-- Outer container with top teal border -->
<div style="border-top: 8px solid #009188; padding: 20px 30px 0 30px;">

    <div class="content">

        <!-- ====== HEADER ====== -->
        <table width="100%" cellpadding="0" cellspacing="0" style="border-bottom: 1px solid #e5e7eb; padding-bottom: 12px; margin-bottom: 12px;">
            <tr>
                <td width="50%" valign="top" style="padding-bottom: 8px;">
                    @if($brand['logo'])
                    <img src="{{ $brand['logo'] }}"
                         alt="Logo"
                         style="height: 48px; width: auto;" />
                    @else
                    <img src="https://deseguridad.net/wp-content/uploads/2026/05/Logo-tecnoinnsoft-183x90-1.png"
                         alt="Logo"
                         style="height: 48px; width: auto;" />
                    @endif
                    <p style="color: #004843; font-size: 8pt; font-weight: bold; margin-top: 6px;">
                        {{ $brand['nombre_comercial'] }}
                    </p>
                    @if($brand['nit'])
                    <p style="color: #6b7280; font-size: 7pt;">
                        NIT {{ $brand['nit'] }}
                    </p>
                    @endif
                </td>
                <td width="50%" valign="top" align="right">
                    <h1>Cotizacion</h1>
                    <p class="teal" style="font-size: 12pt; font-weight: bold; margin-top: 4px;">
                        N&deg; {{ $opportunity['id'] }}-v{{ $opportunity['version'] }}
                    </p>
                    <p class="gray" style="font-size: 7pt; margin-top: 4px;">
                        Fecha: {{ $opportunity['sent_at'] }}<br>
                        Validez: {{ $opportunity['due_date'] }} dias
                    </p>
                </td>
            </tr>
        </table>

        <!-- ====== DIRIGIDA A / RESPONSABLE ====== -->
        <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom: 14px;">
            <tr>
                <td width="50%" valign="top" style="padding-right: 10px;">
                    <table width="100%" cellpadding="6" cellspacing="0" style="background-color: #e0fffc; border: 1px solid #AFFFF9;">
                        <tr>
                            <td>
                                <h2 style="margin-bottom: 4px;">Dirigida a:</h2>
                                <p style="font-size: 11pt; font-weight: bold; color: #333333;">{{ $entity['name'] }}</p>
                                @if(isset($client_contact) && $client_contact['name'] !== '—')
                                    <p style="font-size: 8pt; color: #4b5563; margin-top: 4px;"><strong>Atención:</strong> {{ $client_contact['name'] }}</p>
                                    <p style="font-size: 8pt; color: #6b7280;">Email: {{ $client_contact['email'] }}</p>
                                    <p style="font-size: 8pt; color: #6b7280;">Tel: {{ $client_contact['telefono'] }}</p>
                                @endif
                            </td>
                        </tr>
                    </table>
                </td>
                <td width="50%" valign="top" style="padding-left: 10px;">
                    <table width="100%" cellpadding="6" cellspacing="0">
                        <tr>
                            <td>
                                <h2 style="margin-bottom: 4px;">Responsable Comercial:</h2>
                                <p style="font-size: 8pt; color: #6b7280;">Nombre usuario:</p>
                                <p style="font-size: 11pt; font-weight: bold; color: #333333;">{{ $contact['user']['name'] }}</p>
                                <p style="font-size: 8pt; color: #6b7280; margin-top: 4px;">Tel:</p>
                                <p style="font-size: 9pt; color: #333333;">{{ $contact['user']['telefono'] ?? '—' }}</p>
                                <p style="font-size: 8pt; color: #6b7280; margin-top: 4px;">Email:</p>
                                <p style="font-size: 9pt; color: #333333;">{{ $contact['user']['email'] }}</p>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>

        <!-- ====== ITEMS TABLE ====== -->
        <table width="100%" cellpadding="10" cellspacing="0" style="border: 1px solid #e5e7eb; margin-bottom: 14px;">
            <thead>
                <tr style="background-color: #009188; color: #ffffff;">
                    <th width="30%" style="padding: 6px 8px; font-size: 7pt; font-weight: bold; text-transform: uppercase; text-align: left; color: #ffffff;">Producto</th>
                    <th width="40%" style="padding: 6px 8px; font-size: 7pt; font-weight: bold; text-transform: uppercase; text-align: left; color: #ffffff;">Descripción / Concepto</th>
                    <th width="8%" style="padding: 6px 8px; font-size: 7pt; font-weight: bold; text-transform: uppercase; text-align: center; color: #ffffff;">Unidad</th>
                    <th width="7%" style="padding: 6px 8px; font-size: 7pt; font-weight: bold; text-transform: uppercase; text-align: right; color: #ffffff;">Cant.</th>
                    <th width="10%" style="padding: 6px 8px; font-size: 7pt; font-weight: bold; text-transform: uppercase; text-align: right; color: #ffffff;">Vr. Unit.</th>
                    <th width="10%" style="padding: 6px 8px; font-size: 7pt; font-weight: bold; text-transform: uppercase; text-align: right; color: #ffffff;">Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach($detalle_oportunidad as $index => $item)
                <tr style="background-color: {{ $index % 2 == 0 ? '#ffffff' : '#f9fafb' }};">
                    <td style="padding: 5px 8px; font-size: 8pt; font-weight: bold; border-bottom: 1px solid #f3f4f6;">{{ $item['producto'] }}</td>
                    <td style="padding: 5px 8px; font-size: 8pt; border-bottom: 1px solid #f3f4f6;">{{ $item['descripcion'] }}</td>
                    <td style="padding: 5px 8px; font-size: 8pt; text-align: center; border-bottom: 1px solid #f3f4f6;">{{ $item['unidad'] }}</td>
                    <td style="padding: 5px 8px; font-size: 8pt; text-align: right; border-bottom: 1px solid #f3f4f6;">{{ $item['qty'] }}</td>
                    <td style="padding: 5px 8px; font-size: 8pt; text-align: right; border-bottom: 1px solid #f3f4f6;">{{ $item['unit_value'] }}</td>
                    <td style="padding: 5px 8px; font-size: 8pt; text-align: right; border-bottom: 1px solid #f3f4f6;">{{ $item['total'] }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>

        <!-- ====== OBSERVATIONS + TOTALS ====== -->
        <table width="100%" cellpadding="10" cellspacing="0" style="margin-bottom: 4px;">
            <tr>
                <!-- Left: Observations -->
                <td width="55%" valign="top" style="padding-right: 12px;">
                    <p class="teal" style="font-size: 8pt; font-weight: bold; margin-bottom: 3px;">Observaciones</p>
                    <p style="font-size: 8pt; font-style: italic; color: #4b5563;">{!! nl2br(e(preg_replace('/\. /', ".\n", $opportunity['observations']))) !!}</p>
                </td>

                <!-- Right: Totals -->
                <td width="45%" valign="top">
                    <table width="100%" cellpadding="8" cellspacing="0" style="background-color: #f9fafb;">
                        <tr>
                            <td>
                                <table width="100%" cellpadding="2" cellspacing="0">
                                    <tr>
                                        <td class="gray" style="font-size: 8pt;">Subtotal:</td>
                                        <td align="right" class="gray" style="font-size: 8pt;">{{ $subtotal }}</td>
                                    </tr>
                                    <tr>
                                        <td class="gray" style="font-size: 8pt;">IVA (19%):</td>
                                        <td align="right" class="gray" style="font-size: 8pt;">{{ $iva }}</td>
                                    </tr>
                                    <tr>
                                        <td colspan="2" style="height: 6px; border-top: 1px solid #e5e7eb;"></td>
                                    </tr>
                                    <tr>
                                        <td style="font-size: 9pt; font-weight: bold; text-transform: uppercase; color: #333333;">Total:</td>
                                        <td align="right" style="font-size: 14pt; font-weight: bold; color: #B35524;">{{ $total_general }}</td>
                                    </tr>
                                </table>

                                <p style="font-size: 7pt; font-weight: bold; color: #6b7280; margin-top: 8px; margin-bottom: 2px;">Forma de Pago</p>
                                <p style="font-size: 8pt; color: #333333;">{!! nl2br(e($opportunity['payment_conditions'])) !!}</p>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>

        <!-- ====== NOTAS ACLARATORIAS (full width) ====== -->
        <table width="100%" cellpadding="10" cellspacing="0" style="margin-bottom: 14px;">
            <tr>
                <td style="border-top: 1px solid #9ca3af; padding-top: 10px;">
                    <p class="teal" style="font-size: 8pt; font-weight: bold; margin-bottom: 3px;">Notas Aclaratorias</p>
                    <p style="font-size: 8pt; font-style: italic; color: #4b5563;">{!! nl2br(e(preg_replace('/\. /', ".\n", $opportunity['aclarations']))) !!}</p>
                </td>
            </tr>
        </table>

    </div><!-- .content -->

</div>

<!-- ====== FOOTER (fijo al fondo) ====== -->
<div class="footer">
    <table width="100%" cellpadding="10" cellspacing="0">
        <tr>
            <!-- Left: Guarantee + Delivery -->
            <td width="60%" valign="top">
                <table width="100%" cellpadding="5" cellspacing="0">
                    <tr>
                        <td width="50%" align="center" valign="top">
                            <p style="font-size: 7pt; font-weight: bold; color: #6b7280; margin-bottom: 2px;">Garantia</p>
                            <p style="font-size: 7pt; color: #9ca3af;">{!! nl2br(e($opportunity['guarantees'])) !!}</p>
                        </td>
                        <td width="50%" align="center" valign="top" style="border-left: 1px solid #e5e7eb;">
                            <p style="font-size: 7pt; font-weight: bold; color: #6b7280; margin-bottom: 2px;">Tiempo de entrega</p>
                            <p style="font-size: 7pt; color: #9ca3af;">{{ $opportunity['delivery_time'] }} dias</p>
                        </td>
                    </tr>
                </table>
            </td>

            <!-- Right: Firma (siempre Tecnoinnsoft) -->
            <td width="40%" valign="top" align="center">
                @if($firma['email'])<p style="font-size: 7pt; color: #6b7280;">{{ $firma['email'] }}</p>@endif
                @if($firma['direccion'])<p style="font-size: 7pt; color: #9ca3af;">{{ $firma['direccion'] }}</p>@endif
                @if($firma['telefono'])<p style="font-size: 7pt; color: #9ca3af;">{{ $firma['telefono'] }}</p>@endif
                @if($firma['dominio'])<p style="font-size: 7pt; color: #9ca3af;">{{ $firma['dominio'] }}</p>@endif
            </td>
        </tr>
    </table>
</div>

</body>
</html>
