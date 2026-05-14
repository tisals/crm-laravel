<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Acerca de — {{ config('app.name', 'Tecnoinnsoft CRM') }}</title>
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
        <a href="{{ url('/') }}" class="flex items-center gap-3 hover:opacity-80 transition-opacity">
            <img src="/Logo.png" alt="Tecnoinnsoft" class="h-10 w-auto" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex'">
            <div class="hidden items-center justify-center h-10 w-10 rounded-lg bg-teal-600 dark:bg-teal-500 text-white font-bold text-lg">
                T
            </div>
            <span class="font-semibold text-lg tracking-tight">Tecnoinnsoft <span class="text-teal-600 dark:text-teal-500">CRM</span></span>
        </a>

        <div class="flex items-center gap-4">
            <span class="text-sm font-medium text-teal-600 dark:text-teal-500">Acerca de</span>
            <button id="theme-toggle" class="p-2 rounded-lg bg-slate-200 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-300 dark:hover:bg-slate-700 transition-colors" aria-label="Cambiar tema">
                <svg class="hidden dark:block w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                <svg class="block dark:hidden w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/></svg>
            </button>
        </div>
    </header>

    {{-- Main --}}
    <main class="flex-1 px-6 py-12">
        <div class="max-w-4xl mx-auto space-y-16">

            {{-- Hero --}}
            <div class="text-center space-y-4">
                <h1 class="text-3xl md:text-5xl font-bold tracking-tight">
                    Todo tu negocio en un solo <span class="text-teal-600 dark:text-teal-500">lugar</span>
                </h1>
                <p class="text-lg text-slate-600 dark:text-slate-400 max-w-2xl mx-auto">
                    Tecnoinnsoft CRM centraliza la gestión de clientes, licencias y comunicaciones para que puedas enfocarte en hacer crecer tu empresa.
                </p>
            </div>

            {{-- Qué es --}}
            <section class="space-y-6">
                <h2 class="text-2xl font-bold">Qué es Tecnoinnsoft CRM</h2>
                <p class="text-slate-600 dark:text-slate-400 leading-relaxed">
                    Es una plataforma de gestión de relaciones con clientes diseñada para empresas tecnológicas. Permite capturar leads automáticamente desde herramientas de diagnóstico, organizar contactos por empresa, administrar licencias de servicios y mantener una comunicación fluida con cada cliente.
                </p>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div class="p-6 rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800">
                        <div class="w-12 h-12 rounded-xl bg-teal-50 dark:bg-teal-500/10 flex items-center justify-center mb-4">
                            <svg class="w-6 h-6 text-teal-600 dark:text-teal-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                        </div>
                        <h3 class="font-semibold text-lg mb-2">Gestión de contactos</h3>
                        <p class="text-sm text-slate-500 dark:text-slate-400">Almacena todos tus leads y clientes en un solo lugar. Cada contacto se vincula automáticamente con su empresa y se etiqueta según su perfil.</p>
                    </div>
                    <div class="p-6 rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800">
                        <div class="w-12 h-12 rounded-xl bg-teal-50 dark:bg-teal-500/10 flex items-center justify-center mb-4">
                            <svg class="w-6 h-6 text-teal-600 dark:text-teal-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                        </div>
                        <h3 class="font-semibold text-lg mb-2">Organizaciones</h3>
                        <p class="text-sm text-slate-500 dark:text-slate-400">Agrupa contactos por empresa. Controla el estado de cada relación: prospecto, cliente activo o inactivo.</p>
                    </div>
                    <div class="p-6 rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800">
                        <div class="w-12 h-12 rounded-xl bg-teal-50 dark:bg-teal-500/10 flex items-center justify-center mb-4">
                            <svg class="w-6 h-6 text-teal-600 dark:text-teal-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                        </div>
                        <h3 class="font-semibold text-lg mb-2">Licencias y servicios</h3>
                        <p class="text-sm text-slate-500 dark:text-slate-400">Administra qué servicios tiene contratada cada empresa, cuántos usuarios pueden acceder y el estado de cada licencia.</p>
                    </div>
                </div>
            </section>

            {{-- Funcionalidades --}}
            <section class="space-y-6">
                <h2 class="text-2xl font-bold">Qué podés hacer</h2>
                <div class="space-y-4">
                    <div class="flex gap-4 p-4 rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800">
                        <div class="shrink-0 w-10 h-10 rounded-lg bg-teal-50 dark:bg-teal-500/10 flex items-center justify-center text-teal-600 dark:text-teal-500 font-bold">1</div>
                        <div>
                            <h3 class="font-semibold mb-1">Capturar leads automáticamente</h3>
                            <p class="text-sm text-slate-500 dark:text-slate-400">Cuando un potencial cliente completa un diagnóstico en tu sitio web, sus datos llegan directo al CRM sin intervención manual. Se crea el contacto, la empresa y la licencia correspondiente en segundos.</p>
                        </div>
                    </div>
                    <div class="flex gap-4 p-4 rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800">
                        <div class="shrink-0 w-10 h-10 rounded-lg bg-teal-50 dark:bg-teal-500/10 flex items-center justify-center text-teal-600 dark:text-teal-500 font-bold">2</div>
                        <div>
                            <h3 class="font-semibold mb-1">Etiquetado inteligente</h3>
                            <p class="text-sm text-slate-500 dark:text-slate-400">El sistema clasifica automáticamente cada contacto según su eje dominante (tecnología, estrategia, economía) y rango de presupuesto. Así podés segmentar tus comunicaciones con precisión.</p>
                        </div>
                    </div>
                    <div class="flex gap-4 p-4 rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800">
                        <div class="shrink-0 w-10 h-10 rounded-lg bg-teal-50 dark:bg-teal-500/10 flex items-center justify-center text-teal-600 dark:text-teal-500 font-bold">3</div>
                        <div>
                            <h3 class="font-semibold mb-1">Controlar licencias por servicio</h3>
                            <p class="text-sm text-slate-500 dark:text-slate-400">Definí cuántos usuarios puede tener cada cliente en cada servicio. El sistema controla los límites automáticamente y te avisa cuando una licencia está por vencer o necesita renovación.</p>
                        </div>
                    </div>
                    <div class="flex gap-4 p-4 rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800">
                        <div class="shrink-0 w-10 h-10 rounded-lg bg-teal-50 dark:bg-teal-500/10 flex items-center justify-center text-teal-600 dark:text-teal-500 font-bold">4</div>
                        <div>
                            <h3 class="font-semibold mb-1">Enviar reportes personalizados</h3>
                            <p class="text-sm text-slate-500 dark:text-slate-400">Cada vez que se registra un nuevo diagnóstico, el sistema puede enviar automáticamente un reporte técnico al correo del cliente con los resultados y recomendaciones.</p>
                        </div>
                    </div>
                    <div class="flex gap-4 p-4 rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800">
                        <div class="shrink-0 w-10 h-10 rounded-lg bg-teal-50 dark:bg-teal-500/10 flex items-center justify-center text-teal-600 dark:text-teal-500 font-bold">5</div>
                        <div>
                            <h3 class="font-semibold mb-1">Integrar con otros sistemas</h3>
                            <p class="text-sm text-slate-500 dark:text-slate-400">El CRM se comunica en tiempo real con plataformas externas. Cuando creás una organización, actualizás un contacto o recibís un pago, otros sistemas se enteran al instante.</p>
                        </div>
                    </div>
                </div>
            </section>

            {{-- Beneficios --}}
            <section class="space-y-6">
                <h2 class="text-2xl font-bold">Beneficios clave</h2>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="flex items-start gap-3 p-4">
                        <svg class="w-5 h-5 text-teal-600 dark:text-teal-500 mt-0.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        <div>
                            <h4 class="font-medium mb-1">Menos trabajo manual</h4>
                            <p class="text-sm text-slate-500 dark:text-slate-400">La captura automática de leads elimina el ingreso manual de datos y reduce errores.</p>
                        </div>
                    </div>
                    <div class="flex items-start gap-3 p-4">
                        <svg class="w-5 h-5 text-teal-600 dark:text-teal-500 mt-0.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        <div>
                            <h4 class="font-medium mb-1">Visión unificada del cliente</h4>
                            <p class="text-sm text-slate-500 dark:text-slate-400">Todos los contactos, servicios y comunicaciones de una empresa en una sola vista.</p>
                        </div>
                    </div>
                    <div class="flex items-start gap-3 p-4">
                        <svg class="w-5 h-5 text-teal-600 dark:text-teal-500 mt-0.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        <div>
                            <h4 class="font-medium mb-1">Control de licencias sin complicaciones</h4>
                            <p class="text-sm text-slate-500 dark:text-slate-400">Sabrés en todo momento quién tiene acceso a qué, cuándo vence y si puede agregar más usuarios.</p>
                        </div>
                    </div>
                    <div class="flex items-start gap-3 p-4">
                        <svg class="w-5 h-5 text-teal-600 dark:text-teal-500 mt-0.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        <div>
                            <h4 class="font-medium mb-1">Comunicación proactiva</h4>
                            <p class="text-sm text-slate-500 dark:text-slate-400">Los reportes automáticos mantienen a tus clientes informados sin que tengas que hacer nada.</p>
                        </div>
                    </div>
                    <div class="flex items-start gap-3 p-4">
                        <svg class="w-5 h-5 text-teal-600 dark:text-teal-500 mt-0.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        <div>
                            <h4 class="font-medium mb-1">Escalable desde el primer día</h4>
                            <p class="text-sm text-slate-500 dark:text-slate-400">Funciona tanto para 10 clientes como para 10.000. La arquitectura crece con tu negocio.</p>
                        </div>
                    </div>
                    <div class="flex items-start gap-3 p-4">
                        <svg class="w-5 h-5 text-teal-600 dark:text-teal-500 mt-0.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        <div>
                            <h4 class="font-medium mb-1">Integración lista para usar</h4>
                            <p class="text-sm text-slate-500 dark:text-slate-400">Conectá con tus herramientas existentes sin desarrollos complejos ni configuraciones difíciles.</p>
                        </div>
                    </div>
                </div>
            </section>

            {{-- Planes --}}
            <section class="space-y-6">
                <h2 class="text-2xl font-bold">Planes disponibles</h2>
                <p class="text-slate-600 dark:text-slate-400">Elegí el plan que se adapte al tamaño de tu empresa. Todos incluyen soporte técnico y actualizaciones.</p>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div class="p-6 rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800">
                        <h3 class="font-bold text-lg mb-2">Starter Kit</h3>
                        <div class="text-3xl font-bold text-teal-600 dark:text-teal-500 mb-4">$27<span class="text-sm font-normal text-slate-500 dark:text-slate-400">/mes</span></div>
                        <ul class="space-y-2 text-sm text-slate-600 dark:text-slate-400">
                            <li class="flex items-center gap-2"><svg class="w-4 h-4 text-teal-600 dark:text-teal-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>Hasta 5 usuarios</li>
                            <li class="flex items-center gap-2"><svg class="w-4 h-4 text-teal-600 dark:text-teal-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>Plugin básico</li>
                            <li class="flex items-center gap-2"><svg class="w-4 h-4 text-teal-600 dark:text-teal-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>Registro de leads</li>
                            <li class="flex items-center gap-2"><svg class="w-4 h-4 text-teal-600 dark:text-teal-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>Soporte por email</li>
                        </ul>
                    </div>
                    <div class="p-6 rounded-xl bg-teal-600 dark:bg-teal-600 text-white relative overflow-hidden">
                        <div class="absolute top-3 right-3 px-2 py-0.5 rounded text-xs font-medium bg-white/20">Más popular</div>
                        <h3 class="font-bold text-lg mb-2">Pro</h3>
                        <div class="text-3xl font-bold mb-4">$97<span class="text-sm font-normal text-teal-100">/mes</span></div>
                        <ul class="space-y-2 text-sm text-teal-50">
                            <li class="flex items-center gap-2"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>Hasta 20 usuarios</li>
                            <li class="flex items-center gap-2"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>Todo lo de Starter</li>
                            <li class="flex items-center gap-2"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>Diagnósticos avanzados</li>
                            <li class="flex items-center gap-2"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>Soporte prioritario</li>
                        </ul>
                    </div>
                    <div class="p-6 rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800">
                        <h3 class="font-bold text-lg mb-2">Enterprise</h3>
                        <div class="text-3xl font-bold text-teal-600 dark:text-teal-500 mb-4">$297<span class="text-sm font-normal text-slate-500 dark:text-slate-400">/mes</span></div>
                        <ul class="space-y-2 text-sm text-slate-600 dark:text-slate-400">
                            <li class="flex items-center gap-2"><svg class="w-4 h-4 text-teal-600 dark:text-teal-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>Usuarios ilimitados</li>
                            <li class="flex items-center gap-2"><svg class="w-4 h-4 text-teal-600 dark:text-teal-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>Todo lo de Pro</li>
                            <li class="flex items-center gap-2"><svg class="w-4 h-4 text-teal-600 dark:text-teal-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>Web gestionado</li>
                            <li class="flex items-center gap-2"><svg class="w-4 h-4 text-teal-600 dark:text-teal-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>Administrador dedicado</li>
                        </ul>
                    </div>
                </div>
            </section>

            {{-- CTA --}}
            <div class="text-center py-8">
                <a href="{{ url('/') }}" class="inline-flex items-center gap-2 px-6 py-3 rounded-lg bg-teal-600 dark:bg-teal-500 text-white font-medium hover:bg-teal-700 dark:hover:bg-teal-400 transition-colors">
                    Volver al inicio
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
                </a>
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
