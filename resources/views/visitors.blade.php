<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Visitors — Felix</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        body { background: #0f1117; color: #e2e8f0; font-family: 'JetBrains Mono', monospace; }
        .card { background: #1a1f2e; border: 1px solid #2d3748; border-radius: 10px; }
        .badge-pending  { background: #92400e; color: #fde68a; }
        .badge-approved { background: #064e3b; color: #6ee7b7; }
        .badge-rejected { background: #4c0519; color: #fca5a5; }
    </style>
</head>
<body class="min-h-screen p-6">

<div class="max-w-6xl mx-auto space-y-6">

    {{-- Header --}}
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-bold text-white">🔍 Visitors & Access Control</h1>
            <p class="text-xs text-slate-400 mt-1">Quản lý quyền truy cập khách</p>
        </div>
        <a href="{{ route('dashboard') }}" class="text-xs text-slate-400 hover:text-white px-3 py-1.5 rounded border border-slate-700 hover:border-slate-500 transition">← Dashboard</a>
    </div>

    @if(session('success'))
        <div class="bg-emerald-900/40 border border-emerald-700 text-emerald-300 text-sm px-4 py-3 rounded-lg">{{ session('success') }}</div>
    @endif

    {{-- Access Requests --}}
    <div class="card p-5">
        <h2 class="text-sm font-bold text-slate-300 mb-4 flex items-center gap-2">
            📋 Yêu cầu truy cập
            @php $pending = $requests->where('status', 'pending')->count() @endphp
            @if($pending > 0)
                <span class="bg-amber-500 text-black text-[10px] font-bold px-2 py-0.5 rounded-full">{{ $pending }} chờ duyệt</span>
            @endif
        </h2>

        @if($requests->isEmpty())
            <p class="text-slate-500 text-sm">Chưa có yêu cầu nào.</p>
        @else
            <div class="space-y-3">
                @foreach($requests as $req)
                @php
                    $loc = $req->location;
                    $locStr = $loc ? (($loc['city'] ?? '') . ', ' . ($loc['country'] ?? '')) : 'Không rõ';
                    $isp = $loc['isp'] ?? '';
                    $isActive = $req->status === 'approved' && $req->expires_at && $req->expires_at->isFuture();
                @endphp
                <div class="flex items-start gap-4 p-3 rounded-lg bg-slate-800/40 border border-slate-700/50">
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2 flex-wrap">
                            <span class="font-bold text-white text-sm">{{ $req->name }}</span>
                            <span class="badge-{{ $req->status }} text-[10px] px-2 py-0.5 rounded font-bold uppercase">
                                {{ $req->status === 'pending' ? 'Chờ' : ($req->status === 'approved' ? 'Đã duyệt' : 'Từ chối') }}
                            </span>
                            @if($isActive)
                                <span class="bg-blue-900 text-blue-300 text-[10px] px-2 py-0.5 rounded font-bold">
                                    ⏱ Hết hạn {{ $req->expires_at->diffForHumans() }}
                                </span>
                            @endif
                        </div>
                        <div class="text-xs text-slate-400 mt-1 space-y-0.5">
                            <div>🌐 <code class="text-slate-300">{{ $req->ip }}</code> — 📍 {{ $locStr }} {{ $isp ? "($isp)" : '' }}</div>
                            @if($req->message)
                                <div>💬 {{ $req->message }}</div>
                            @endif
                            <div>🕐 {{ $req->created_at->format('d/m/Y H:i') }}</div>
                        </div>
                    </div>
                    <div class="flex gap-2 shrink-0">
                        @if($req->status === 'pending')
                            <form method="POST" action="{{ route('access.approve', $req->id) }}">
                                @csrf
                                <button class="bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-bold px-3 py-1.5 rounded transition">✓ Duyệt 24h</button>
                            </form>
                            <form method="POST" action="{{ route('access.reject', $req->id) }}">
                                @csrf
                                <button class="bg-red-700 hover:bg-red-600 text-white text-xs font-bold px-3 py-1.5 rounded transition">✕ Từ chối</button>
                            </form>
                        @elseif($isActive)
                            <form method="POST" action="{{ route('access.revoke', $req->id) }}">
                                @csrf
                                <button class="bg-slate-600 hover:bg-slate-500 text-white text-xs font-bold px-3 py-1.5 rounded transition">Thu hồi</button>
                            </form>
                        @endif
                    </div>
                </div>
                @endforeach
            </div>
        @endif
    </div>

    {{-- Recent Visitors --}}
    <div class="card p-5">
        <h2 class="text-sm font-bold text-slate-300 mb-4">🌍 IP truy cập gần đây</h2>

        @if($recentVisitors->isEmpty())
            <p class="text-slate-500 text-sm">Chưa có lượt truy cập nào được ghi nhận.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-xs">
                    <thead>
                        <tr class="text-slate-500 border-b border-slate-700">
                            <th class="text-left pb-2 pr-4">IP</th>
                            <th class="text-left pb-2 pr-4">Vị trí</th>
                            <th class="text-left pb-2 pr-4">ISP</th>
                            <th class="text-left pb-2 pr-4">Lần cuối</th>
                            <th class="text-left pb-2">Lượt</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800">
                        @foreach($recentVisitors as $v)
                        @php
                            $loc = $v->location;
                            $locStr = $loc ? (($loc['city'] ?? '') . ', ' . ($loc['country'] ?? '')) : 'Không rõ';
                            $isp = $loc['isp'] ?? '—';
                        @endphp
                        <tr class="hover:bg-slate-800/30">
                            <td class="py-2 pr-4"><code class="text-emerald-400">{{ $v->ip }}</code></td>
                            <td class="py-2 pr-4 text-slate-300">{{ $locStr }}</td>
                            <td class="py-2 pr-4 text-slate-400">{{ $isp }}</td>
                            <td class="py-2 pr-4 text-slate-400">{{ \Carbon\Carbon::parse($v->last_seen)->diffForHumans() }}</td>
                            <td class="py-2 text-slate-400">{{ $v->visits }}x</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

</div>
</body>
</html>
