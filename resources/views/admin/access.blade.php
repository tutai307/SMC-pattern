<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Felix — Quản lý truy cập</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>body { background: #080c13; }</style>
</head>
<body class="min-h-screen text-white p-4 md:p-6">

<div class="max-w-5xl mx-auto space-y-6">

    <!-- Header -->
    <div class="flex items-center justify-between">
        <div class="flex items-center gap-3">
            <a href="/" class="text-slate-500 hover:text-slate-300 transition-all">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                </svg>
            </a>
            <div>
                <h1 class="text-lg font-bold">Quản lý truy cập</h1>
                <p class="text-slate-500 text-xs">Duyệt yêu cầu · Xem IP · Thu hồi quyền</p>
            </div>
        </div>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button class="text-slate-500 hover:text-slate-300 text-xs border border-white/10 px-3 py-1.5 rounded-lg transition-all">Đăng xuất</button>
        </form>
    </div>

    @if(session('success'))
    <div class="bg-green-500/10 border border-green-500/30 rounded-xl px-4 py-3 text-green-400 text-sm">
        {{ session('success') }}
    </div>
    @endif

    <!-- Stats row -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
        @foreach([
            ['label' => 'Chờ duyệt', 'val' => $pending->count(), 'color' => 'yellow'],
            ['label' => 'Đang truy cập', 'val' => $approved->count(), 'color' => 'green'],
            ['label' => 'Đã từ chối', 'val' => $denied->count(), 'color' => 'red'],
            ['label' => 'Lượt ghé thăm', 'val' => $visits->count(), 'color' => 'blue'],
        ] as $s)
        <div class="bg-white/[0.03] border border-white/[0.06] rounded-xl p-4">
            <div class="text-{{ $s['color'] }}-400 text-2xl font-bold font-mono">{{ $s['val'] }}</div>
            <div class="text-slate-500 text-xs mt-0.5">{{ $s['label'] }}</div>
        </div>
        @endforeach
    </div>

    <!-- Pending requests -->
    @if($pending->count())
    <div class="bg-white/[0.03] border border-yellow-500/20 rounded-2xl overflow-hidden">
        <div class="px-5 py-3 border-b border-white/[0.06] flex items-center gap-2">
            <span class="w-2 h-2 bg-yellow-400 rounded-full animate-pulse"></span>
            <h2 class="font-semibold text-sm">Yêu cầu đang chờ duyệt ({{ $pending->count() }})</h2>
        </div>
        <div class="divide-y divide-white/[0.04]">
            @foreach($pending as $r)
            <div class="px-5 py-4 flex items-center justify-between gap-4">
                <div class="min-w-0">
                    <div class="font-semibold text-sm text-white">{{ $r->name }}</div>
                    <div class="text-slate-500 text-xs mt-0.5 flex items-center gap-2 flex-wrap">
                        <code class="text-slate-400">{{ $r->ip }}</code>
                        <span>·</span>
                        <span>{{ $r->location ?? 'Không rõ' }}</span>
                        <span>·</span>
                        <span>{{ $r->created_at->diffForHumans() }}</span>
                    </div>
                    @if($r->user_agent)
                    <div class="text-slate-700 text-[10px] mt-0.5 truncate max-w-xs">{{ $r->user_agent }}</div>
                    @endif
                </div>
                <div class="flex gap-2 shrink-0">
                    <form method="POST" action="{{ route('access.approve', $r->id) }}">
                        @csrf
                        <button class="bg-green-600/20 hover:bg-green-600/40 text-green-400 border border-green-500/30 px-4 py-1.5 rounded-lg text-xs font-bold transition-all">
                            ✓ Duyệt
                        </button>
                    </form>
                    <form method="POST" action="{{ route('access.deny', $r->id) }}">
                        @csrf
                        <button class="bg-red-600/10 hover:bg-red-600/20 text-red-400 border border-red-500/20 px-4 py-1.5 rounded-lg text-xs font-bold transition-all">
                            ✕ Từ chối
                        </button>
                    </form>
                </div>
            </div>
            @endforeach
        </div>
    </div>
    @endif

    <!-- Approved (active) -->
    @if($approved->count())
    <div class="bg-white/[0.03] border border-white/[0.06] rounded-2xl overflow-hidden">
        <div class="px-5 py-3 border-b border-white/[0.06]">
            <h2 class="font-semibold text-sm text-green-400">Đang có quyền truy cập ({{ $approved->count() }})</h2>
        </div>
        <div class="divide-y divide-white/[0.04]">
            @foreach($approved as $r)
            <div class="px-5 py-3 flex items-center justify-between gap-4">
                <div class="min-w-0">
                    <div class="font-semibold text-sm">{{ $r->name ?? '—' }}</div>
                    <div class="text-slate-500 text-xs flex items-center gap-2 flex-wrap">
                        <code class="text-slate-400">{{ $r->ip }}</code>
                        <span>·</span>
                        <span>{{ $r->location ?? 'Không rõ' }}</span>
                        <span>·</span>
                        <span class="text-green-500">Hết hạn {{ $r->expires_at->diffForHumans() }}</span>
                    </div>
                </div>
                <form method="POST" action="{{ route('access.revoke', $r->id) }}">
                    @csrf
                    <button class="text-slate-500 hover:text-red-400 border border-white/10 hover:border-red-500/30 px-3 py-1 rounded-lg text-xs transition-all">
                        Thu hồi
                    </button>
                </form>
            </div>
            @endforeach
        </div>
    </div>
    @endif

    <!-- Recent visits (anonymous) -->
    @if($visits->count())
    <div class="bg-white/[0.02] border border-white/[0.04] rounded-2xl overflow-hidden">
        <div class="px-5 py-3 border-b border-white/[0.04]">
            <h2 class="font-semibold text-sm text-slate-400">Lượt ghé thăm gần đây (chưa đăng nhập)</h2>
        </div>
        <div class="divide-y divide-white/[0.03]">
            @foreach($visits as $r)
            <div class="px-5 py-2.5 flex items-center justify-between gap-4">
                <div class="flex items-center gap-3 min-w-0">
                    <div class="w-1.5 h-1.5 bg-slate-600 rounded-full shrink-0"></div>
                    <div>
                        <code class="text-slate-400 text-xs">{{ $r->ip }}</code>
                        <span class="text-slate-600 text-xs ml-2">{{ $r->location ?? 'Không rõ' }}</span>
                    </div>
                </div>
                <span class="text-slate-700 text-xs shrink-0">{{ $r->created_at->diffForHumans() }}</span>
            </div>
            @endforeach
        </div>
    </div>
    @endif

    @if(!$pending->count() && !$approved->count() && !$visits->count())
    <div class="text-center py-16 text-slate-600">
        <p class="text-4xl mb-3">📭</p>
        <p class="text-sm">Chưa có lượt truy cập nào.</p>
    </div>
    @endif

</div>

</body>
</html>
