<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title></title>
    <link rel="icon" href="data:,">
    <style>
        html, body { margin: 0; height: 100%; background: #0b0d14; }
        body { display: grid; place-items: center; }
        input { box-sizing: border-box; width: min(320px, 80vw); padding: 12px 14px; border: 1px solid #2a2e42; border-radius: 10px; background: #0f1220; color: #e6e8ef; font-size: 16px; }
        input:focus { outline: none; border-color: #4a5070; }
        input.e { border-color: #b91c1c; }
    </style>
</head>
<body>
    <form method="POST">
        <input name="{{ \App\Support\MonitorAccess::FIELD }}" type="password" autocomplete="current-password" autofocus required @class(['e' => $retry])>
    </form>
</body>
</html>
