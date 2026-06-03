<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>ZKTeco Live Attendance</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
    :root {
        --bg:#0f172a; --card:#1e293b; --border:#334155; --text:#e2e8f0;
        --muted:#94a3b8; --accent:#38bdf8; --ok:#22c55e; --warn:#facc15; --err:#ef4444;
    }
    * { box-sizing: border-box; }
    body { margin:0; font-family: ui-sans-serif, system-ui, sans-serif; background:var(--bg); color:var(--text); }
    header {
        padding:1rem 1.5rem; border-bottom:1px solid var(--border);
        display:flex; align-items:center; gap:1rem; flex-wrap:wrap;
    }
    h1 { margin:0; font-size:1.1rem; font-weight:600; }
    .status { font-size:.8rem; padding:.25rem .6rem; border-radius:999px; background:#334155; color:var(--muted); }
    .status.live    { background:rgba(34,197,94,.2); color:var(--ok); }
    .status.error   { background:rgba(239,68,68,.2); color:var(--err); }
    select, .pill {
        background:#0f172a; color:var(--text); border:1px solid var(--border);
        padding:.4rem .7rem; border-radius:.5rem; font-size:.85rem;
    }
    main { padding:1.5rem; }
    table { width:100%; border-collapse:collapse; background:var(--card); border-radius:.75rem; overflow:hidden; }
    th, td { padding:.75rem 1rem; text-align:left; border-bottom:1px solid var(--border); font-size:.9rem; }
    th { background:#0b1220; color:var(--muted); text-transform:uppercase; font-size:.7rem; letter-spacing:.05em; }
    tr:last-child td { border-bottom:none; }
    tr.fresh { animation: flash 2s ease-out; }
    @keyframes flash {
        0%   { background:rgba(250,204,21,.25); }
        100% { background:transparent; }
    }
    .empty { padding:2rem; text-align:center; color:var(--muted); }
    .mode-Fingerprint { color:var(--accent); }
    .mode-Face        { color:#a78bfa; }
    .mode-Card        { color:#fb923c; }
    .mode-Password    { color:var(--muted); }
    .state-Check\ In    { color:var(--ok); }
    .state-Check\ Out   { color:var(--warn); }
    .state-Break\ In, .state-Break\ Out { color:#60a5fa; }
</style>
</head>
<body>
<header>
    <h1>ZKTeco Live Attendance</h1>
    <span id="status" class="status">connecting...</span>
    <span class="pill">Device:</span>
    <select id="device-filter"><option value="">All devices</option></select>
    <span id="count" class="pill" style="margin-left:auto">0 punches</span>
</header>
<main>
    <table>
        <thead>
            <tr>
                <th>Device</th><th>User ID</th><th>Name</th>
                <th>Scanned at</th><th>Received</th><th>Mode</th><th>State</th>
            </tr>
        </thead>
        <tbody id="rows">
            <tr><td colspan="7" class="empty">Waiting for the first punch...</td></tr>
        </tbody>
    </table>
</main>

<script>
const RECENT_URL  = "{{ url('/attendance/recent') }}";
const DEVICES_URL = "{{ url('/attendance/devices') }}";
const POLL_MS = 2000;

const statusEl  = document.getElementById('status');
const rowsEl    = document.getElementById('rows');
const countEl   = document.getElementById('count');
const deviceSel = document.getElementById('device-filter');

// Integer ID cursor: exact, no timezone/precision issues.
// 0 = no data yet (triggers initial load of last 50 records).
let lastId        = 0;
let total         = 0;
let currentDevice = '';
let inFlight      = false;   // prevents overlapping requests causing duplicates
let failures      = 0;

function setStatus(text, cls) {
    statusEl.textContent = text;
    statusEl.className = 'status ' + (cls || '');
}

function fmtTime(s) {
    if (!s) return '';
    return String(s).replace('T', ' ').replace(/\.\d+Z?$/, '');
}

function renderRow(ev, isNew) {
    const tr = document.createElement('tr');
    if (isNew) tr.className = 'fresh';
    tr.innerHTML = `
        <td>${ev.device_name ?? ev.device_id ?? ''}</td>
        <td><strong>${ev.user_id}</strong></td>
        <td>${ev.user_name ?? '<span style="color:#64748b">—</span>'}</td>
        <td>${fmtTime(ev.device_time)}</td>
        <td>${fmtTime(ev.received_at)}</td>
        <td class="mode-${(ev.mode||'').replace(/ /g,'\\ ')}">${ev.mode}</td>
        <td class="state-${(ev.state||'').replace(/ /g,'\\ ')}">${ev.state}</td>
    `;
    return tr;
}

async function loadDevices() {
    try {
        const r = await fetch(DEVICES_URL);
        const list = await r.json();
        for (const d of list) {
            const opt = document.createElement('option');
            opt.value = d.device_id;
            opt.textContent = d.name;
            deviceSel.appendChild(opt);
        }
    } catch (e) { /* ignore */ }
}

async function poll() {
    // Skip this tick if a request is already in-flight.
    // Without this guard, slow responses cause the same rows to be
    // fetched twice with the same cursor and rendered as duplicates.
    if (inFlight) return;
    inFlight = true;

    try {
        const url = new URL(RECENT_URL, window.location.origin);
        if (currentDevice) url.searchParams.set('device', currentDevice);

        if (lastId === 0) {
            // Initial load: get the 50 most recent records.
            url.searchParams.set('n', '50');
        } else {
            // Incremental: only records with id > lastId.
            // n=200 handles bursts (e.g. device reconnect syncing many punches).
            url.searchParams.set('since_id', lastId);
            url.searchParams.set('n', '200');
        }

        const r = await fetch(url);
        if (!r.ok) throw new Error('HTTP ' + r.status);
        const events = await r.json();   // always newest-first (ORDER BY id DESC)
        failures = 0;
        setStatus('live', 'live');

        if (events.length) {
            const isInitial = (lastId === 0);

            // Advance the cursor to the highest id in this batch.
            // events[0] is the newest so it has the max id.
            for (const ev of events) {
                if (ev.id > lastId) lastId = ev.id;
            }

            // Build a fragment in API order (newest-first) so that
            // prepend/append puts the newest row at the very top.
            const frag = document.createDocumentFragment();
            for (const ev of events) {
                frag.appendChild(renderRow(ev, !isInitial));
            }

            if (isInitial) {
                rowsEl.innerHTML = '';
                rowsEl.appendChild(frag);   // newest row lands at top
            } else {
                rowsEl.prepend(frag);        // new punches prepended, newest at top
            }

            total += events.length;
            countEl.textContent = total + ' punches';

            // Cap DOM to 200 rows to avoid memory growth over 24h.
            while (rowsEl.children.length > 200) rowsEl.removeChild(rowsEl.lastChild);
        }
    } catch (e) {
        failures++;
        setStatus('reconnecting...', failures > 3 ? 'error' : '');
    } finally {
        inFlight = false;
    }
}

deviceSel.addEventListener('change', () => {
    currentDevice = deviceSel.value;
    lastId = 0; total = 0;
    rowsEl.innerHTML = '<tr><td colspan="7" class="empty">Loading...</td></tr>';
    poll();
});

loadDevices().then(() => { poll(); setInterval(poll, POLL_MS); });
</script>
</body>
</html>
