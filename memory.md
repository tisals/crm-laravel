# Memory - CRM Laravel Session

## Goal
Configuración inicial CRM Laravel 12

## What Was Done
- Proyecto Laravel 12 detectado con stack moderno
- PHP ^8.2, Laravel 12, Sanctum ^4.3
- Frontend: Vite 7, Tailwind CSS 4
- Tests configurados con PHPUnit 11

## Key Discoveries
- Laravel 12 (versión muy reciente)
- Tailwind CSS 4 + Vite 7 (stack frontend moderno)
- Comando `composer test` configurado con artisan test
- Laravel Sail incluido para Docker

## Files Created
- AGENTS.md - Contexto para agentes
- memory.md - Persistencia de sesión

## How to Proceed
1. Revisar estructura actual de módulos CRM
2. Configurar autenticación con Sanctum
3. Definir modelos y migraciones principales
4. Configurar frontend con Vite + Tailwind

## Lecciones Aprendidas
- Laravel 12 es muy nuevo, verificar compatibilidad de paquetes
- Vite 7 + Tailwind 4 requiere configuración específica
- Concurrently permite correr múltiples servicios en dev
