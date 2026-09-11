<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        {{-- Config de Reverb en tiempo de ejecución (no depende del build de Vite) --}}
        <meta name="reverb-key" content="{{ env('VITE_REVERB_APP_KEY', env('REVERB_APP_KEY', '')) }}">
        <meta name="reverb-host" content="{{ env('VITE_REVERB_HOST', env('REVERB_HOST', '')) }}">
        <meta name="reverb-port" content="{{ env('VITE_REVERB_PORT', env('REVERB_PORT', '')) }}">
        <meta name="reverb-scheme" content="{{ env('VITE_REVERB_SCHEME', env('REVERB_SCHEME', 'https')) }}">

        <title inertia>{{ config('app.name', 'Laravel') }}</title>
        <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml" />
        <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any" />
        <meta name="theme-color" content="#4f46e5" />

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @routes
        @php($ziggyUrl = rtrim((string) url('/'), '/'))
        <script>
            // Ziggy genera las URLs con APP_URL. Lo alineamos al host real de la petición
            // (p. ej. 127.0.0.1:8000 o localhost:8000) para evitar peticiones cross-origin
            // que el navegador bloquea por CORS.
            if (typeof Ziggy !== 'undefined' && Ziggy.url !== @json($ziggyUrl)) {
                Ziggy.url = @json($ziggyUrl);
            }
        </script>
        @vite(['resources/js/app.js', "resources/js/Pages/{$page['component']}.vue"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
