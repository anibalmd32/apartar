<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="apartar">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title inertia>{{ $meta['title'] ?? config('app.name') }}</title>

        <link rel="icon" href="/favicon.ico" sizes="48x48">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">
        <link rel="manifest" href="/site.webmanifest">
        <meta name="theme-color" content="#5046e5">

        @isset($meta)
            <meta name="description" content="{{ $meta['description'] }}">
            <meta property="og:type" content="website">
            <meta property="og:site_name" content="{{ $meta['siteName'] }}">
            <meta property="og:title" content="{{ $meta['title'] }}">
            <meta property="og:description" content="{{ $meta['description'] }}">
            <meta property="og:url" content="{{ $meta['url'] }}">
            @if ($meta['image'] !== null)
                <meta property="og:image" content="{{ $meta['image'] }}">
            @endif
            <meta name="twitter:card" content="{{ $meta['image'] !== null ? 'summary_large_image' : 'summary' }}">
            <link rel="canonical" href="{{ $meta['url'] }}">
        @endisset

        <script>
            (function () {
                var saved = localStorage.getItem('appearance') || 'system';
                var dark = saved === 'dark' || (saved === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
                document.documentElement.classList.toggle('dark', dark);
                document.documentElement.dataset.theme = dark ? 'apartar-dark' : 'apartar';
            })();
        </script>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=plus-jakarta-sans:400,500,600,700,800" rel="stylesheet" />

        @routes
        @viteReactRefresh
        @vite(['resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
