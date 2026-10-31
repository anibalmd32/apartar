<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Monitor · Apartar</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #0b0d14; color: #e6e8ef; font-family: system-ui, -apple-system, "Segoe UI", sans-serif; }
        main { width: min(380px, 92vw); background: #151826; border: 1px solid #262a3d; border-radius: 16px; padding: 28px; }
        h1 { margin: 0 0 4px; font-size: 20px; }
        p { margin: 0 0 20px; color: #9aa0b5; font-size: 14px; }
        label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px; }
        input { width: 100%; padding: 11px 12px; border-radius: 10px; border: 1px solid #2f3450; background: #0f1220; color: inherit; font-size: 14px; }
        input:focus { outline: 2px solid #6d5ef5; border-color: transparent; }
        button { margin-top: 16px; width: 100%; padding: 11px; border: 0; border-radius: 10px; background: #6d5ef5; color: #fff; font-weight: 700; font-size: 14px; cursor: pointer; }
        .error { color: #f87171; font-size: 13px; margin-top: 8px; }
    </style>
</head>
<body>
    <main>
        <h1>Monitor de Apartar</h1>
        <p>Rendimiento, errores, trazas y logs de la plataforma.</p>

        <form method="POST" action="{{ route('monitor.access.store') }}">
            @csrf
            <label for="token">Token de acceso</label>
            <input id="token" name="token" type="password" autocomplete="current-password" autofocus required>
            @error('token')
                <div class="error" role="alert">{{ $message }}</div>
            @enderror
            <button type="submit">Entrar</button>
        </form>
    </main>
</body>
</html>
