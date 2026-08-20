<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Offline — {{ config('app.name', 'SecondLine') }}</title>
    <style>
        body { font-family: ui-sans-serif, system-ui, sans-serif; background: #1A365D; color: #fff;
               display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
        .card { text-align: center; padding: 2rem; max-width: 22rem; }
        .badge { width: 56px; height: 56px; border-radius: 14px; background: #C9A227; color: #1A365D;
                 font-weight: 800; font-size: 22px; display: flex; align-items: center; justify-content: center; margin: 0 auto 1rem; }
        h1 { font-size: 1.15rem; margin: 0 0 .5rem; }
        p { font-size: .9rem; color: rgba(255,255,255,.7); margin: 0 0 1.25rem; }
        button { background: #C9A227; color: #1A365D; border: 0; border-radius: 8px;
                 padding: .6rem 1.4rem; font-weight: 600; font-size: .9rem; cursor: pointer; }
    </style>
</head>
<body>
    <div class="card">
        <div class="badge">SL</div>
        <h1>You're offline</h1>
        <p>SecondLine can't reach the server. Your work is safe — reconnect and try again.</p>
        <button onclick="window.location.reload()">Retry</button>
    </div>
</body>
</html>
