<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    {{-- The document itself carries no user data. The interface resolves the
         session through GET /api/auth/me, so an expired session is handled by
         a server side redirect loop. --}}
    <meta name="app-name" content="{{ config('app.name') }}">
    <meta name="app-version" content="{{ config('app.version') }}">

    <title>{{ config('app.name') }}</title>

    {{-- PWA manifest --}}
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#2563eb">

    @vite(['resources/css/app.css', 'resources/js/app.ts'])
</head>
<body>
    <div id="app"></div>

    {{-- Rendered before the bundle is evaluated so that assistive technology
         and users without JavaScript receive a meaningful message. --}}
    <noscript>
        <div class="noscript-notice">
            <h1>{{ config('app.name') }}</h1>
            <p>Esta aplicación requiere JavaScript para funcionar.</p>
        </div>
    </noscript>
</body>
</html>