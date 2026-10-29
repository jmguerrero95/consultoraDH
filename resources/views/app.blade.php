<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">

    {{-- The document itself carries no user data. The interface resolves the
         session through GET /api/auth/me, so an expired session is handled by
         a server side redirect loop. --}}
    <meta name="app-name" content="{{ config('app.name') }}">
    <meta name="app-version" content="{{ config('app.version') }}">

    {{-- The two fonts on the critical path are preloaded here, in the document,
         instead of from the bundle: the browser discovers them while parsing the
         head, not after the JavaScript has run. Both are resolved through the
         Vite manifest so the hashed filename matches the one the CSS uses; in
         development (or before a build) `Vite::asset` throws and we simply skip
         the preload rather than emit a broken path. --}}
    @php
        $fontPreloads = [];

        foreach ([
            'node_modules/bootstrap-icons/font/fonts/bootstrap-icons.woff2',
            'node_modules/@fontsource-variable/inter/files/inter-latin-wght-normal.woff2',
        ] as $font) {
            try {
                $fontPreloads[] = Vite::asset($font);
            } catch (\Throwable) {
                // No manifest: the dev server injects its own assets.
            }
        }
    @endphp

    @foreach ($fontPreloads as $fontHref)
        <link rel="preload" href="{{ $fontHref }}" as="font" type="font/woff2" crossorigin>
    @endforeach

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