<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex">
    <title>@yield('title') · Apartar</title>
    <link rel="preload" href="/fonts/plus-jakarta-sans-latin.woff2" as="font" type="font/woff2" crossorigin>
    <style>
        @font-face { font-family: 'Plus Jakarta Sans'; font-weight: 200 800; font-display: swap; src: url('/fonts/plus-jakarta-sans-latin.woff2') format('woff2'); }
        :root { color-scheme: light dark; --bg: #f5f6fa; --card: #ffffff; --ink: #151a2d; --ink-2: #4a5268; --ink-3: #6b7287; --line: #e3e5ee; --primary: #5046e5; --primary-hover: #4338ca; --soft: #eeedfd; --soft-ink: #4338ca; }
        @media (prefers-color-scheme: dark) { :root { --bg: #0c0f17; --card: #151a26; --ink: #edeff5; --ink-2: #b6bccb; --ink-3: #8a91a3; --line: #262c3b; --soft: #1f1d4a; --soft-ink: #b9b4ff; } }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; min-height: 100dvh; display: flex; flex-direction: column; background: var(--bg); color: var(--ink); font-family: 'Plus Jakarta Sans', system-ui, -apple-system, 'Segoe UI', Roboto, Arial, sans-serif; -webkit-font-smoothing: antialiased; }
        header { display: flex; align-items: center; height: 60px; padding: 0 16px; max-width: 1440px; width: 100%; margin: 0 auto; }
        .brand { display: inline-flex; align-items: center; gap: 10px; min-height: 44px; color: inherit; text-decoration: none; font-size: 18px; font-weight: 800; letter-spacing: -0.6px; }
        .mark { display: grid; place-items: center; width: 32px; height: 32px; border-radius: 9px; background: var(--primary); color: #fff; font-size: 18px; font-weight: 800; }
        main { flex: 1; display: flex; align-items: flex-start; justify-content: center; padding: 12vh 16px 64px; }
        .card { width: 100%; max-width: 480px; display: flex; flex-direction: column; gap: 14px; }
        .code { align-self: flex-start; padding: 3px 10px; border-radius: 999px; background: var(--soft); color: var(--soft-ink); font-size: 12px; font-weight: 700; font-variant-numeric: tabular-nums; }
        h1 { margin: 0; font-size: 30px; line-height: 1.15; font-weight: 800; letter-spacing: -0.02em; text-wrap: balance; }
        p { margin: 0; color: var(--ink-2); font-size: 16px; line-height: 1.55; }
        .actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 10px; }
        .btn { display: inline-flex; align-items: center; justify-content: center; min-height: 48px; padding: 0 24px; border-radius: 999px; font-size: 15px; font-weight: 700; text-decoration: none; }
        .btn-primary { background: var(--primary); color: #fff; }
        .btn-primary:hover { background: var(--primary-hover); }
        .btn-ghost { color: var(--ink); border: 1px solid var(--line); background: var(--card); }
        .btn:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; }
        @media (max-width: 639px) { .btn { flex: 1 1 100%; } }
        @media (min-width: 1024px) { header { height: 76px; padding: 0 80px; } main { align-items: center; padding-top: 24px; } h1 { font-size: 40px; } }
    </style>
</head>
<body>
    <header>
        <a class="brand" href="{{ url('/') }}" aria-label="Apartar, inicio"><span class="mark" aria-hidden="true">a</span>apartar</a>
    </header>
    <main>
        <div class="card">
            <span class="code">Error @yield('code')</span>
            <h1>@yield('heading')</h1>
            <p>@yield('message')</p>
            <div class="actions">
                @hasSection('action')
                    @yield('action')
                @endif
                <a class="btn @hasSection('action') btn-ghost @else btn-primary @endif" href="{{ url('/') }}">Ir al inicio</a>
            </div>
        </div>
    </main>
</body>
</html>
