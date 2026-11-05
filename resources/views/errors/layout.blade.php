<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex">
    <title>@yield('title') · Apartar</title>
    <link rel="preload" href="/fonts/archivo-latin.woff2" as="font" type="font/woff2" crossorigin>
    <script>
        (function () {
            var saved = 'system';
            try { saved = localStorage.getItem('appearance') || 'system'; } catch (error) {}
            var dark = saved === 'dark' || (saved === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
            document.documentElement.dataset.theme = dark ? 'apartar-dark' : 'apartar';
        })();
    </script>
    <style>
        @font-face { font-family: 'Archivo'; font-weight: 100 900; font-stretch: 62% 125%; font-display: swap; src: url('/fonts/archivo-latin.woff2') format('woff2'); }
        :root { color-scheme: light; --bg: #f4f5f2; --card: #fbfcfa; --ink: #15171b; --ink-2: #4a4f58; --ink-3: #5f646d; --line: #15171b; --primary: #1c45c4; --primary-hover: #15379f; --soft: #e6ebf9; --soft-ink: #15379f; --on-primary: #ffffff; }
        [data-theme='apartar-dark'] { color-scheme: dark; --bg: #0c0e11; --card: #15181d; --ink: #f2f3ef; --ink-2: #bdc1c8; --ink-3: #9da3ab; --line: #f2f3ef; --primary: #47a8ff; --primary-hover: #74beff; --soft: #0f2740; --soft-ink: #8cc8ff; --on-primary: #06121f; }
        header { justify-content: space-between; }
        .theme { display: inline-flex; align-items: center; justify-content: center; width: 44px; height: 44px; border: 0; border-radius: 6px; background: transparent; color: var(--ink); cursor: pointer; font: inherit; font-size: 20px; }
        .theme:hover { background: var(--soft); }
        .theme:focus-visible { outline: 3px solid var(--primary); outline-offset: 3px; }
        .theme svg { width: 18px; height: 18px; fill: currentColor; }
        .theme .sun { display: none; }
        @media (prefers-color-scheme: dark) { :root:not([data-theme]) { color-scheme: dark; --bg: #0c0e11; --card: #15181d; --ink: #f2f3ef; --ink-2: #bdc1c8; --ink-3: #9da3ab; --line: #f2f3ef; --primary: #47a8ff; --primary-hover: #74beff; --soft: #0f2740; --soft-ink: #8cc8ff; --on-primary: #06121f; } :root:not([data-theme]) .theme .sun { display: block; } :root:not([data-theme]) .theme .moon { display: none; } }
        [data-theme='apartar-dark'] .theme .sun { display: block; }
        [data-theme='apartar-dark'] .theme .moon { display: none; }
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
        <button class="theme" type="button" aria-label="Cambiar entre modo claro y oscuro" onclick="var d=document.documentElement;var dark=d.dataset.theme!=='apartar-dark';d.dataset.theme=dark?'apartar-dark':'apartar';var sys=window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches;try{localStorage.setItem('appearance',dark===sys?'system':(dark?'dark':'light'));}catch(e){}">
            <svg class="moon" viewBox="0 0 512 512" aria-hidden="true"><path d="M268.279 496c-67.574 0-130.978-26.191-178.534-73.745S16 311.293 16 243.718A252.25 252.25 0 0 1 154.183 18.676a24.44 24.44 0 0 1 34.46 28.958 220.12 220.12 0 0 0 54.8 220.923A218.75 218.75 0 0 0 399.085 333.2a220.2 220.2 0 0 0 65.277-9.846 24.439 24.439 0 0 1 28.959 34.461A252.26 252.26 0 0 1 268.279 496M153.31 55.781A219.3 219.3 0 0 0 48 243.718C48 365.181 146.816 464 268.279 464a219.3 219.3 0 0 0 187.938-105.31 253 253 0 0 1-57.13 6.513 250.54 250.54 0 0 1-178.268-74.016 252.15 252.15 0 0 1-67.509-235.4Z"/></svg>
            <svg class="sun" viewBox="0 0 512 512" aria-hidden="true"><path d="M256 104c-83.813 0-152 68.187-152 152s68.187 152 152 152 152-68.187 152-152-68.187-152-152-152m0 272a120 120 0 1 1 120-120 120.136 120.136 0 0 1-120 120M240 16h32v48h-32zm0 432h32v48h-32zm208-208h48v32h-48zm-432 0h48v32H16zm372.687 171.314 22.627-22.627 32 32-22.627 22.627zm-320-320 22.628-22.628 32 32-22.628 22.628zm-.002 329.375 32-32 22.628 22.626-32 32zm320.002-320.003 32-32 22.628 22.628-32 32z"/></svg>
        </button>
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
