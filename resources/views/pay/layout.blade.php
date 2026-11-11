<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('title')</title>
    <style>
        :root { --fg:#1f2937; --muted:#6b7280; --line:#e5e7eb; --accent:#059669; --bg:#f9fafb; --error:#b91c1c; }
        * { box-sizing: border-box; }
        body { margin:0; font: 16px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; color:var(--fg); background:var(--bg); }
        main { max-width: 32rem; margin: 2rem auto; padding: 0 1rem; }
        .card { background:#fff; border:1px solid var(--line); border-radius:.75rem; padding:1.25rem; margin-bottom:1rem; }
        h1 { font-size:1.25rem; margin:0 0 .25rem; }
        .muted { color:var(--muted); font-size:.9rem; }
        .amount { font-size:1.75rem; font-weight:700; margin:.5rem 0; }
        fieldset { border:0; padding:0; margin:0; }
        label.option { display:flex; gap:.75rem; align-items:center; border:1px solid var(--line); border-radius:.5rem; padding:.75rem; margin:.5rem 0; cursor:pointer; }
        input[type=email], input[type=tel] { width:100%; padding:.6rem; border:1px solid var(--line); border-radius:.5rem; font-size:1rem; }
        button { width:100%; padding:.8rem; border:0; border-radius:.5rem; background:var(--accent); color:#fff; font-size:1rem; font-weight:600; cursor:pointer; }
        .error { color:var(--error); margin:.5rem 0; }
        .instructions { white-space:pre-line; background:var(--bg); border-radius:.5rem; padding:.75rem; }
        .status-confirmed { color:var(--accent); }
        .status-failed { color:var(--error); }
    </style>
</head>
<body>
<main>
    @yield('content')
</main>
</body>
</html>
