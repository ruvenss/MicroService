<?php

/**
 * Development-only realtime debug dashboard (rendered by App\Controllers\Debug\MsDebug).
 *
 * Self-contained: inline CSS + JS, no external assets, no build step. The initial
 * snapshot is server-rendered into `window.__MS_DEBUG__` so the first paint is
 * populated; the script then re-polls /ms_debug/data every `refreshMs` and re-renders.
 *
 * @var int                   $refreshMs
 * @var array<string, mixed>  $initial
 */
// JSON_HEX_* so a value containing "</script>" or a quote can't break out of the
// inline <script>. This surface is dev-only, but the habit stays defensive.
$bootstrap = json_encode($initial, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="robots" content="noindex,nofollow">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ms_debug · MicroService</title>
<style>
  :root {
    --bg:#0d1117; --panel:#161b22; --border:#30363d; --fg:#c9d1d9; --muted:#8b949e;
    --accent:#58a6ff; --ok:#3fb950; --warn:#d29922; --down:#f85149; --mono:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;
  }
  * { box-sizing:border-box; }
  body { margin:0; background:var(--bg); color:var(--fg); font:14px/1.5 system-ui,-apple-system,Segoe UI,Roboto,sans-serif; }
  header { display:flex; align-items:center; gap:16px; padding:12px 20px; border-bottom:1px solid var(--border); background:var(--panel); position:sticky; top:0; z-index:1; }
  header h1 { font-size:15px; margin:0; font-family:var(--mono); letter-spacing:.5px; }
  header h1 .slash { color:var(--muted); }
  .pill { font-family:var(--mono); font-size:12px; padding:2px 8px; border-radius:10px; border:1px solid var(--border); color:var(--muted); }
  .pill.env { color:var(--accent); border-color:var(--accent); }
  .live { display:flex; align-items:center; gap:6px; margin-left:auto; color:var(--muted); font-size:12px; }
  .dot { width:9px; height:9px; border-radius:50%; background:var(--muted); transition:background .2s; }
  .dot.on { background:var(--ok); box-shadow:0 0 6px var(--ok); }
  .dot.err { background:var(--down); box-shadow:0 0 6px var(--down); }
  main { display:grid; grid-template-columns:repeat(auto-fill,minmax(360px,1fr)); gap:16px; padding:20px; align-items:start; }
  .card { background:var(--panel); border:1px solid var(--border); border-radius:8px; overflow:hidden; }
  .card.wide { grid-column:1/-1; }
  .card h2 { font-size:12px; text-transform:uppercase; letter-spacing:.8px; color:var(--muted); margin:0; padding:10px 14px; border-bottom:1px solid var(--border); }
  .card .body { padding:6px 14px 12px; }
  table { width:100%; border-collapse:collapse; font-family:var(--mono); font-size:12.5px; }
  th, td { text-align:left; padding:5px 8px; border-bottom:1px solid var(--border); white-space:nowrap; }
  th { color:var(--muted); font-weight:600; }
  tr:last-child td { border-bottom:0; }
  td.wrap, th.wrap { white-space:normal; word-break:break-all; }
  .kv { display:grid; grid-template-columns:auto 1fr; gap:2px 14px; font-family:var(--mono); font-size:12.5px; padding:8px 0; }
  .kv dt { color:var(--muted); }
  .kv dd { margin:0; text-align:right; }
  .tag { font-family:var(--mono); font-size:11px; padding:1px 6px; border-radius:4px; border:1px solid var(--border); color:var(--muted); }
  .up { color:var(--ok); } .down { color:var(--down); } .warn { color:var(--warn); }
  .status-2 { color:var(--ok); } .status-4 { color:var(--warn); } .status-5 { color:var(--down); }
  .muted { color:var(--muted); }
  .empty { color:var(--muted); font-style:italic; padding:8px 0; }
  code { font-family:var(--mono); }
  .scroll { max-height:340px; overflow:auto; }
</style>
</head>
<body>
<header>
  <h1>ms<span class="slash">_</span>debug</h1>
  <span class="pill env" id="env">—</span>
  <span class="pill" id="clock">—</span>
  <div class="live"><span class="dot" id="dot"></span><span id="livetxt">connecting…</span></div>
</header>
<main id="grid"></main>

<script>
window.__MS_DEBUG__ = <?= $bootstrap ?>;
const REFRESH_MS = <?= (int) $refreshMs ?>;
const DATA_URL = location.pathname.replace(/\/$/, '') + '/data';

const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
const yn = b => b ? '<span class="up">yes</span>' : '<span class="muted">no</span>';
const upDown = s => s === 'up' ? '<span class="up">up</span>' : '<span class="down">down</span>';

function kv(pairs) {
  return '<dl class="kv">' + pairs.map(([k, v]) => `<dt>${esc(k)}</dt><dd>${v}</dd>`).join('') + '</dl>';
}
function panel(id, title, inner, wide) {
  return `<section class="card${wide ? ' wide' : ''}"><h2>${esc(title)}</h2><div class="body">${inner}</div></section>`;
}
function errBody(p) { return `<div class="empty">unavailable: ${esc(p.error)}</div>`; }

function renderRuntime(r) {
  if (r.error) return errBody(r);
  return kv([
    ['PHP', esc(r.php_version) + (r.zend_threads ? ' <span class="tag">ZTS</span>' : '')],
    ['CodeIgniter', esc(r.ci_version)],
    ['OPcache', yn(r.opcache_enabled)],
    ['JIT', yn(r.jit_enabled)],
    ['Memory', `${esc(r.memory_used_mb)} MB <span class="muted">/ peak ${esc(r.memory_peak_mb)} MB / limit ${esc(r.memory_limit)}</span>`],
  ]);
}
function renderHealth(h) {
  if (h.error) return errBody(h);
  return kv([
    ['Database', upDown(h.database)],
    ['Cache', upDown(h.cache)],
    ['Cache handler', `<code>${esc(h.cache_handler)}</code>`],
    ['Redis configured', yn(h.redis_configured)],
    ['File fallback', h.file_fallback ? '<span class="warn">active (Redis down)</span>' : '<span class="muted">no</span>'],
  ]);
}
function renderRequests(rows) {
  if (rows.error) return errBody(rows);
  if (!rows.length) return '<div class="empty">no requests logged yet</div>';
  const body = rows.map(r => {
    const cls = 'status-' + String(r.status || '').charAt(0);
    return `<tr><td class="${cls}">${esc(r.status)}</td><td>${esc(r.method)}</td>`
      + `<td class="wrap">${esc(r.path)}</td><td>${esc(r.latency_ms)}ms</td>`
      + `<td class="muted">${esc(r.created_at)}</td></tr>`;
  }).join('');
  return `<div class="scroll"><table><thead><tr><th>St</th><th>Verb</th><th class="wrap">Path</th><th>Lat</th><th>When</th></tr></thead><tbody>${body}</tbody></table></div>`;
}
function renderResources(rows) {
  if (rows.error) return errBody(rows);
  if (!rows.length) return '<div class="empty">no resources declared</div>';
  const body = rows.map(r => `<tr><td>${esc(r.slug)}</td><td class="muted">${esc(r.table)}</td>`
    + `<td class="muted">${esc(r.primaryKey)}</td><td class="wrap muted">${esc((r.filterable || []).join(', '))}</td></tr>`).join('');
  return `<table><thead><tr><th>Slug</th><th>Table</th><th>PK</th><th class="wrap">Filterable</th></tr></thead><tbody>${body}</tbody></table>`;
}
function renderRoutes(rows) {
  if (rows.error) return errBody(rows);
  if (!rows.length) return '<div class="empty">no routes</div>';
  const body = rows.map(r => `<tr><td>${esc(r.method)}</td><td class="wrap">${esc(r.from)}</td><td class="wrap muted">${esc(r.to)}</td></tr>`).join('');
  return `<div class="scroll"><table><thead><tr><th>Verb</th><th class="wrap">Pattern</th><th class="wrap">Handler</th></tr></thead><tbody>${body}</tbody></table></div>`;
}
function renderKeys(rows) {
  if (rows.error) return errBody(rows);
  if (!rows.length) return '<div class="empty">no API keys — mint one with <code>php spark key:create</code></div>';
  const body = rows.map(r => {
    const st = r.status === 'active' ? 'up' : 'down';
    return `<tr><td>${esc(r.prefix)}</td><td>${esc(r.name)}</td><td class="${st}">${esc(r.status)}</td>`
      + `<td class="wrap muted">${esc((r.scopes || []).join(', '))}</td>`
      + `<td class="muted">${esc(r.last_used_at || '—')}</td></tr>`;
  }).join('');
  return `<table><thead><tr><th>Prefix</th><th>Name</th><th>Status</th><th class="wrap">Scopes</th><th>Last used</th></tr></thead><tbody>${body}</tbody></table>`;
}
function renderWebhooks(counts) {
  if (counts.error) return errBody(counts);
  const keys = Object.keys(counts);
  if (!keys.length) return '<div class="empty">outbox empty</div>';
  return kv(keys.map(k => [k, `<span class="tag">${esc(counts[k])}</span>`]));
}
function renderIdempotency(d) {
  if (d.error) return errBody(d);
  return kv([['Stored claims', `<span class="tag">${esc(d.total)}</span>`]]);
}

function render(s) {
  document.getElementById('env').textContent = s.environment;
  document.getElementById('clock').textContent = s.time;
  document.getElementById('grid').innerHTML = [
    panel('runtime', 'Runtime', renderRuntime(s.runtime)),
    panel('health', 'Health', renderHealth(s.health)),
    panel('webhooks', 'Webhook outbox', renderWebhooks(s.webhooks)),
    panel('idempotency', 'Idempotency', renderIdempotency(s.idempotency)),
    panel('keys', 'API keys (metadata only)', renderKeys(s.keys), true),
    panel('requests', 'Recent requests', renderRequests(s.requests), true),
    panel('resources', 'Resources', renderResources(s.resources), true),
    panel('routes', 'Routes', renderRoutes(s.routes), true),
  ].join('');
}

function setLive(state) {
  const dot = document.getElementById('dot'), txt = document.getElementById('livetxt');
  dot.className = 'dot ' + (state === 'ok' ? 'on' : state === 'err' ? 'err' : '');
  txt.textContent = state === 'ok' ? `live · ${REFRESH_MS / 1000}s` : state === 'err' ? 'stream error — retrying' : 'connecting…';
}

async function poll() {
  try {
    const res = await fetch(DATA_URL, { headers: { 'Accept': 'application/json' }, cache: 'no-store' });
    if (!res.ok) throw new Error('HTTP ' + res.status);
    render(await res.json());
    setLive('ok');
  } catch (e) {
    setLive('err');
  }
}

render(window.__MS_DEBUG__);
setLive('ok');
setInterval(poll, REFRESH_MS);
</script>
</body>
</html>
