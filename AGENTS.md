# AGENTS.md - Contexto Importante para Agentes IA

## Estructura del Proyecto
```
crm-laravel/
├── app/                # Código principal Laravel 12
├── bootstrap/         # Bootstrap Laravel
├── config/            # Configuraciones
├── database/          # Migraciones y seeders
├── resources/         # Vistas, assets (Vite + Tailwind CSS 4)
├── routes/            # Rutas (web, api)
├── tests/             # Tests PHPUnit 11
├── vendor/            # Dependencias PHP
├── node_modules/      # Dependencias JS
├── composer.json      # Laravel 12, PHP ^8.2, Sanctum ^4.3
├── package.json       # Vite 7, Tailwind CSS 4, Axios, Laravel Vite Plugin
├── vite.config.js     # Configuración Vite
├── phpunit.xml        # Config PHPUnit
├── artisan            # CLI Laravel
└── .env.example       # Variables de entorno
```

## Reglas Críticas
### 0. Inicialización (/init)
- Ejecutar /init al inicio de nuevo proyecto

### 1. Memoria
- Consultar memory.md al inicio de cada sesión
- AL TOCAR 60% → ejecutar `/compact` o proceso manual

### 2. Credenciales
- NO hardcodear tokens, API keys, passwords
- Usar variables de entorno (.env)

## Comandos Útiles
- `composer install` - Instalar dependencias PHP
- `npm install` - Instalar dependencias JS
- `npm run dev` - Desarrollo con Vite
- `npm run build` - Build producción
- `php artisan serve` - Servidor desarrollo
- `php artisan test` - Ejecutar tests (PHPUnit 11)
- `php artisan migrate` - Migraciones
- `composer test` - Test con config clear + artisan test

## Testing
- **Framework**: PHPUnit ^11.5.50
- **Command**: `php artisan test` o `composer test`
- **Laravel Pint**: ^1.24 (formateo de código)
- **Laravel Sail**: ^1.41 (Docker)
- **Laravel Pail**: ^1.2.2 (logs en tiempo real)

## Stack Tecnológico
- **Backend**: Laravel 12, PHP ^8.2
- **Frontend**: Vite 7, Tailwind CSS 4, Axios
- **Auth**: Laravel Sanctum ^4.3
- **Dev Tools**: Concurrently, Laravel Vite Plugin

## Archivos de Referencia
| Archivo | Propósito |
|---------|-----------|
| app/ | Lógica de negocio CRM |
| routes/api.php | Endpoints API |
| resources/js/ | Frontend Vite |
| database/migrations/ | Esquema BD |
| tests/ | Tests automatizados PHPUnit |

## Errores Comunes a Evitar
1. No commitear .env con credenciales
2. Ejecutar `npm run build` antes de producción
3. Verificar Sanctum para APIs
4. Cuidar configuración de Vite + Tailwind CSS 4
