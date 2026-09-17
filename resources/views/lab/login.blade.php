<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Acceso interno</title>
<style>
  *{box-sizing:border-box} body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#0b1220;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;color:#e2e8f0}
  .card{width:min(380px,92vw);background:#111c33;border:1px solid #26355a;border-radius:16px;padding:28px;box-shadow:0 20px 60px rgba(0,0,0,.5)}
  h1{font-size:18px;margin:0 0 4px} p.sub{font-size:12px;color:#8ea0c2;margin:0 0 18px}
  label{display:block;font-size:12px;color:#8ea0c2;margin:12px 0 4px}
  input{width:100%;padding:10px 12px;border-radius:10px;border:1px solid #2b3d68;background:#0c1528;color:#e2e8f0;font-size:14px}
  input:focus{outline:none;border-color:#38bdf8}
  button{width:100%;margin-top:18px;padding:11px;border:0;border-radius:10px;background:linear-gradient(90deg,#0284c7,#4f46e5);color:#fff;font-weight:800;font-size:14px;cursor:pointer}
  .err{background:#3b0d0d;border:1px solid #7f1d1d;color:#fecaca;font-size:13px;border-radius:10px;padding:10px 12px;margin-bottom:12px}
  .lock{font-size:28px}
</style>
</head>
<body>
  <form class="card" method="POST" action="/{{ $base }}/login">
    @csrf
    <div class="lock">🔒</div>
    <h1>Acceso interno</h1>
    <p class="sub">Zona de diagnóstico · solo administradores</p>
    @if ($errors->any())
      <div class="err">{{ $errors->first() }}</div>
    @endif
    <label for="email">Correo del administrador</label>
    <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username">
    <label for="password">Contraseña</label>
    <input id="password" type="password" name="password" required autocomplete="current-password">
    <button type="submit">Entrar</button>
  </form>
</body>
</html>
