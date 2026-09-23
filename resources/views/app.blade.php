<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title inertia>{{ $meta['title'] ?? config('app.name', 'Laravel') }}</title>

        {{--
            Open Graph de la superficie pública (REQ-CAT-021 RB-10). Va en el
            HTML del servidor y no en un <Head> de React: el rastreador que
            compone la previsualización de WhatsApp no ejecuta JavaScript.
            Solo la llenan las páginas que lo piden con `withViewData('meta')`.
        --}}
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

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />

        @routes
        @viteReactRefresh
        @vite(['resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
