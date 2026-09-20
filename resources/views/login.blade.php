<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Sign in — ZKTeco Attendance</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
    :root {
        --bg:#0f172a; --card:#1e293b; --border:#334155; --text:#e2e8f0;
        --muted:#94a3b8; --accent:#38bdf8; --err:#ef4444;
    }
    * { box-sizing: border-box; }
    body {
        margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center;
        font-family: ui-sans-serif, system-ui, sans-serif; background:var(--bg); color:var(--text);
    }
    .card {
        width:100%; max-width:360px; background:var(--card); border:1px solid var(--border);
        border-radius:.75rem; padding:2rem; margin:1rem;
    }
    h1 { margin:0 0 1.5rem; font-size:1.15rem; font-weight:600; text-align:center; }
    label { display:block; font-size:.8rem; color:var(--muted); margin-bottom:.35rem; }
    .field { margin-bottom:1rem; }
    input[type="email"], input[type="password"] {
        width:100%; background:#0f172a; color:var(--text); border:1px solid var(--border);
        padding:.6rem .75rem; border-radius:.5rem; font-size:.9rem;
    }
    input[type="email"]:focus, input[type="password"]:focus {
        outline:none; border-color:var(--accent);
    }
    .remember { display:flex; align-items:center; gap:.5rem; font-size:.85rem; color:var(--muted); margin-bottom:1.25rem; }
    .remember input { margin:0; }
    button {
        width:100%; background:var(--accent); color:#0f172a; border:none; font-weight:600;
        padding:.65rem; border-radius:.5rem; font-size:.9rem; cursor:pointer;
    }
    button:hover { filter:brightness(1.05); }
    .errors {
        background:rgba(239,68,68,.15); border:1px solid rgba(239,68,68,.4); color:#fca5a5;
        border-radius:.5rem; padding:.6rem .75rem; font-size:.8rem; margin-bottom:1rem;
    }
    .status {
        background:rgba(34,197,94,.15); border:1px solid rgba(34,197,94,.4); color:#86efac;
        border-radius:.5rem; padding:.6rem .75rem; font-size:.8rem; margin-bottom:1rem;
    }
</style>
</head>
<body>
<div class="card">
    <h1>ZKTeco Attendance</h1>

    @if (session('status'))
        <div class="status">{{ session('status') }}</div>
    @endif

    @if ($errors->any())
        <div class="errors">
            @foreach ($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('login.attempt') }}">
        @csrf
        <div class="field">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username">
        </div>
        <div class="field">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" required autocomplete="current-password">
        </div>
        <label class="remember">
            <input type="checkbox" name="remember">
            Remember me
        </label>
        <button type="submit">Sign in</button>
    </form>
</div>
</body>
</html>
