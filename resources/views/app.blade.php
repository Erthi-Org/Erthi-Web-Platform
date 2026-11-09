<!doctype html>
<html lang="{{ $page['props']['locale'] ?? 'ar' }}" dir="{{ ($page['props']['locale'] ?? 'ar') === 'en' ? 'ltr' : 'rtl' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @if (in_array($page['component'] ?? '', ['Admin', 'Login'], true))
        <meta name="erthi-admin-base" content="/{{ config('erthi.admin_prefix') }}">
    @endif
    <meta name="theme-color" content="#19110e">
    <meta name="robots" content="{{ ($page['props']['seo']['index'] ?? false) ? 'index, follow' : 'noindex, nofollow' }}">
    <link rel="icon" type="image/svg+xml" href="/brand/book.svg">
    <link rel="alternate icon" type="image/png" href="/brand/book-32.png">
    <link rel="apple-touch-icon" href="/brand/book-192.png">
    <link rel="manifest" href="/manifest.webmanifest">
    @if (($page['props']['page'] ?? null) === 'home')
        <link rel="preload" as="image" href="/brand/scene-mobile.webp" media="(max-width: 900px)" fetchpriority="high">
        <link rel="preload" as="image" href="/brand/scene.webp" imagesrcset="/brand/scene-medium.webp 1152w, /brand/scene.webp 1536w" imagesizes="100vw" media="(width > 900px)" fetchpriority="high">
    @endif

    @isset($page['props']['seo'])
        @php($seo = $page['props']['seo'])
        <meta name="description" content="{{ $seo['description'] }}">
        <link rel="canonical" href="{{ $seo['canonical'] }}">
        @foreach($seo['alternates'] as $lang => $alternate)
            <link rel="alternate" hreflang="{{ $lang }}" href="{{ $alternate }}">
        @endforeach
        <meta property="og:title" content="{{ $seo['title'] }}">
        <meta property="og:description" content="{{ $seo['description'] }}">
        <meta property="og:url" content="{{ $seo['canonical'] }}">
        <meta property="og:type" content="website">
        <meta property="og:site_name" content="Erthi | إرثي">
        <meta property="og:locale" content="{{ ($page['props']['locale'] ?? 'ar') === 'en' ? 'en_US' : 'ar_EG' }}">
        <meta property="og:image" content="{{ $seo['image'] }}">
        <meta property="og:image:width" content="1200">
        <meta property="og:image:height" content="630">
        <meta name="twitter:card" content="summary_large_image">
    @endisset
    <title inertia>{{ $page['props']['seo']['title'] ?? 'Erthi | إرثي' }}</title>
    @viteReactRefresh
    @vite('resources/js/app.tsx')
    @inertiaHead
</head>
<body>
    @inertia
</body>
</html>
