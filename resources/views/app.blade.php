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
    <meta name="theme-color" content="#1094D6">

    {{-- Official brand icons. The query string busts the old generic favicon
         without touching user data, cookies or service worker caches. --}}
    <link rel="icon" href="/favicon.ico?v=2" sizes="any">
    <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png?v=2">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png?v=2">
    <link rel="icon" type="image/png" sizes="48x48" href="/favicon-48x48.png?v=2">
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png?v=2">

    <meta name="application-name" content="Consultora DH">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="Consultora DH">

    {{-- iOS startup images. Android builds its native splash from the manifest
         colour and the maskable icons; iOS needs an explicit per-device link.
         The default status bar style keeps the artwork visible. --}}
    <link rel="apple-touch-startup-image" href="/branding/splash/splash-640x1136.png" media="(device-width: 320px) and (device-height: 568px) and (-webkit-device-pixel-ratio: 2) and (orientation: portrait)">
    <link rel="apple-touch-startup-image" href="/branding/splash/splash-750x1334.png" media="(device-width: 375px) and (device-height: 667px) and (-webkit-device-pixel-ratio: 2) and (orientation: portrait)">
    <link rel="apple-touch-startup-image" href="/branding/splash/splash-828x1792.png" media="(device-width: 414px) and (device-height: 896px) and (-webkit-device-pixel-ratio: 2) and (orientation: portrait)">
    <link rel="apple-touch-startup-image" href="/branding/splash/splash-1125x2436.png" media="(device-width: 375px) and (device-height: 812px) and (-webkit-device-pixel-ratio: 3) and (orientation: portrait)">
    <link rel="apple-touch-startup-image" href="/branding/splash/splash-1170x2532.png" media="(device-width: 390px) and (device-height: 844px) and (-webkit-device-pixel-ratio: 3) and (orientation: portrait)">
    <link rel="apple-touch-startup-image" href="/branding/splash/splash-1179x2556.png" media="(device-width: 393px) and (device-height: 852px) and (-webkit-device-pixel-ratio: 3) and (orientation: portrait)">
    <link rel="apple-touch-startup-image" href="/branding/splash/splash-1242x2688.png" media="(device-width: 414px) and (device-height: 896px) and (-webkit-device-pixel-ratio: 3) and (orientation: portrait)">
    <link rel="apple-touch-startup-image" href="/branding/splash/splash-1284x2778.png" media="(device-width: 428px) and (device-height: 926px) and (-webkit-device-pixel-ratio: 3) and (orientation: portrait)">
    <link rel="apple-touch-startup-image" href="/branding/splash/splash-1290x2796.png" media="(device-width: 430px) and (device-height: 932px) and (-webkit-device-pixel-ratio: 3) and (orientation: portrait)">
    <link rel="apple-touch-startup-image" href="/branding/splash/splash-1536x2048.png" media="(device-width: 768px) and (device-height: 1024px) and (-webkit-device-pixel-ratio: 2) and (orientation: portrait)">
    <link rel="apple-touch-startup-image" href="/branding/splash/splash-1668x2224.png" media="(device-width: 834px) and (device-height: 1112px) and (-webkit-device-pixel-ratio: 2) and (orientation: portrait)">
    <link rel="apple-touch-startup-image" href="/branding/splash/splash-2048x2732.png" media="(device-width: 1024px) and (device-height: 1366px) and (-webkit-device-pixel-ratio: 2) and (orientation: portrait)">
    <link rel="apple-touch-startup-image" href="/branding/splash/splash-2048x1536.png" media="(device-width: 1024px) and (device-height: 768px) and (-webkit-device-pixel-ratio: 2) and (orientation: landscape)">
    <link rel="apple-touch-startup-image" href="/branding/splash/splash-2732x2048.png" media="(device-width: 1366px) and (device-height: 1024px) and (-webkit-device-pixel-ratio: 2) and (orientation: landscape)">

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