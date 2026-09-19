<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Lab · Consola de diagnóstico</title>
<style>
  *{box-sizing:border-box} body{margin:0;background:#0b1220;color:#e2e8f0;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;font-size:14px}
  header{position:sticky;top:0;z-index:10;display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between;padding:12px 18px;background:#0e1830f2;border-bottom:1px solid #22325a;backdrop-filter:blur(6px)}
  header h1{font-size:16px;margin:0} header h1 small{color:#7dd3fc;font-weight:400}
  .wrap{max-width:1100px;margin:0 auto;padding:18px}
  .grid{display:grid;gap:14px} @media(min-width:900px){.grid2{grid-template-columns:1fr 1fr}}
  .card{background:#111c33;border:1px solid #26355a;border-radius:14px;padding:16px}
  .card h2{font-size:14px;margin:0 0 4px;text-transform:uppercase;letter-spacing:.06em;color:#7dd3fc}
  .card p.desc{font-size:12px;color:#8ea0c2;margin:0 0 12px}
  button,.btn{padding:8px 14px;border-radius:9px;border:1px solid #2b3d68;background:#1a2a4f;color:#e2e8f0;font-weight:700;font-size:13px;cursor:pointer}
  button.primary{background:linear-gradient(90deg,#0284c7,#4f46e5);border:0;color:#fff}
  button.warn{background:#7c2d12;border-color:#9a3412;color:#fed7aa}
  button:disabled{opacity:.55;cursor:wait}
  input,select,textarea{padding:8px 10px;border-radius:9px;border:1px solid #2b3d68;background:#0c1528;color:#e2e8f0;font-size:13px}
  table{width:100%;border-collapse:collapse;font-size:13px} th,td{text-align:left;padding:8px;border-bottom:1px solid #1e2c52;vertical-align:top}
  th{color:#8ea0c2;font-size:11px;text-transform:uppercase;letter-spacing:.05em}
  .ok{color:#4ade80;font-weight:700} .fail{color:#f87171;font-weight:700}
  .pill{display:inline-block;padding:2px 8px;border-radius:99px;font-size:11px;font-weight:800}
  .pill.ok{background:#052e16;color:#4ade80} .pill.fail{background:#3b0d0d;color:#f87171} .pill.info{background:#0c2a4a;color:#7dd3fc}
  .hint{font-size:12px;color:#fbbf24;margin-top:4px}
  .detail{font-size:12px;color:#aebdd8;word-break:break-word}
  .trace{background:#0c1528;border:1px solid #22325a;border-radius:10px;padding:10px;font-family:ui-monospace,Consolas,monospace;font-size:12px;white-space:pre-wrap;max-height:220px;overflow:auto}
  .row{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
  .snap{display:flex;gap:14px;flex-wrap:wrap;font-size:12px;color:#8ea0c2}
  .snap b{color:#e2e8f0}
  form.logout{margin:0}
</style>
</head>
<body>
<header>
  <h1>🧪 Lab <small>· consola secreta de diagnóstico</small></h1>
  <div class="row">
    <span class="snap">👤 <b>{{ $user->name ?? '' }}</b> · 🕑 <b>{{ $snapshot['now'] ?? '' }}</b> ({{ $snapshot['time_source'] ?? '' }})</span>
    <form class="logout" method="POST" action="/{{ $base }}/logout">@csrf<button type="submit">Salir 🔒</button></form>
  </div>
</header>

<div class="wrap grid">
  <div class="card">
    <h2>🖥️ Entorno</h2>
    <div class="snap">
      <span>APP_URL <b>{{ $snapshot['app_url'] ?? '' }}</b></span>
      <span>TZ <b>{{ $snapshot['timezone'] ?? '' }}</b></span>
      <span>ENV <b>{{ $snapshot['env'] ?? '' }}</b></span>
      <span>DEBUG <b>{{ $snapshot['debug'] ?? '' }}</b></span>
      <span>DB <b>{{ $snapshot['db'] ?? '' }}</b></span>
      <span>Bot <b>{{ $snapshot['bot'] ?? '' }}</b></span>
      <span>Sesión lab <b>{{ $snapshot['session_minutes'] ?? '' }} min</b></span>
    </div>
  </div>

  <div class="card">
    <h2>✅ 1 · Chequeo general del sistema</h2>
    <p class="desc">Revisa Telegram, webhook, cron, permisos y base de datos. Si algo falla, muestra el motivo y cómo arreglarlo.</p>
    <div class="row"><button class="primary" id="btnChecks" onclick="runChecks()">Pasar revisión ahora</button><span id="checksSummary"></span></div>
    <div id="checks" style="margin-top:12px"></div>
  </div>

  <div class="card">
    <h2>⏰ 2 · Recordatorios · adelantar aviso (debug)</h2>
    <p class="desc">Envía un recordatorio a Telegram <b>AHORA aunque no sea la hora</b>. Ideal para verificar que el aviso llega bien antes de producción.</p>
    <div class="row">
      <button onclick="loadReminders()">Cargar recordatorios</button>
      <button class="warn" id="btnDispatch" onclick="dispatchNow()">⚡ Ejecutar dispatcher real (como el cron)</button>
      <span id="dispatchOut"></span>
    </div>
    <div style="margin-top:10px;overflow:auto"><table>
      <thead><tr><th>ID</th><th>Título / fechas</th><th>Estado</th><th>Destino</th><th></th></tr></thead>
      <tbody id="remRows"><tr><td colspan="5" class="detail">Pulsa “Cargar recordatorios”.</td></tr></tbody>
    </table></div>
    <div id="fireOut" style="margin-top:10px"></div>
  </div>

  <div class="grid grid2">
    <div class="card">
      <h2>📩 3 · Probar Telegram directo</h2>
      <p class="desc">Envía un mensaje de prueba a tu chat o a un chat_id manual (útil si un usuario dice “no me llega”).</p>
      <div class="row">
        <input id="tChat" placeholder="chat_id (vacío = mi chat)" style="flex:1;min-width:160px">
        <input id="tText" placeholder="Texto (opcional)" style="flex:2;min-width:200px">
        <button class="primary" onclick="tgTest()">Enviar 🧪</button>
      </div>
      <div id="tgOut" style="margin-top:8px"></div>
    </div>
    <div class="card">
      <h2>📜 4 · Ver errores recientes (log)</h2>
      <p class="desc">Últimas líneas de <code>storage/logs/laravel.log</code> sin necesidad de SSH. Para arreglos rápidos en producción.</p>
      <div class="row"><button onclick="loadLogs()">Ver log</button><span class="detail">Muestra 80 líneas (recorta líneas largas).</span></div>
      <div class="trace" id="logOut" style="margin-top:8px">—</div>
    </div>
  </div>

  <div class="card">
    <h2>👥 5 · Permisos y vinculación por usuario</h2>
    <p class="desc">Quién puede qué (recordatorios, administración) y quién tiene Telegram vinculado.</p>
    <div class="row"><button onclick="loadUsers()">Cargar usuarios</button></div>
    <div style="margin-top:10px;overflow:auto"><table>
      <thead><tr><th>Usuario</th><th>Roles</th><th>Puede</th><th>Telegram</th><th>Cuenta</th></tr></thead>
      <tbody id="userRows"><tr><td colspan="5" class="detail">—</td></tr></tbody>
    </table></div>
  </div>
</div>

<script>
const BASE = '/{{ $base }}';
const csrf = '{{ csrf_token() }}';
const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
async function post(url, body = {}) {
  const r = await fetch(BASE + url, {method:'POST', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':csrf,'Accept':'application/json'}, body:JSON.stringify(body)});
  const j = await r.json().catch(() => ({ok:false, error:'Respuesta no JSON (HTTP '+r.status+')'}));
  j._status = r.status; return j;
}
async function get(url) {
  const r = await fetch(BASE + url, {headers:{'Accept':'application/json'}});
  return r.json().catch(() => ({ok:false, error:'Respuesta no JSON (HTTP '+r.status+')'}));
}
async function runChecks() {
  const b = document.getElementById('btnChecks'); b.disabled = true; b.textContent = 'Revisando…';
  const j = await get('/api/checklist');
  b.disabled = false; b.textContent = 'Pasar revisión ahora';
  document.getElementById('checksSummary').innerHTML = `<span class="pill ok">${j.passed ?? 0} ok</span> <span class="pill fail">${j.failed ?? 0} fallos</span>`;
  document.getElementById('checks').innerHTML = (j.checks || []).map(c => `
    <div style="padding:8px 0;border-bottom:1px solid #1e2c52">
      <span class="${c.ok ? 'ok' : 'fail'}">${c.ok ? '✅' : '❌'}</span> <b>${esc(c.name)}</b><br>
      <span class="detail">${esc(c.detail)}</span>
      ${c.hint ? `<div class="hint">💡 ${esc(c.hint)}</div>` : ''}
    </div>`).join('') || `<span class="fail">${esc(j.error || 'Sin datos')}</span>`;
}
async function loadReminders() {
  const j = await get('/api/reminders');
  const tb = document.getElementById('remRows');
  if (!j.reminders) { tb.innerHTML = `<tr><td colspan="5" class="fail">${esc(j.error || 'Error')}</td></tr>`; return; }
  tb.innerHTML = j.reminders.map(r => {
    const st = r.status === 'done' ? '<span class="pill info">hecho</span>'
      : (r.due_now ? '<span class="pill fail">avisables ahora</span>' : '<span class="pill ok">programado</span>');
    const tg = r.telegram_sent ? ' · ✈️ tg enviado' : (r.notified_at ? ' · 🔔 web avisado' : '');
    const tgt = (r.targets || []).map(t => `${esc(t.name)}${t.linked ? ' ✅' : ' ⚠️sin chat'}`).join(', ') || '—';
    return `<tr><td>#${r.id}</td>
      <td><b>${esc(r.title)}</b><br><span class="detail">📅 ${esc(r.scheduled_at)} · 🔔 ${esc(r.notify_at)}</span></td>
      <td>${st}<br><span class="detail">${esc(tg)}</span></td>
      <td class="detail">${tgt}</td>
      <td><button class="primary" onclick="fire(${r.id},this)">Adelantar 🧪</button></td></tr>`;
  }).join('') || '<tr><td colspan="5">Sin recordatorios.</td></tr>';
}
async function fire(id, btn) {
  btn.disabled = true; const old = btn.textContent; btn.textContent = 'Enviando…';
  const j = await post('/api/reminders/' + id + '/fire', {platform:true});
  btn.disabled = false; btn.textContent = old;
  const rows = (j.targets || []).map(t => `<tr><td>${esc(t.name)}</td><td>${t.sent ? '<span class="ok">✅ enviado</span>' : '<span class="fail">❌ falló</span>'}</td><td class="detail">${esc(t.chat_id || '—')}<br>${esc(t.error || '')}</td></tr>`).join('');
  document.getElementById('fireOut').innerHTML = `
    <div class="card" style="background:#0c1528">
      <b>#${id} ${j.ok ? '<span class="ok">✅ OK</span>' : '<span class="fail">❌ CON FALLOS</span>'}</b>
      ${j.hint ? `<div class="hint">💡 ${esc(j.hint)}</div>` : ''}
      ${j.error ? `<div class="fail">${esc(j.error)}</div>` : ''}
      ${rows ? `<table style="margin-top:8px"><thead><tr><th>Destino</th><th>Telegram</th><th>Detalle / error</th></tr></thead><tbody>${rows}</tbody></table>` : ''}
      ${j.platform ? `<div class="detail" style="margin-top:6px">Plataforma: ${j.platform.sent ? 'avisos creados: '+j.platform.sent : esc(j.platform.skipped || j.platform.error || '—')}</div>` : ''}
    </div>`;
  loadReminders();
}
async function dispatchNow() {
  const b = document.getElementById('btnDispatch'); b.disabled = true;
  document.getElementById('dispatchOut').textContent = 'Ejecutando…';
  const j = await post('/api/dispatch-now', {});
  b.disabled = false;
  document.getElementById('dispatchOut').innerHTML = j.ok ? `<span class="pill ok">despachados: ${j.sent}</span> <span class="detail">${esc(j.hint || '')}</span>` : `<span class="fail">${esc(j.error || 'Error')}</span>`;
}
async function tgTest() {
  const j = await post('/api/telegram-test', {chat_id: document.getElementById('tChat').value, text: document.getElementById('tText').value});
  document.getElementById('tgOut').innerHTML = j.ok ? `<span class="ok">✅ Enviado al chat ${esc(j.chat_id)}</span>` : `<span class="fail">❌ ${esc(j.error || 'Falló')}</span>`;
}
async function loadUsers() {
  const j = await get('/api/users');
  const tb = document.getElementById('userRows');
  if (!j.users) { tb.innerHTML = `<tr><td colspan="5" class="fail">${esc(j.error || 'Error')}</td></tr>`; return; }
  tb.innerHTML = j.users.map(u => `<tr>
    <td><b>${esc(u.name)}</b><br><span class="detail">${esc(u.email)}</span></td>
    <td class="detail">${(u.roles || []).map(esc).join(', ') || '—'}</td>
    <td>${u.can_admin ? '<span class="pill fail">admin</span> ' : ''}${u.can_manage_reminders ? '<span class="pill ok">recordatorios</span>' : '<span class="pill info">solo ver</span>'}</td>
    <td>${u.telegram_linked ? '<span class="ok">✅ vinculado</span>' : '<span class="fail">⚠️ sin vincular</span>'}</td>
    <td class="detail">${u.verified ? 'verificado' : '⚠️ sin verificar'}</td></tr>`).join('');
}
async function loadLogs() {
  document.getElementById('logOut').textContent = 'Cargando…';
  const j = await get('/api/logs?lines=80');
  document.getElementById('logOut').textContent = (j.lines || []).join('\n') || (j.hint || 'Sin líneas.');
}
runChecks();
</script>
</body>
</html>
