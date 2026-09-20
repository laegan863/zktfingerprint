<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>ZKTeco Live Attendance</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <style>
        :root {
            --bg: #0f172a;
            --card: #1e293b;
            --border: #334155;
            --text: #e2e8f0;
            --muted: #94a3b8;
            --accent: #38bdf8;
            --ok: #22c55e;
            --warn: #facc15;
            --err: #ef4444;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: ui-sans-serif, system-ui, sans-serif;
            background: var(--bg);
            color: var(--text);
        }

        header {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 1rem;
            flex-wrap: wrap;
        }

        h1 {
            margin: 0;
            font-size: 1.1rem;
            font-weight: 600;
        }

        .status {
            font-size: .8rem;
            padding: .25rem .6rem;
            border-radius: 999px;
            background: #334155;
            color: var(--muted);
        }

        .status.live {
            background: rgba(34, 197, 94, .2);
            color: var(--ok);
        }

        .status.error {
            background: rgba(239, 68, 68, .2);
            color: var(--err);
        }

        select,
        .pill {
            background: #0f172a;
            color: var(--text);
            border: 1px solid var(--border);
            padding: .4rem .7rem;
            border-radius: .5rem;
            font-size: .85rem;
        }

        .user-info {
            font-size: .85rem;
            color: var(--muted);
            margin-left: auto;
            display: flex;
            align-items: center;
            gap: .75rem;
        }

        .logout-btn {
            background: transparent;
            color: var(--muted);
            border: 1px solid var(--border);
            padding: .35rem .7rem;
            border-radius: .5rem;
            font-size: .8rem;
            cursor: pointer;
        }

        .logout-btn:hover {
            color: var(--text);
            border-color: var(--accent);
        }

        main {
            padding: 1.5rem;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: var(--card);
            border-radius: .75rem;
            overflow: hidden;
        }

        th,
        td {
            padding: .75rem 1rem;
            text-align: left;
            border-bottom: 1px solid var(--border);
            font-size: .9rem;
        }

        th {
            background: #0b1220;
            color: var(--muted);
            text-transform: uppercase;
            font-size: .7rem;
            letter-spacing: .05em;
        }

        tr:last-child td {
            border-bottom: none;
        }

        tr.fresh {
            animation: flash 2s ease-out;
        }

        @keyframes flash {
            0% {
                background: rgba(250, 204, 21, .25);
            }

            100% {
                background: transparent;
            }
        }

        .empty {
            padding: 2rem;
            text-align: center;
            color: var(--muted);
        }

        .mode-Fingerprint {
            color: var(--accent);
        }

        .mode-Face {
            color: #a78bfa;
        }

        .mode-Card {
            color: #fb923c;
        }

        .mode-Password {
            color: var(--muted);
        }

        .state-Check\ In {
            color: var(--ok);
        }

        .state-Check\ Out {
            color: var(--warn);
        }

        .state-Break\ In,
        .state-Break\ Out {
            color: #60a5fa;
        }
    </style>
</head>

<body>
    <header>
        <h1>ZKTeco Live Attendance</h1>
        <span id="status" class="status">connecting...</span>
        <span class="pill">Device:</span>
        <select id="device-filter">
            <option value="">All devices</option>
        </select>
        <span id="count" class="pill">0 punches</span>
        <span class="user-info">
            {{ auth()->user()->name }}
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="logout-btn">Logout</button>
            </form>
        </span>
    </header>
    <main>
        <table>
            <thead>
                <tr>
                    <th>Device</th>
                    <th>User ID</th>
                    <th>Name</th>
                    <th>Scanned at</th>
                    <th>Received</th>
                    <th>Mode</th>
                    <th>State</th>
                </tr>
            </thead>
            <tbody id="rows">
                <tr>
                    <td colspan="7" class="empty">Waiting for the first punch...</td>
                </tr>
            </tbody>
        </table>
    </main>

    <script>
        const STREAM_URL = "{{ url('/attendance/stream') }}";
        const DEVICES_URL = "{{ url('/attendance/devices') }}";

        const statusEl = document.getElementById('status');
        const rowsEl = document.getElementById('rows');
        const countEl = document.getElementById('count');
        const deviceSel = document.getElementById('device-filter');

        let lastId = 0;
        let total = 0;
        let currentDevice = '';
        let stream;

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
            } catch (e) {}
        }

        function connectStream() {
            if (stream) stream.close();

            const url = new URL(STREAM_URL, window.location.origin);
            if (currentDevice) url.searchParams.set('device', currentDevice);
            if (lastId > 0) url.searchParams.set('since_id', lastId);

            setStatus('connecting...', '');
            stream = new EventSource(url);
            stream.addEventListener('attendance', (event) => {
                const ev = JSON.parse(event.data);
                const isInitial = lastId === 0;
                lastId = Math.max(lastId, Number(ev.id));
                if (isInitial) rowsEl.innerHTML = '';
                rowsEl.prepend(renderRow(ev, !isInitial));
                total++;
                countEl.textContent = total + ' punches';
                while (rowsEl.children.length > 200) rowsEl.removeChild(rowsEl.lastChild);
                setStatus('live', 'live');
            });
            stream.onerror = () => setStatus('reconnecting...', 'error');
        }

        deviceSel.addEventListener('change', () => {
            currentDevice = deviceSel.value;
            lastId = 0;
            total = 0;
            rowsEl.innerHTML = '<tr><td colspan="7" class="empty">Loading...</td></tr>';
            connectStream();
        });

        loadDevices().then(() => {
            connectStream();
        });
    </script>
</body>

</html>
