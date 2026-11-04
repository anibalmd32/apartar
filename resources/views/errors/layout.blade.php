<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex">
    <title>@yield('title') · Apartar</title>
    <link rel="preload" href="/fonts/archivo-latin.woff2" as="font" type="font/woff2" crossorigin>
    <style>
        @font-face { font-family: 'Archivo'; font-weight: 100 900; font-stretch: 62% 125%; font-display: swap; src: url('/fonts/archivo-latin.woff2') format('woff2'); }
        :root { color-scheme: light dark; --bg: #f4f5f2; --card: #fbfcfa; --ink: #15171b; --ink-2: #4a4f58; --ink-3: #5f646d; --line: #15171b; --primary: #1c45c4; --primary-hover: #15379f; --soft: #e6ebf9; --soft-ink: #15379f; --on-primary: #ffffff; }
        @media (prefers-color-scheme: dark) { :root { --bg: #111316; --card: #16181c; --ink: #f0f1ed; --ink-2: #b8bcc3; --ink-3: #9aa0a8; --line: #f0f1ed; --primary: #86a0ff; --primary-hover: #a5b8ff; --soft: #1d2440; --soft-ink: #a5b8ff; --on-primary: #0b0d12; } }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; min-height: 100dvh; display: flex; flex-direction: column; background: var(--bg); color: var(--ink); font-family: 'Archivo', system-ui, -apple-system, 'Segoe UI', Roboto, Arial, sans-serif; -webkit-font-smoothing: antialiased; }
        header { display: flex; align-items: center; height: 60px; padding: 0 16px; max-width: 1440px; width: 100%; margin: 0 auto; }
        .brand { display: inline-flex; align-items: center; gap: 10px; min-height: 44px; color: var(--primary); text-decoration: none; font-size: 30px; font-weight: 900; font-stretch: 72%; letter-spacing: -0.01em; }
        main { flex: 1; display: flex; align-items: flex-start; justify-content: center; padding: 12vh 16px 64px; }
        .card { width: 100%; max-width: 480px; display: flex; flex-direction: column; gap: 14px; }
        .code { align-self: flex-start; padding: 3px 10px; border-radius: 999px; background: var(--soft); color: var(--soft-ink); font-size: 12px; font-weight: 700; font-variant-numeric: tabular-nums; }
        h1 { margin: 0; font-size: 44px; line-height: 0.92; font-weight: 850; font-stretch: 68%; text-transform: uppercase; letter-spacing: -0.012em; text-wrap: balance; }
        p { margin: 0; color: var(--ink-2); font-size: 16px; line-height: 1.55; }
        .actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 10px; }
        .btn { display: inline-flex; align-items: center; justify-content: center; min-height: 48px; padding: 0 24px; border-radius: 6px; font-size: 16px; font-weight: 750; text-decoration: none; }
        .btn-primary { background: var(--primary); color: var(--on-primary); }
        .btn-primary:hover { background: var(--primary-hover); }
        .btn-ghost { color: var(--ink); box-shadow: inset 0 0 0 2px var(--line); background: transparent; }
        .btn:focus-visible { outline: 3px solid var(--primary); outline-offset: 3px; }
        @media (max-width: 639px) { .btn { flex: 1 1 100%; } }
        @media (min-width: 1024px) { header { height: 76px; padding: 0 80px; } main { align-items: center; padding-top: 24px; } h1 { font-size: 72px; } }
    </style>
</head>
<body>
    <header>
        <a class="brand" href="{{ url('/') }}" aria-label="Apartar, inicio">apartar</a>
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
