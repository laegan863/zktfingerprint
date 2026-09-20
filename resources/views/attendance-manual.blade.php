<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>ZKTeco Attendance</title>
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

        .pagination {
            margin-top: 1.25rem;
            display: flex;
            gap: .4rem;
            flex-wrap: wrap;
        }

        .pagination a,
        .pagination span {
            padding: .4rem .7rem;
            border-radius: .5rem;
            border: 1px solid var(--border);
            color: var(--text);
            text-decoration: none;
            font-size: .85rem;
        }

        .pagination span.disabled,
        .pagination span.dots {
            color: var(--muted);
            border-color: transparent;
        }

        .pagination a:hover {
            border-color: var(--accent);
            color: var(--accent);
        }

        .pagination span.active {
            background: var(--accent);
            color: #0f172a;
            border-color: var(--accent);
            font-weight: 600;
        }
    </style>
</head>

<body>
    <header>
        <h1>ZKTeco Attendance</h1>
        <span class="pill">{{ $data->total() }} records</span>
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
                    <th>Received at</th>
                    <th>Mode</th>
                    <th>State</th>
                    <th>Workcode</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($data as $row)
                    <tr>
                        <td>{{ $row->device_name ?? $row->device_id }}</td>
                        <td><strong>{{ $row->user_id }}</strong></td>
                        <td>{{ $row->user_name ?? '—' }}</td>
                        <td>{{ $row->device_time }}</td>
                        <td>{{ $row->received_at }}</td>
                        <td class="mode-{{ str_replace(' ', '\\ ', \App\Support\ZktLabels::verify((int) $row->verify)) }}">
                            {{ \App\Support\ZktLabels::verify((int) $row->verify) }}
                        </td>
                        <td class="state-{{ str_replace(' ', '\\ ', \App\Support\ZktLabels::status((int) $row->status)) }}">
                            {{ \App\Support\ZktLabels::status((int) $row->status) }}
                        </td>
                        <td>{{ $row->workcode }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="empty">No attendance records yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        @if ($data->hasPages())
            <nav class="pagination">
                @if ($data->onFirstPage())
                    <span class="disabled">&laquo; Prev</span>
                @else
                    <a href="{{ $data->previousPageUrl() }}">&laquo; Prev</a>
                @endif

                @foreach ($data->getUrlRange(max(1, $data->currentPage() - 3), min($data->lastPage(), $data->currentPage() + 3)) as $page => $url)
                    @if ($page == $data->currentPage())
                        <span class="active">{{ $page }}</span>
                    @else
                        <a href="{{ $url }}">{{ $page }}</a>
                    @endif
                @endforeach

                @if ($data->hasMorePages())
                    <a href="{{ $data->nextPageUrl() }}">Next &raquo;</a>
                @else
                    <span class="disabled">Next &raquo;</span>
                @endif
            </nav>
        @endif
    </main>

    <script>
        // Keep the page (and its current pagination query string) fresh automatically.
        setInterval(() => window.location.reload(), 10000);
    </script>
</body>

</html>