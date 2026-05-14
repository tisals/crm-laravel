<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'Tecnoinnsoft CRM') }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script>
        (function() {
            const saved = localStorage.getItem('theme');
            const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            if (saved === 'dark' || (!saved && prefersDark)) {
                document.documentElement.classList.add('dark');
            } else {
                document.documentElement.classList.remove('dark');
            }
        })();
    </script>
</head>
<body class="bg-slate-50 text-slate-950 dark:bg-slate-950 dark:text-white min-h-screen flex flex-col transition-colors duration-300">

    {{-- Header --}}
    <header class="w-full px-6 py-4 flex items-center justify-between border-b border-slate-200 dark:border-slate-800">
        <div class="flex items-center gap-3">
            <img src="/Logo.png" alt="Tecnoinnsoft" class="h-10 w-auto" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex'">
            <div class="hidden items-center justify-center h-10 w-10 rounded-lg bg-teal-600 dark:bg-teal-500 text-white font-bold text-lg">
                T
            </div>
            <span class="font-semibold text-lg tracking-tight">Tecnoinnsoft <span class="text-teal-600 dark:text-teal-500">CRM</span></span>
        </div>

        <div class="flex items-center gap-4">
            <a href="{{ url('/about') }}" class="text-sm font-medium hover:text-teal-600 dark:hover:text-teal-500 transition-colors">
                Acerca de
            </a>
            <button id="theme-toggle" class="p-2 rounded-lg bg-slate-200 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-300 dark:hover:bg-slate-700 transition-colors" aria-label="Cambiar tema">
                {{-- Sun icon (visible en dark) --}}
                <svg class="hidden dark:block w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                {{-- Moon icon (visible en light) --}}
                <svg class="block dark:hidden w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/></svg>
            </button>
        </div>
    </header>

    {{-- Main --}}
    <main class="flex-1 flex flex-col items-center justify-center px-6 py-16">
        <div class="max-w-3xl w-full text-center space-y-8">

            <div class="space-y-4">
                <h1 class="text-4xl md:text-6xl font-bold tracking-tight">
                    API <span class="text-teal-600 dark:text-teal-500">CRM</span> Backend
                </h1>
                <p class="text-lg md:text-xl text-slate-600 dark:text-slate-400 max-w-2xl mx-auto">
                    Plataforma de gestión de contactos, organizaciones y licencias para el ecosistema Tecnoinnsoft.
                </p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-left">
                <div class="p-6 rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm">
                    <div class="w-10 h-10 rounded-lg bg-teal-50 dark:bg-teal-500/10 flex items-center justify-center mb-4">
                        <svg class="w-5 h-5 text-teal-600 dark:text-teal-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                    </div>
                    <h3 class="font-semibold mb-1">Webhooks</h3>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Registro automático de leads desde herramientas de diagnóstico.</p>
                </div>
                <div class="p-6 rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm">
                    <div class="w-10 h-10 rounded-lg bg-teal-50 dark:bg-teal-500/10 flex items-center justify-center mb-4">
                        <svg class="w-5 h-5 text-teal-600 dark:text-teal-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                    </div>
                    <h3 class="font-semibold mb-1">Licencias</h3>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Gestión de usuarios activos y límites por servicio.</p>
                </div>
                <div class="p-6 rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm">
                    <div class="w-10 h-10 rounded-lg bg-teal-50 dark:bg-teal-500/10 flex items-center justify-center mb-4">
                        <svg class="w-5 h-5 text-teal-600 dark:text-teal-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                    </div>
                    <h3 class="font-semibold mb-1">API REST</h3>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Contrato claro para integración con servicios FastAPI.</p>
                </div>
            </div>

            <div class="flex flex-wrap items-center justify-center gap-3 pt-4">
                <span class="px-3 py-1 rounded-full text-xs font-medium bg-slate-200 dark:bg-slate-800 text-slate-700 dark:text-slate-300">Laravel 12</span>
                <span class="px-3 py-1 rounded-full text-xs font-medium bg-slate-200 dark:bg-slate-800 text-slate-700 dark:text-slate-300">Sanctum</span>
                <span class="px-3 py-1 rounded-full text-xs font-medium bg-slate-200 dark:bg-slate-800 text-slate-700 dark:text-slate-300">Tailwind v4</span>
                <span class="px-3 py-1 rounded-full text-xs font-medium bg-slate-200 dark:bg-slate-800 text-slate-700 dark:text-slate-300">Clean Architecture</span>
            </div>
        </div>
    </main>

    {{-- Footer --}}
    <footer class="px-6 py-6 text-center text-sm text-slate-400 dark:text-slate-600 border-t border-slate-200 dark:border-slate-800">
        &copy; {{ date('Y') }} Tecnoinnsoft. Todos los derechos reservados.
    </footer>

    <script>
        const toggle = document.getElementById('theme-toggle');
        const html = document.documentElement;
        toggle.addEventListener('click', () => {
            html.classList.toggle('dark');
            localStorage.setItem('theme', html.classList.contains('dark') ? 'dark' : 'light');
        });
    </script>
</body>
</html>
