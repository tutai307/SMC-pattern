<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>404 — Felix Terminal</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { background: #0a0e17; font-family: ui-monospace, monospace; }
        .glow { text-shadow: 0 0 60px rgba(59,130,246,0.3), 0 0 20px rgba(59,130,246,0.2); }
        @keyframes flicker { 0%,100%{opacity:1} 92%{opacity:1} 93%{opacity:.8} 95%{opacity:1} 97%{opacity:.9} }
        .flicker { animation: flicker 4s infinite; }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center text-white">
    <div class="text-center px-6 space-y-6 max-w-md">
        <!-- Logo -->
        <div class="text-sm font-bold tracking-widest text-blue-500/60 uppercase mb-2">Felix Terminal</div>

        <!-- 404 -->
        <div class="text-[96px] md:text-[120px] font-black leading-none text-slate-800 glow flicker select-none">
            404
        </div>

        <!-- Message -->
        <div class="space-y-2">
            <h1 class="text-lg font-semibold text-slate-300">Symbol không tìm thấy</h1>
            <p class="text-slate-500 text-sm leading-relaxed">
                Coin này không tồn tại trên Binance Futures, hoặc đường dẫn không hợp lệ.
            </p>
        </div>

        <!-- Divider -->
        <div class="border-t border-white/5"></div>

        <!-- Actions -->
        <div class="flex flex-col sm:flex-row gap-3 justify-center">
            <a href="/"
               class="px-6 py-2.5 bg-blue-600 hover:bg-blue-500 rounded-lg text-sm font-semibold transition-colors">
                ← Về trang chủ
            </a>
            <a href="/?symbol=BTCUSDT&timeframe=15m&method=smc"
               class="px-6 py-2.5 bg-white/5 hover:bg-white/10 border border-white/10 rounded-lg text-sm font-semibold transition-colors">
                Xem BTCUSDT
            </a>
        </div>

        <!-- Popular symbols -->
        <div class="space-y-2">
            <p class="text-[10px] text-slate-600 uppercase tracking-widest">Symbols phổ biến</p>
            <div class="flex flex-wrap gap-2 justify-center">
                @foreach(['ETHUSDT','SOLUSDT','BNBUSDT','XRPUSDT','ADAUSDT'] as $sym)
                <a href="/?symbol={{ $sym }}&timeframe=15m&method=smc"
                   class="px-3 py-1 text-[10px] font-mono bg-white/5 hover:bg-blue-500/20 hover:text-blue-400 border border-white/10 rounded-md transition-colors">
                    {{ $sym }}
                </a>
                @endforeach
            </div>
        </div>
    </div>
</body>
</html>
