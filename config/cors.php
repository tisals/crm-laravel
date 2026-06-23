<?php

/*
|--------------------------------------------------------------------------
| CORS (Cross-Origin Resource Sharing) Configuration
|--------------------------------------------------------------------------
|
| Orígenes autorizados a consumir la API desde el navegador.
| Editar la variable de entorno CORS_ALLOWED_ORIGINS (separar con comas).
| Default: los dos frontends conocidos (crm.tecnoinnsoft.com y
| prod-dashboard-crm.jsvdny.easypanel.host).
|
*/

$originsEnv = env(
    'CORS_ALLOWED_ORIGINS',
    'https://crm.tecnoinnsoft.com,https://prod-dashboard-crm.jsvdny.easypanel.host'
);

return [
    // Rutas cubiertas por CORS (toda la API)
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    // Métodos HTTP permitidos
    'allowed_methods' => ['*'],

    // Orígenes permitidos (leídos de .env, fallback a los 2 frontends conocidos)
    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', $originsEnv)
    ))),

    // Patrones regex de orígenes (vacío por ahora)
    'allowed_origins_patterns' => [],

    // Headers permitidos en la request (Authorization: Bearer va acá)
    'allowed_headers' => ['*'],

    // Headers expuestos al frontend en la respuesta
    'exposed_headers' => [],

    // Cache del preflight (en segundos)
    'max_age' => 86400,

    // false porque usamos Bearer tokens en Authorization, NO cookies de sesión.
    // Si en algún momento se usa Sanctum stateful con cookies, cambiar a true
    // y recordar que allowed_origins NO puede ser '*' cuando esto es true.
    'supports_credentials' => false,
];
