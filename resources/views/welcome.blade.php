<!DOCTYPE html>
<html lang="en">
<head>
    <style>
        #page-loader { position:fixed;top:0;left:0;height:2px;background:linear-gradient(90deg,#3b82f6,#06b6d4);z-index:9999;width:0;opacity:0;transition:width 2s ease-out,opacity .15s; }
    </style>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>${{ number_format($currentPrice, $currentPrice < 10 ? 4 : 2) }} {{ $symbol }} — Felix</title>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="shortcut icon" href="/favicon.ico">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="{{ asset('css/dashboard.css') }}?v=3">
    @vite(['resources/js/app.js'])
    <script src="https://unpkg.com/lightweight-charts@4.1.1/dist/lightweight-charts.standalone.production.js"></script>
</head>
<body class="bg-[#0a0e17] text-white">
    <div id="page-loader"></div>
    <!-- Header -->
    <header class="px-4 py-3 md:px-6 md:py-4 border-b border-white/10 bg-[#0a0e17]/80 sticky top-0 z-50 backdrop-blur-md">
        <!-- Row 1: Logo + Price -->
        <div class="flex justify-between items-center">
            <div class="flex items-center gap-2 md:gap-4">
                <div class="text-xl md:text-2xl font-bold bg-gradient-to-r from-blue-500 to-cyan-400 bg-clip-text text-transparent">
                    Felix <span class="text-xs font-normal text-slate-500">v1.0</span>
                </div>
                <div class="hidden sm:flex items-center text-sm text-slate-400">
                    <span class="indicator-dot dot-online"></span> Market Online
                </div>
                <a href="/academy" class="hidden sm:flex px-3 py-1 bg-purple-500/10 text-purple-400 text-[10px] font-bold rounded-lg border border-purple-500/20 hover:bg-purple-500/20 transition-all items-center space-x-1">
                    <span class="w-1.5 h-1.5 bg-purple-500 rounded-full animate-pulse"></span>
                    <span>TOM ACADEMY</span>
                </a>
                <a href="/planner" class="hidden sm:flex px-3 py-1 bg-blue-500/10 text-blue-400 text-[10px] font-bold rounded-lg border border-blue-500/20 hover:bg-blue-500/20 transition-all items-center space-x-1">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path></svg>
                    <span>KẾ HOẠCH</span>
                </a>
            </div>

            <div class="flex items-center gap-2 md:gap-6">
                <!-- Search: desktop only -->
                <form action="/" method="GET" class="relative hidden md:block" id="symbol-form-desktop">
                    <input type="hidden" name="timeframe" value="{{ $timeframe }}">
                    <input type="hidden" name="method" value="{{ $method }}">
                    <div class="symbol-autocomplete" data-form="symbol-form-desktop">
                        <input type="text" name="symbol" value="{{ $symbol }}" autocomplete="off"
                            class="bg-white/5 border border-white/10 rounded-lg px-4 py-1.5 pr-9 text-sm focus:outline-none focus:border-blue-500/50 w-36 transition-all focus:w-52 font-mono uppercase placeholder:normal-case"
                            placeholder="Search symbol...">
                        <button type="submit" class="absolute right-3 top-2 text-slate-500 hover:text-blue-400">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                        </button>
                        <ul class="symbol-dropdown hidden absolute z-50 top-full mt-1 left-0 w-64 bg-[#141923] border border-white/10 rounded-xl shadow-2xl overflow-hidden max-h-72 overflow-y-auto"></ul>
                    </div>
                </form>

                <div class="text-right">
                    <div class="text-[10px] text-slate-500 uppercase">{{ $symbol }}</div>
                    <div id="current-price-display" class="text-base md:text-xl font-mono text-green-400" data-last-price="{{ $currentPrice }}">
                        ${{ number_format($currentPrice, 2) }}
                    </div>
                </div>
                <div class="hidden md:flex items-center gap-4">
                    <div class="h-10 w-px bg-white/10"></div>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="text-slate-500 hover:text-slate-300 px-3 py-1.5 rounded-lg text-xs font-semibold transition-all border border-white/10 hover:border-white/20">
                            Đăng xuất
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Row 2: Mobile nav + search -->
        <div class="flex items-center gap-2 mt-2 sm:hidden">
            <a href="/academy" class="flex items-center gap-1 px-2.5 py-1 bg-purple-500/10 text-purple-400 text-[10px] font-bold rounded-lg border border-purple-500/20">
                <span class="w-1.5 h-1.5 bg-purple-500 rounded-full animate-pulse"></span>
                <span>ACADEMY</span>
            </a>
            <a href="/planner" class="flex items-center gap-1 px-2.5 py-1 bg-blue-500/10 text-blue-400 text-[10px] font-bold rounded-lg border border-blue-500/20">
                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path></svg>
                <span>PLAN</span>
            </a>
            <form action="/" method="GET" class="relative flex-1" id="symbol-form-mobile">
                <input type="hidden" name="timeframe" value="{{ $timeframe }}">
                <input type="hidden" name="method" value="{{ $method }}">
                <div class="symbol-autocomplete" data-form="symbol-form-mobile">
                    <input type="text" name="symbol" value="{{ $symbol }}" autocomplete="off"
                        class="bg-white/5 border border-white/10 rounded-lg px-3 py-1.5 pr-9 text-sm focus:outline-none focus:border-blue-500/50 w-full font-mono uppercase placeholder:normal-case"
                        placeholder="Tìm symbol...">
                    <button type="submit" class="absolute right-2.5 top-2 text-slate-500 hover:text-blue-400">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                    </button>
                    <ul class="symbol-dropdown hidden absolute z-50 top-full mt-1 left-0 right-0 bg-[#141923] border border-white/10 rounded-xl shadow-2xl overflow-hidden max-h-64 overflow-y-auto"></ul>
                </div>
            </form>
        </div>
    </header>

    <!-- SMC Watchlist Bar -->
    <div class="border-b border-white/[0.06] bg-[#0d1117]/80 backdrop-blur-sm sticky top-[57px] z-30">
        <div class="max-w-screen-2xl mx-auto px-3 md:px-6">
            <div class="flex items-center gap-1 overflow-x-auto py-2 scrollbar-none" id="watchlist-bar">
                <span class="text-[9px] text-slate-600 uppercase font-bold tracking-widest shrink-0 pr-2 border-r border-white/10 mr-1">SMC</span>
                @php
                $watchlistCoins = [
                    ['sym' => 'XAUUSDT', 'label' => 'XAU', 'name' => 'Gold',     'tier' => 1],
                    ['sym' => 'XAGUSDT', 'label' => 'XAG', 'name' => 'Silver',   'tier' => 1],
                    ['sym' => 'BTCUSDT', 'label' => 'BTC', 'name' => 'Bitcoin',  'tier' => 1],
                    ['sym' => 'ETHUSDT', 'label' => 'ETH', 'name' => 'Ethereum', 'tier' => 1],
                ];
                @endphp
                @foreach($watchlistCoins as $coin)
                @php $isActive = strtoupper($symbol) === $coin['sym']; @endphp
                <button
                    onclick="navigate('{{ strtolower($coin['sym']) }}', currentTimeframe, currentMethod)"
                    title="{{ $coin['name'] }}"
                    class="watchlist-coin shrink-0 flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-[11px] font-bold transition-all
                        {{ $isActive
                            ? 'bg-blue-600/20 text-blue-300 border border-blue-500/40'
                            : 'text-slate-500 hover:text-slate-200 hover:bg-white/5 border border-transparent' }}"
                    data-sym="{{ strtolower($coin['sym']) }}">
                    @if($coin['tier'] === 1)
                    <span class="w-1.5 h-1.5 rounded-full shrink-0 {{ $isActive ? 'bg-blue-400' : 'bg-slate-600' }}"></span>
                    @else
                    <span class="w-1.5 h-1.5 rounded-full shrink-0 {{ $isActive ? 'bg-blue-400' : 'bg-slate-700' }}"></span>
                    @endif
                    {{ $coin['label'] }}
                </button>
                @endforeach
            </div>
        </div>
    </div>

    <main class="trading-container">
        <!-- Chart Section -->
        <div class="glass-card p-4">
            <div class="flex flex-wrap justify-between items-center gap-2 mb-4">
                <h2 class="text-base md:text-lg font-semibold flex items-center">
                    <svg class="w-4 h-4 md:w-5 md:h-5 mr-2 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 12l3-3 3 3 4-4M8 21l4-4 4 4M3 4h18M4 4h16v12a1 1 0 01-1 1H5a1 1 0 01-1-1V4z"></path></svg>
                    <span class="hidden sm:inline">Chart</span>
                </h2>
                <div class="flex flex-wrap gap-1.5 justify-end">
                    <!-- Method Toggle -->
                    <div class="flex bg-white/5 p-1 rounded-lg">
                        <button data-method="smc" class="method-btn px-3 py-1 rounded-md text-[10px] font-bold transition-all {{ ($method ?? 'smc') == 'smc' ? 'bg-blue-600 text-white shadow-md' : 'text-slate-500 hover:text-slate-300' }}">SMC</button>
                        <button data-method="elliot" class="method-btn px-3 py-1 rounded-md text-[10px] font-bold transition-all {{ ($method ?? 'smc') == 'elliot' ? 'bg-blue-600 text-white shadow-md' : 'text-slate-500 hover:text-slate-300' }}">ELLIOT</button>
                    </div>

                    <button data-tf="15m" class="tf-btn px-3 md:px-4 py-1.5 rounded-lg text-[10px] md:text-xs font-bold transition-all {{ $timeframe == '15m' ? 'bg-blue-600 text-white shadow-lg shadow-blue-500/20' : 'bg-white/5 text-slate-400 hover:bg-white/10' }}">M15</button>
                    <button data-tf="1h"  class="tf-btn px-3 md:px-4 py-1.5 rounded-lg text-[10px] md:text-xs font-bold transition-all {{ $timeframe == '1h'  ? 'bg-blue-600 text-white shadow-lg shadow-blue-500/20' : 'bg-white/5 text-slate-400 hover:bg-white/10' }}">H1</button>
                    <button data-tf="4h"  class="tf-btn px-3 md:px-4 py-1.5 rounded-lg text-[10px] md:text-xs font-bold transition-all {{ $timeframe == '4h'  ? 'bg-blue-600 text-white shadow-lg shadow-blue-500/20' : 'bg-white/5 text-slate-400 hover:bg-white/10' }}">H4</button>
                </div>
            </div>
            {{-- Skeleton: visible until JS renders the chart --}}
            <div id="chart-skeleton" class="chart-container relative overflow-hidden rounded-lg bg-[#0a0e17] border border-white/[0.06]">
                {{-- Horizontal grid lines --}}
                <div class="absolute inset-0 flex flex-col justify-between py-6 pl-3 pr-16 pointer-events-none">
                    @for ($i = 0; $i < 6; $i++)
                        <div class="w-full h-px bg-white/[0.04]"></div>
                    @endfor
                </div>
                {{-- Fake candle bars --}}
                @php $bars = [28,42,58,35,52,70,44,62,80,48,33,60,72,46,55,84,38,65,50,74,36,53,67,43,61,77,34,55,47,63,79,41,57,49,68,30,58,45,72,38]; @endphp
                <div class="absolute left-3 right-16 flex items-end gap-[3px]" style="bottom:32px;top:24px">
                    @foreach($bars as $i => $h)
                        <div class="flex-1 rounded-sm animate-pulse"
                             style="height:{{ $h }}%;background:{{ $i % 3 === 1 ? 'rgba(239,68,68,0.12)' : 'rgba(34,197,94,0.12)' }};animation-delay:{{ round($i * 0.04, 2) }}s"></div>
                    @endforeach
                </div>
                {{-- Time axis --}}
                <div class="absolute bottom-2 left-4 right-16 flex justify-between px-2">
                    @for ($i = 0; $i < 4; $i++)
                        <div class="h-2 w-14 bg-white/[0.05] rounded animate-pulse"></div>
                    @endfor
                </div>
                {{-- Price axis --}}
                <div class="absolute top-3 right-2 bottom-8 flex flex-col justify-between items-end pr-1">
                    @for ($i = 0; $i < 6; $i++)
                        <div class="h-2 w-10 bg-white/[0.05] rounded animate-pulse"></div>
                    @endfor
                </div>
                {{-- Loading pill --}}
                <div class="absolute inset-0 flex items-center justify-center">
                    <div class="flex items-center gap-2.5 bg-[#0a0e17]/90 px-4 py-2 rounded-lg border border-white/10 shadow-xl">
                        <svg class="w-4 h-4 animate-spin text-blue-400 shrink-0" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
                        </svg>
                        <span class="text-[11px] text-slate-400 font-medium tracking-wide">Đang tải chart...</span>
                    </div>
                </div>
            </div>
            <div id="chart" class="chart-container" style="display:none"></div>
        </div>

        <!-- Sidebar / Signals -->
        <div class="space-y-4 md:space-y-6">
            <div class="glass-card p-3 md:p-6" id="analysis-panel">
                <div class="mb-4">
                    <h3 class="text-slate-400 text-xs font-bold uppercase tracking-wider mb-3">Dự đoán Vào lệnh AI</h3>
                    <form id="propose-form" action="{{ url()->current() }}" method="GET" class="flex items-center gap-2"
                          data-sl="{{ $analysis['signal']['sl'] ?? '' }}"
                          data-rr="{{ isset($analysis['signal']['entry'], $analysis['signal']['sl'], $analysis['signal']['tp']) ? round(abs($analysis['signal']['tp'] - $analysis['signal']['entry']) / max(abs($analysis['signal']['entry'] - $analysis['signal']['sl']), 0.000001), 1) : '0' }}">
                        <input type="hidden" name="symbol" value="{{ request('symbol', $symbol) }}">
                        <input type="hidden" name="timeframe" value="{{ request('timeframe', $timeframe) }}">
                        <input type="hidden" name="method" value="{{ request('method', $method ?? 'smc') }}">
                        <input type="hidden" name="propose" value="1">

                        <input type="number" name="capital" id="capital-input" value="{{ request('capital', 100) }}" placeholder="Vốn ($)" class="bg-white/5 border border-white/10 rounded px-2 py-1.5 text-[10px] flex-1 min-w-0 text-white focus:outline-none focus:border-blue-500/50" min="1" step="any" required>

                        <button type="submit" id="propose-btn" class="text-[10px] bg-blue-500/20 text-blue-400 px-3 py-1.5 rounded border border-blue-500/30 hover:bg-blue-500/30 transition-all font-bold whitespace-nowrap flex-shrink-0">
                            ĐỀ XUẤT LỆNH
                        </button>
                    </form>
                    <button id="open-advisor-btn"
                        class="mt-2 w-full text-[10px] bg-purple-500/10 text-purple-400 px-3 py-1.5 rounded border border-purple-500/20 hover:bg-purple-500/20 transition-all font-bold uppercase tracking-wider flex items-center justify-center gap-1.5">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.347.347A3.75 3.75 0 0112 18.75a3.75 3.75 0 01-2.652-1.1l-.347-.347z"/></svg>
                        Tư vấn lệnh đang mở
                    </button>
                </div>

                <div id="signal-body">
                @if($analysis['signal'])
                    @php
                        $isCounter = $analysis['signal']['is_counter_trend'] ?? false;
                        $signalColor = $isCounter ? 'border-amber-500/50 bg-amber-500/5' : ($analysis['signal']['type'] == 'MUA' ? 'border-green-500/50 bg-green-500/5' : 'border-red-500/50 bg-red-500/5');
                        $badgeColor = $isCounter ? 'bg-amber-500/20 text-amber-400' : ($analysis['signal']['type'] == 'MUA' ? 'bg-green-500/20 text-green-400' : 'bg-red-500/20 text-red-400');
                        $textColor = $isCounter ? 'text-amber-400' : ($analysis['signal']['type'] == 'MUA' ? 'text-green-400' : 'text-red-400');
                        $aiScore = $analysis['signal']['ai_score'] ?? null;
                        $aiError = $analysis['signal']['ai_error'] ?? null;
                    @endphp

                    @if($aiError)
                    <div class="bg-red-500/20 border border-red-500/50 p-3 rounded-lg mb-4 text-[10px] text-red-400">
                        <strong>⚠️ LỖI AI:</strong> {{ $aiError }} <br>
                        <span class="text-slate-400">Vui lòng kiểm tra OPENROUTER_API_KEY trong file .env</span>
                    </div>
                    @endif

                    <div class="signal-card {{ $signalColor }} border rounded-xl p-4 mb-4 relative overflow-hidden">
                        @if($aiScore)
                        <div class="absolute top-0 right-0 p-2">
                            <div class="flex flex-col items-center">
                                <span class="text-[8px] text-slate-500 font-bold">AI SCORE</span>
                                <span class="text-xl font-black {{ $aiScore >= 80 ? 'text-green-400' : ($aiScore >= 60 ? 'text-amber-400' : 'text-red-400') }}">{{ $aiScore }}</span>
                            </div>
                        </div>
                        @endif

                        <div class="flex justify-between items-start mb-2">
                            <span class="{{ $textColor }} font-bold text-base md:text-lg">
                                Lệnh {{ $analysis['signal']['type'] }}
                                @if($isCounter) <span class="text-[10px] ml-1 px-1 rounded bg-amber-500/20">RỦI RO</span> @endif
                            </span>
                            <span class="{{ $badgeColor }} text-[10px] px-2 py-0.5 rounded-full uppercase font-bold" title="Điểm confluence (không phải winrate lịch sử)" style="margin-right:{{ $aiScore ? '44px' : '0' }}">{{ $analysis['signal']['winrate'] }}% Confluence</span>
                        </div>
                        @php
                            $capital = request('capital', 0);
                            $margin = 0;
                            $leverage = 0;
                            $volume = 0;
                            $rrRatio = 0;
                            $liqPrice = 0;
                            $riskAmount = 0;
                            $slTooTight = false;

                            if ($capital > 0 && isset($analysis['signal']['entry'], $analysis['signal']['sl'], $analysis['signal']['tp'])) {
                                $entry = $analysis['signal']['entry'];
                                $sl    = $analysis['signal']['sl'];
                                $tp    = $analysis['signal']['tp'];
                                $isLong = ($analysis['signal']['type'] === 'MUA' || $analysis['signal']['type'] === 'LONG');

                                $slPercent = abs($entry - $sl) / $entry;
                                $tpPercent = abs($tp - $entry) / $entry;

                                if ($slPercent > 0) {
                                    // Cảnh báo nếu SL quá chật (< 0.8%) — dễ bị quét bởi noise
                                    $slTooTight = $slPercent < 0.008;

                                    // Leverage an toàn: giữ khoảng cách liquidation = 2× SL distance
                                    // Công thức: liq_distance ≈ 1/leverage → cần 1/L ≥ 2×SL%
                                    // → leverage ≤ 1/(2×SL%), cap cứng tại 20x
                                    $safeLeverage = floor(1 / ($slPercent * 2));
                                    $leverage = max(1, min(20, $safeLeverage));

                                    // Rủi ro tối đa = 2% vốn
                                    $riskAmount = $capital * 0.02;

                                    // Khối lượng notional dựa trên rủi ro thực
                                    $volume = $riskAmount / $slPercent;

                                    // Margin cần nạp = notional / leverage
                                    $margin = $volume / $leverage;

                                    // R:R ratio
                                    $rrRatio = $slPercent > 0 ? round($tpPercent / $slPercent, 2) : 0;

                                    // Giá thanh lý ước tính (isolated margin, bỏ qua fee ~0.5%)
                                    $liqBuffer = 1 / $leverage;
                                    $liqPrice = $isLong
                                        ? $entry * (1 - $liqBuffer * 0.9)
                                        : $entry * (1 + $liqBuffer * 0.9);
                                }
                            }

                            // Màu đòn bẩy
                            $levColor = $leverage <= 10 ? 'text-green-400' : ($leverage <= 15 ? 'text-amber-400' : 'text-red-400');
                            // Màu R:R
                            $rrColor  = $rrRatio >= 2 ? 'text-green-400' : ($rrRatio >= 1.5 ? 'text-amber-400' : 'text-red-400');
                        @endphp
                        <div class="space-y-2 text-sm mb-3">
                            <div class="flex justify-between"><span class="text-slate-500">Điểm vào</span> <span class="font-mono text-white">${{ number_format($analysis['signal']['entry'], 4) }}</span></div>
                            <div class="flex justify-between"><span class="text-slate-500">Chốt lời</span> <span class="font-mono text-green-400">${{ number_format($analysis['signal']['tp'], 4) }}</span></div>
                            <div class="flex justify-between"><span class="text-slate-500">Cắt lỗ</span> <span class="font-mono text-red-400">${{ number_format($analysis['signal']['sl'], 4) }}</span></div>
                        </div>

                        @if($capital > 0)
                        @if($slTooTight)
                        <div class="bg-red-500/10 border border-red-500/30 rounded-lg p-2 mb-2 text-[10px] text-red-400">
                            ⚠ SL quá chật (&lt;0.8%) — dễ bị quét bởi noise thị trường. Nên mở rộng SL hoặc chờ setup rõ hơn.
                        </div>
                        @endif
                        <div class="bg-black/20 border border-white/5 rounded-lg p-3 text-sm space-y-2 mb-2">
                            <div class="flex justify-between text-[11px] text-slate-400 mb-1">
                                <span>Quản lý vốn (Risk 2%)</span>
                                <span>Vốn: <span class="text-white">${{ number_format($capital, 2) }}</span></span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span class="text-slate-500">Ký quỹ cần nạp</span>
                                <span class="font-mono text-blue-400 font-bold">${{ number_format($margin, 2) }}</span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span class="text-slate-500">Đòn bẩy đề xuất</span>
                                <span class="font-mono {{ $levColor }} font-bold">{{ $leverage }}x</span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span class="text-slate-500">Khối lượng lệnh</span>
                                <span class="font-mono text-white">${{ number_format($volume, 2) }}</span>
                            </div>
                            <div class="border-t border-white/5 pt-2 flex justify-between items-center">
                                <span class="text-slate-500">Tỉ lệ R:R</span>
                                <span class="font-mono {{ $rrColor }} font-bold">1 : {{ $rrRatio }}</span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span class="text-slate-500">Lỗ tối đa</span>
                                <span class="font-mono text-red-400">-${{ number_format($riskAmount, 2) }} (2% vốn)</span>
                            </div>
                            <div class="flex justify-between items-center text-[10px]">
                                <span class="text-slate-500">Giá thanh lý (~)</span>
                                <span class="font-mono text-orange-400">${{ number_format($liqPrice, 4) }}</span>
                            </div>
                        </div>
                        @endif
                    </div>

                    <!-- VERDICT BANNER -->
                    @php
                        $vDecision = $verdict['decision'] ?? 'NEUTRAL';
                        $vReasons  = $verdict['reasons'] ?? [];
                        $vStyle = match($vDecision) {
                            'ENTER'   => ['border' => 'border-green-500/60',  'bg' => 'bg-green-500/10',  'text' => 'text-green-400',  'icon' => '✅', 'label' => 'VÀO LỆNH'],
                            'CAUTION' => ['border' => 'border-amber-500/60',  'bg' => 'bg-amber-500/10',  'text' => 'text-amber-400',  'icon' => '⚠️', 'label' => 'CẨN THẬN'],
                            'SKIP'    => ['border' => 'border-red-500/60',    'bg' => 'bg-red-500/10',    'text' => 'text-red-400',    'icon' => '❌', 'label' => 'KHÔNG VÀO'],
                            default   => ['border' => 'border-slate-500/40',  'bg' => 'bg-slate-500/5',   'text' => 'text-slate-400',  'icon' => '—',  'label' => 'CHƯA RÕ'],
                        };
                    @endphp
                    <div class="border {{ $vStyle['border'] }} {{ $vStyle['bg'] }} rounded-xl p-3 mb-4">
                        <div class="flex items-center justify-between mb-1.5">
                            <span class="text-[10px] text-slate-500 font-bold uppercase tracking-widest">Phán quyết cuối</span>
                            <span class="{{ $vStyle['text'] }} font-black text-sm tracking-wide">{{ $vStyle['icon'] }} {{ $vStyle['label'] }}</span>
                        </div>
                        @foreach($vReasons as $reason)
                        <div class="text-[10px] text-slate-400 flex items-start gap-1.5 mt-1">
                            <span class="{{ $vStyle['text'] }} mt-0.5 flex-shrink-0">›</span>
                            <span>{{ $reason }}</span>
                        </div>
                        @endforeach
                    </div>

                    <!-- AI Deep Insights Section -->
                    @if(isset($analysis['signal']['ai_analysis']))
                    <div class="space-y-3 mt-4">
                        <div class="bg-blue-500/10 border border-blue-500/20 p-4 rounded-xl">
                            <div class="flex items-center mb-2">
                                <span class="w-2 h-2 bg-blue-400 rounded-full animate-pulse mr-2"></span>
                                <span class="text-[10px] text-blue-400 font-bold uppercase tracking-widest">AI Market Analysis</span>
                            </div>
                            <p class="text-[11px] text-slate-300 leading-relaxed">{{ $analysis['signal']['ai_analysis'] }}</p>
                        </div>

                        <div class="bg-red-500/10 border border-red-500/20 p-4 rounded-xl">
                            <div class="flex items-center mb-2">
                                <svg class="w-3 h-3 text-red-400 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                                <span class="text-[10px] text-red-400 font-bold uppercase tracking-widest">Risk Management</span>
                            </div>
                            <p class="text-[11px] text-slate-300 leading-relaxed">{{ $analysis['signal']['ai_risk'] }}</p>
                        </div>

                        <div class="bg-green-500/10 border border-green-500/20 p-4 rounded-xl shadow-lg shadow-green-500/5">
                            <div class="flex items-center mb-2">
                                <svg class="w-3 h-3 text-green-400 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                <span class="text-[10px] text-green-400 font-bold uppercase tracking-widest">Final Verdict</span>
                            </div>
                            <p class="text-[11px] text-white font-medium leading-relaxed">{{ $analysis['signal']['ai_recommendation'] }}</p>
                        </div>

                        @if(!empty($analysis['signal']['ai_entry_timing']))
                        <div class="bg-amber-500/10 border border-amber-500/20 p-3 rounded-xl">
                            <div class="flex items-center gap-2 mb-1">
                                <svg class="w-3 h-3 text-amber-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                <span class="text-[10px] text-amber-400 font-bold uppercase tracking-widest">Entry Timing</span>
                            </div>
                            <p class="text-[11px] text-slate-300 leading-relaxed">{{ $analysis['signal']['ai_entry_timing'] }}</p>
                        </div>
                        @endif
                    </div>
                    @elseif(isset($analysis['signal']['ai_comment']))
                    <div class="bg-blue-500/10 border border-blue-500/20 p-3 rounded-lg mb-4">
                        <div class="flex items-center mb-1">
                            <span class="w-2 h-2 bg-blue-400 rounded-full animate-pulse mr-2"></span>
                            <span class="text-[10px] text-blue-400 font-bold uppercase">AI Insights</span>
                        </div>
                        <p class="text-[11px] text-slate-300 italic">"{{ $analysis['signal']['ai_comment'] }}"</p>
                    </div>
                    @endif

                    <div class="flex flex-col space-y-2">
                        <div class="text-xs text-slate-500 bg-white/5 p-3 rounded-lg leading-relaxed italic border-l-2 {{ $isCounter ? 'border-amber-500' : ($analysis['signal']['type'] == 'MUA' ? 'border-green-500' : 'border-red-500') }}">
                            "{{ $analysis['signal']['reason'] }}"
                        </div>
                        <button onclick="toggleReason()" class="text-[10px] text-blue-400 hover:text-blue-300 flex items-center justify-center py-2 border border-blue-500/20 rounded-lg hover:bg-blue-500/5 transition-all">
                            <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                            TẠI SAO CÓ KÈO & CÁCH TÍNH WINRATE?
                        </button>
                    </div>
                @else
                    <div class="text-center py-8 text-slate-500 text-sm">
                        <svg class="w-10 h-10 mx-auto mb-3 opacity-20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                        Không phát hiện tín hiệu xác suất cao. <br>Xu hướng hiện tại: <span class="text-blue-400 uppercase font-bold">{{ $analysis['structure']['trend'] }}</span>
                        <button onclick="toggleReason()" class="mt-4 block w-full text-[10px] text-slate-400 hover:text-white py-2 border border-white/10 rounded-lg hover:bg-white/5 transition-all">
                            TẠI SAO KHÔNG CÓ KÈO?
                        </button>
                    </div>
                @endif
                </div>{{-- #signal-body --}}
            </div>

            <!-- Detailed Explanation Overlay (Hidden by default) -->
            <div id="detailed-reason" class="hidden glass-card p-6 border-blue-500/30 bg-blue-900/10 mb-6 animate-in fade-in duration-300">
                <div class="flex justify-between items-start mb-4">
                    <h4 class="text-blue-400 text-xs font-bold uppercase tracking-widest">Phân tích Chuyên sâu SMC</h4>
                    <button onclick="toggleReason()" class="text-slate-500 hover:text-white text-lg">&times;</button>
                </div>
                <div class="space-y-4 text-[11px] leading-relaxed">
                    <div class="bg-black/20 p-3 rounded border border-white/5">
                        <p class="text-blue-300 font-bold mb-1">Cơ sở Kỹ thuật:</p>
                        <ul class="list-disc list-inside space-y-1 text-slate-400">
                            <li>Cấu trúc: <span class="text-white">{{ $analysis['structure']['trend'] }}</span> {{ $analysis['structure']['bos'] ? '(Có BOS)' : '' }}</li>
                            <li>Vùng giá: <span class="text-white">{{ count($analysis['orderBlocks']) }} Order Blocks</span> hoạt động</li>
                            <li>Thanh khoản: <span class="text-white">{{ count($analysis['fvgs']) }} vùng FVG</span> được xác định</li>
                            <li>Lực nến: <span class="text-white">{{ $analysis['indicators']['adx'] > 25 ? 'Mạnh' : ($analysis['indicators']['adx'] > 15 ? 'Trung bình' : 'Yếu') }}</span> (ADX: {{ round($analysis['indicators']['adx'], 1) }})</li>
                        </ul>
                    </div>
                    <div class="bg-black/20 p-3 rounded border border-white/5">
                        <p class="text-green-300 font-bold mb-1">Cách tính điểm Confluence:</p>
                        <p class="text-slate-400">Điểm confluence đo mức độ hội tụ tín hiệu — <span class="text-amber-400">không phải winrate lịch sử</span>. Điểm càng cao = setup càng nhiều xác nhận:</p>
                        <div class="grid grid-cols-2 gap-2 mt-2">
                            <div class="text-[10px] border-r border-white/10 pr-2">
                                <span class="block text-slate-500">Thuận xu hướng</span>
                                <span class="text-white">+30%</span>
                            </div>
                            <div class="text-[10px] pl-2">
                                <span class="block text-slate-500">Chạm vùng SMC OB</span>
                                <span class="text-white">+20%</span>
                            </div>
                            <div class="text-[10px] border-r border-white/10 pr-2 mt-1">
                                <span class="block text-slate-500">Xác nhận FVG</span>
                                <span class="text-white">+15%</span>
                            </div>
                            <div class="text-[10px] pl-2 mt-1">
                                <span class="block text-slate-500">Xác nhận BOS/CHoCH</span>
                                <span class="text-white">+15%</span>
                            </div>
                        </div>
                        @if($analysis['signal'] && ($analysis['signal']['is_counter_trend'] ?? false))
                        <p class="mt-2 text-amber-400 font-bold">- Trừ 20% vì đánh ngược xu hướng chính.</p>
                        @endif
                    </div>
                    @if(!$analysis['signal'])
                    <div class="text-amber-400/80 italic p-2 border-l-2 border-amber-500/50 bg-amber-500/5">
                        Hệ thống chưa tìm thấy sự hội tụ của đủ 3 yếu tố: Vùng giá uy tín + Lực nến đủ mạnh + Cấu trúc rõ ràng. Do đó, tỉ lệ thắng hiện tại dưới 60%, lệnh bị hủy bỏ để đảm bảo an toàn.
                    </div>
                    @endif
                </div>
            </div>

            @if($method === 'elliot' && !empty($analysis['waves']) && !empty($analysis['fibonacci']))
            @php $fib = $analysis['fibonacci']; @endphp
            <div class="glass-card p-3 md:p-4 border border-purple-500/20">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-slate-400 text-xs font-bold uppercase tracking-wider">🌊 Fibonacci Elliott</h3>
                    <span class="text-[10px] px-2 py-0.5 rounded-full font-bold {{ $fib['is_bullish'] ? 'bg-green-500/20 text-green-400' : 'bg-red-500/20 text-red-400' }}">
                        {{ $fib['is_bullish'] ? '▲ TĂNG' : '▼ GIẢM' }}
                    </span>
                </div>
                <div class="grid grid-cols-2 gap-1 text-[10px] mb-3">
                    <div class="text-slate-500">Swing High</div>
                    <div class="text-right font-mono text-red-400">${{ number_format($fib['swing_high'], 4) }}</div>
                    <div class="text-slate-500">Swing Low</div>
                    <div class="text-right font-mono text-green-400">${{ number_format($fib['swing_low'], 4) }}</div>
                </div>
                <div class="text-[9px] text-slate-600 font-bold uppercase mb-1">Retracement</div>
                <div class="space-y-1 mb-3">
                    @foreach($fib['retracement_levels'] as $lvl)
                    <div class="flex justify-between text-[10px]">
                        <span class="text-slate-500">{{ $lvl['ratio'] }}</span>
                        <span class="font-mono {{ $fib['is_bullish'] ? 'text-green-400/80' : 'text-red-400/80' }}">${{ number_format($lvl['price'], 4) }}</span>
                    </div>
                    @endforeach
                </div>
                <div class="text-[9px] text-slate-600 font-bold uppercase mb-1">Extension (Target)</div>
                <div class="space-y-1">
                    @foreach(array_slice($fib['extension_levels'], 0, 3) as $lvl)
                    <div class="flex justify-between text-[10px]">
                        <span class="text-slate-500">{{ $lvl['ratio'] }}</span>
                        <span class="font-mono text-blue-400/80">${{ number_format($lvl['price'], 4) }}</span>
                    </div>
                    @endforeach
                </div>
            </div>
            @endif

            <div class="glass-card p-3 md:p-6">
                <h3 class="text-slate-400 text-xs font-bold uppercase mb-3 md:mb-4 tracking-wider">Phân tích Thị trường</h3>
                <div class="space-y-3 md:space-y-4">
                    <div class="flex justify-between items-center text-sm gap-2">
                        <span class="text-slate-500 flex-shrink-0">Xu hướng</span>
                        <div class="flex flex-col items-end">
                            <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase {{ $analysis['structure']['trend'] == 'TĂNG GIÁ' ? 'bg-green-500/20 text-green-400' : 'bg-red-500/20 text-red-400' }}">
                                {{ $analysis['structure']['trend'] }}
                            </span>
                            @if($analysis['structure']['bos']) <span class="text-[9px] text-blue-400 font-bold mt-1">BOS</span> @endif
                            @if($analysis['structure']['choch']) <span class="text-[9px] text-amber-400 font-bold mt-1">CHoCH</span> @endif
                        </div>
                    </div>
                    <div class="flex justify-between items-center text-sm gap-2">
                        <span class="text-slate-500 flex-shrink-0">HTF</span>
                        <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase {{ $analysis['htf_trend'] == 'TĂNG GIÁ' ? 'bg-green-500/20 text-green-400' : 'bg-red-500/20 text-red-400' }}">
                            {{ $analysis['htf_trend'] }}
                        </span>
                    </div>
                    <div class="flex justify-between items-center text-sm gap-2">
                        <span class="text-slate-500 flex-shrink-0">SMC OB</span>
                        <span class="text-white font-mono text-xs">{{ count($analysis['orderBlocks']) }} vùng</span>
                    </div>
                    <div class="flex justify-between items-center text-sm gap-2">
                        <span class="text-slate-500 flex-shrink-0">SMC FVG</span>
                        <span class="text-amber-400 font-mono text-xs">{{ count($analysis['fvgs']) }} vùng</span>
                    </div>
                    <div class="flex justify-between items-center text-sm gap-2">
                        <span class="text-slate-500 flex-shrink-0">ADX</span>
                        <span class="font-bold {{ $analysis['indicators']['adx'] > 25 ? 'text-green-400' : 'text-slate-500' }}">
                            {{ round($analysis['indicators']['adx'], 1) }}
                        </span>
                    </div>
                    <div class="flex justify-between items-center text-sm gap-2">
                        <span class="text-slate-500 flex-shrink-0">ATR</span>
                        <span class="text-xs text-blue-400 font-mono">{{ number_format($analysis['indicators']['atr'], 2) }}</span>
                    </div>
                    <div class="flex justify-between items-center text-sm gap-2">
                        <span class="text-slate-500 flex-shrink-0">EMA 200</span>
                        <span class="text-xs font-bold {{ $currentPrice > $analysis['indicators']['ema200'] ? 'text-green-400' : 'text-red-400' }}">
                            {{ $currentPrice > $analysis['indicators']['ema200'] ? 'ABOVE' : 'BELOW' }}
                        </span>
                    </div>
                    @if($analysis['signal'] && isset($analysis['signal']['pattern']))
                    <div class="flex justify-between items-center text-sm">
                        <span class="text-slate-500">Mô hình nến</span>
                        <span class="text-amber-400 font-bold uppercase text-[10px]">{{ $analysis['signal']['pattern'] }}</span>
                    </div>
                    @endif
                </div>
            </div>

            <!-- Coin Quality Card -->
            @php
                $cqStatus = $coinQuality['status'] ?? 'CAUTION';
                $cqScore  = $coinQuality['score']  ?? 50;
                $cqColor  = match($cqStatus) {
                    'SAFE'    => ['bar' => 'bg-green-500', 'badge' => 'bg-green-500/20 text-green-400',  'border' => 'border-green-500/20'],
                    'AVOID'   => ['bar' => 'bg-red-500',   'badge' => 'bg-red-500/20 text-red-400',      'border' => 'border-red-500/20'],
                    default   => ['bar' => 'bg-amber-500', 'badge' => 'bg-amber-500/20 text-amber-400',  'border' => 'border-amber-500/20'],
                };
                $cqLabel  = match($cqStatus) { 'SAFE' => 'AN TOÀN', 'AVOID' => 'TRÁNH', default => 'CẨN THẬN' };
            @endphp
            <div class="glass-card p-3 md:p-4 {{ $cqColor['border'] }} border">
                <div class="flex justify-between items-center mb-3">
                    <h3 class="text-slate-400 text-xs font-bold uppercase tracking-wider">Chất lượng Coin</h3>
                    <span class="text-[10px] font-black px-2 py-0.5 rounded-full {{ $cqColor['badge'] }}">{{ $cqLabel }}</span>
                </div>

                <!-- Score bar -->
                <div class="mb-3">
                    <div class="flex justify-between text-[9px] text-slate-600 mb-1">
                        <span>Score</span><span class="font-bold text-slate-400">{{ $cqScore }}/100</span>
                    </div>
                    <div class="h-1.5 bg-white/5 rounded-full overflow-hidden">
                        <div class="{{ $cqColor['bar'] }} h-full rounded-full transition-all" style="width:{{ $cqScore }}%"></div>
                    </div>
                </div>

                <div class="space-y-2">
                    <div class="flex justify-between items-center text-[11px]">
                        <span class="text-slate-500">Volume 24h</span>
                        <span class="font-mono {{ ($coinQuality['volume24h'] ?? 0) >= 10_000_000 ? 'text-green-400' : (($coinQuality['volume24h'] ?? 0) >= 1_000_000 ? 'text-amber-400' : 'text-red-400') }}">
                            ${{ number_format(($coinQuality['volume24h'] ?? 0) / 1_000_000, 1) }}M
                        </span>
                    </div>
                    <div class="flex justify-between items-center text-[11px]">
                        <span class="text-slate-500">Open Interest</span>
                        <span class="font-mono {{ ($coinQuality['oi_usdt'] ?? 0) >= 3_000_000 ? 'text-green-400' : (($coinQuality['oi_usdt'] ?? 0) >= 500_000 ? 'text-amber-400' : 'text-red-400') }}">
                            ${{ number_format(($coinQuality['oi_usdt'] ?? 0) / 1_000_000, 2) }}M
                        </span>
                    </div>
                    <div class="flex justify-between items-center text-[11px]">
                        <span class="text-slate-500">Funding Rate</span>
                        @php $fr = ($coinQuality['funding'] ?? 0) * 100; @endphp
                        <span class="font-mono {{ abs($fr) <= 0.03 ? 'text-green-400' : (abs($fr) <= 0.1 ? 'text-amber-400' : 'text-red-400') }}">
                            {{ $fr >= 0 ? '+' : '' }}{{ number_format($fr, 4) }}%
                        </span>
                    </div>
                </div>

                @if(!empty($coinQuality['flags']))
                <div class="mt-3 space-y-1 border-t border-white/5 pt-2">
                    @foreach($coinQuality['flags'] as $flag)
                    <div class="text-[10px] text-red-400 flex items-start gap-1">
                        <span class="flex-shrink-0 mt-0.5">⚠</span>
                        <span>{{ $flag }}</span>
                    </div>
                    @endforeach
                </div>
                @endif
            </div>
        </div>
    </main>

    <!-- AI Signal History Table -->
    <div class="max-w-[1400px] mx-auto px-3 md:px-4 pb-12">
        <div class="glass-card p-4 md:p-6">
            <div class="mb-4 md:mb-6">
                <!-- Title + action buttons -->
                <div class="flex justify-between items-start gap-3">
                    <div>
                        <h3 class="text-white font-bold text-base md:text-lg">Lịch sử Tín hiệu AI</h3>
                        <p class="text-slate-500 text-xs mt-1 hidden sm:block">Ghi lại kết quả thực tế của các lệnh AI đã đề xuất</p>
                    </div>
                    <div class="flex items-center gap-2 flex-shrink-0">
                        <!-- Bulk Action Button -->
                        <button type="submit" form="bulk-delete-form" class="hidden id-selected-actions text-[10px] bg-amber-500/10 text-amber-400 px-3 py-1.5 rounded-lg border border-amber-500/20 hover:bg-amber-500/20 transition-all uppercase font-bold">
                            Xoá đã chọn
                        </button>

                        <form action="{{ route('signals.clearAll') }}" method="POST" onsubmit="return confirm('Bạn có chắc muốn xoá sạch lịch sử?')">
                            @csrf
                            <button type="submit" class="text-[10px] bg-red-500/10 text-red-400 px-3 py-1.5 rounded-lg border border-red-500/20 hover:bg-red-500/20 transition-all uppercase font-bold">
                                Xoá tất cả
                            </button>
                        </form>
                    </div>
                </div>
                <!-- Coin stats: full-width row below -->
                <div class="flex flex-wrap gap-2 md:gap-3 mt-3">
                    @forelse($coinStats as $stat)
                    <div class="bg-white/5 border border-white/10 rounded-lg px-3 md:px-4 py-2 text-center min-w-[90px]">
                        <p class="text-[10px] text-slate-500 uppercase font-bold mb-1">{{ $stat->symbol }}</p>
                        <p class="text-blue-400 font-bold text-base md:text-lg">
                            {{ $stat->total > 0 ? round(($stat->wins / $stat->total) * 100) : 0 }}%
                        </p>
                        <p class="text-[9px] text-slate-600">{{ $stat->wins }}W - {{ $stat->losses }}L</p>
                    </div>
                    @empty
                    <p class="text-slate-600 text-xs italic mt-1">Chưa đủ dữ liệu thống kê...</p>
                    @endforelse
                </div>
            </div>

            <div class="overflow-x-auto">
                <form id="bulk-delete-form" action="{{ route('signals.bulkDelete') }}" method="POST" onsubmit="return confirm('Xoá các lệnh đã chọn?')">
                    @csrf
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="text-slate-500 text-[10px] uppercase border-b border-white/5">
                                <th class="pb-3 font-medium w-8">
                                    <input type="checkbox" id="select-all" class="rounded border-white/10 bg-white/5 text-blue-500 focus:ring-0">
                                </th>
                                <th class="pb-3 font-medium">Thời gian</th>
                                <th class="pb-3 font-medium">Loại</th>
                                <th class="pb-3 font-medium text-right">Vào / TP / SL</th>
                                <th class="pb-3 font-medium text-center">Trạng thái</th>
                                <th class="pb-3 font-medium hidden md:table-cell">Lý do</th>
                                <th class="pb-3 font-medium text-right">Xoá</th>
                            </tr>
                        </thead>
                        <tbody class="text-sm">
                            @forelse($signals as $signal)
                            <tr class="border-b border-white/5 hover:bg-white/[0.02] transition-colors" data-signal-id="{{ $signal->id }}">
                                <td class="py-3">
                                    <input type="checkbox" name="ids[]" value="{{ $signal->id }}" class="signal-checkbox rounded border-white/10 bg-white/5 text-blue-500 focus:ring-0">
                                </td>
                                <td class="py-3 text-slate-400 text-[10px] whitespace-nowrap">
                                    {{ $signal->created_at->format('H:i') }}<br>
                                    <span class="text-slate-600">{{ $signal->created_at->format('d/m') }}</span>
                                </td>
                                <td class="py-3">
                                    <span class="px-2 py-0.5 rounded text-[10px] font-bold {{ $signal->type == 'LONG' ? 'bg-green-500/20 text-green-400' : 'bg-red-500/20 text-red-400' }}">
                                        {{ $signal->type }}
                                    </span>
                                    <div class="text-[9px] text-slate-600 mt-0.5">{{ $signal->timeframe }}</div>
                                </td>
                                <td class="py-3 text-right">
                                    <div class="font-mono text-white text-[10px]">${{ number_format($signal->entry_price, 2) }}</div>
                                    <div class="text-green-400 text-[9px] font-mono">${{ number_format($signal->tp_price, 2) }}</div>
                                    <div class="text-red-400 text-[9px] font-mono">${{ number_format($signal->sl_price, 2) }}</div>
                                </td>
                                <td class="py-3 text-center signal-status-cell">
                                    @if($signal->status == 'PENDING')
                                        @if($signal->filled_at)
                                            <span class="text-green-400 text-[9px] animate-pulse font-bold block">THEO DÕI</span>
                                            <div class="text-slate-600 text-[8px] mt-0.5">{{ $signal->filled_at->format('H:i d/m') }}</div>
                                        @else
                                            <span class="text-amber-400 text-[9px] font-bold block">CHỜ KHỚP</span>
                                            <div class="text-slate-600 text-[8px] mt-0.5">Bot tự theo dõi</div>
                                        @endif
                                    @elseif($signal->status == 'WIN')
                                        <span class="bg-green-500 text-white text-[9px] px-2 py-0.5 rounded font-bold uppercase">Thắng</span>
                                    @elseif($signal->status == 'CANCELLED')
                                        <span class="bg-slate-500 text-white text-[9px] px-2 py-0.5 rounded font-bold uppercase">Huỷ</span>
                                    @else
                                        <span class="bg-red-500 text-white text-[9px] px-2 py-0.5 rounded font-bold uppercase">Thua</span>
                                    @endif
                                </td>
                                <td class="py-3 text-slate-500 text-xs max-w-[200px] truncate hidden md:table-cell">
                                    {{ $signal->reason }}
                                </td>
                                <td class="py-3 text-right">
                                    @if(in_array($signal->status, ['WIN', 'LOSS', 'CANCELLED']))
                                    <button type="button" onclick="if(confirm('Reset lệnh này về PENDING?')) document.getElementById('reset-form-{{ $signal->id }}').submit();" class="text-slate-500/50 hover:text-amber-400 transition-colors p-1" title="Reset về PENDING">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
                                    </button>
                                    @endif
                                    <button type="button" onclick="event.preventDefault(); if(confirm('Xoá lệnh này?')) document.getElementById('delete-form-{{ $signal->id }}').submit();" class="text-red-500/50 hover:text-red-400 transition-colors p-1">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                                    </button>
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="7" class="py-12 text-center text-slate-600 italic text-sm">
                                    Chưa có dữ liệu. Bấm "ĐỀ XUẤT LỆNH" để bắt đầu.
                                </td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </form>

                <!-- Hidden Individual Delete / Reset Forms -->
                @foreach($signals as $signal)
                <form id="delete-form-{{ $signal->id }}" action="{{ route('signals.delete', $signal->id) }}" method="POST" class="hidden">
                    @csrf
                    @method('DELETE')
                </form>
                <form id="reset-form-{{ $signal->id }}" action="{{ route('signals.reset', $signal->id) }}" method="POST" class="hidden">
                    @csrf
                </form>
                @endforeach
            </div>
        </div>
    </div>

    <!-- ════ PRE-FLIGHT MODAL ════ -->
    <div id="preflight-modal" class="hidden fixed inset-0 bg-black/75 backdrop-blur-sm z-50 flex items-center justify-center p-4">
        <div class="bg-[#141923] border border-blue-500/20 rounded-2xl p-5 max-w-sm w-full shadow-2xl shadow-blue-500/10">
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h3 class="text-white font-bold text-sm">📋 Pre-flight Check</h3>
                    <p class="text-slate-500 text-[10px] mt-0.5">Kiểm tra tâm lý trước khi vào lệnh</p>
                </div>
                <button onclick="closePreflight()" class="text-slate-600 hover:text-slate-400 text-lg leading-none">✕</button>
            </div>

            <!-- 3 câu hỏi -->
            <div class="space-y-3 mb-4">
                <label id="pf-label-1" class="flex items-start gap-3 cursor-pointer group p-2.5 rounded-lg border border-white/5 hover:border-white/10 transition-colors">
                    <input type="checkbox" id="pf-q1" class="mt-0.5 h-4 w-4 rounded border-white/20 bg-white/5 text-blue-500 focus:ring-0 focus:ring-offset-0 cursor-pointer shrink-0">
                    <span class="text-[11px] text-slate-400 leading-relaxed group-hover:text-slate-300 transition-colors">
                        SL tại <code id="pf-sl-price" class="text-red-400 font-mono">—</code> — nếu chạm đây, kịch bản của tôi <strong class="text-white">hoàn toàn bị bác bỏ</strong>?
                    </span>
                </label>
                <label id="pf-label-2" class="flex items-start gap-3 cursor-pointer group p-2.5 rounded-lg border border-white/5 hover:border-white/10 transition-colors">
                    <input type="checkbox" id="pf-q2" class="mt-0.5 h-4 w-4 rounded border-white/20 bg-white/5 text-blue-500 focus:ring-0 focus:ring-offset-0 cursor-pointer shrink-0">
                    <span class="text-[11px] text-slate-400 leading-relaxed group-hover:text-slate-300 transition-colors">
                        Tôi vào lệnh này vì <strong class="text-white">CẤU TRÚC THỊ TRƯỜNG</strong>, không phải vì "hy vọng"?
                    </span>
                </label>
                <label id="pf-label-3" class="flex items-start gap-3 cursor-pointer group p-2.5 rounded-lg border border-white/5 hover:border-white/10 transition-colors">
                    <input type="checkbox" id="pf-q3" class="mt-0.5 h-4 w-4 rounded border-white/20 bg-white/5 text-blue-500 focus:ring-0 focus:ring-offset-0 cursor-pointer shrink-0">
                    <span class="text-[11px] text-slate-400 leading-relaxed group-hover:text-slate-300 transition-colors">
                        Nếu giá đi ngược và tôi thua <strong id="pf-risk-amt" class="text-red-400">2% vốn</strong>, tôi chấp nhận như <strong class="text-white">chi phí vận hành</strong> — không hối tiếc?
                    </span>
                </label>
            </div>

            <!-- Inverse Rule reminder -->
            <div class="bg-slate-900/60 border border-white/[0.06] rounded-xl p-3 mb-4 text-[10px] space-y-1">
                <p class="text-slate-500 font-bold uppercase tracking-wider mb-1.5">💡 Inverse Rule</p>
                <p class="text-green-400/80">↗ <strong>Lệnh lời</strong> → <em>Hy vọng</em> xu hướng đi xa. Chỉ đóng khi cấu trúc đảo chiều.</p>
                <p class="text-red-400/80">↘ <strong>Lệnh lỗ</strong> → <em>Sợ hãi</em>, cắt tại SL. Không nới SL, không trung bình giá xuống.</p>
            </div>

            <!-- Actions -->
            <div class="flex gap-2.5">
                <button onclick="closePreflight()" class="flex-1 text-[11px] text-slate-400 hover:text-white py-2.5 border border-white/10 rounded-xl hover:bg-white/5 transition-all font-semibold">
                    Huỷ
                </button>
                <button id="pf-submit" disabled onclick="submitAfterPreflight()"
                    class="flex-1 text-[11px] font-bold py-2.5 rounded-xl transition-all disabled:opacity-25 disabled:cursor-not-allowed bg-blue-600 text-white hover:bg-blue-500">
                    ✅ Xác nhận vào lệnh
                </button>
            </div>
        </div>
    </div>

    <script>
        // Global state — mutable by SPA engine
        let currentSymbol    = "{{ strtolower($symbol) }}";
        let currentTimeframe = "{{ $timeframe }}";
        let currentMethod    = "{{ $method }}";
        // Legacy aliases (used by chart init code below)
        let symbolLower  = currentSymbol;
        let wsTimeframe  = currentTimeframe;

        // === PRICE FEED — WS primary, HTTP polling fallback ===
        (function initPriceFeed() {
            const priceEl = document.getElementById('current-price-display');
            if (!priceEl) return;

            let ws            = null;
            let lastMsgAt     = 0;
            let pollTimer     = null;
            let wsActive      = false;

            function formatPrice(price) {
                const dec = price < 10 ? 4 : 2;
                return new Intl.NumberFormat('en-US', { minimumFractionDigits: dec, maximumFractionDigits: dec }).format(price);
            }

            function applyPrice(price) {
                const old = parseFloat(priceEl.dataset.lastPrice || price);
                priceEl.style.color     = price >= old ? '#22c55e' : '#ef4444';
                priceEl.textContent     = '$' + formatPrice(price);
                priceEl.dataset.lastPrice = price;
                document.title = `$${formatPrice(price)} ${symbolLower.toUpperCase()} — Felix`;
                lastMsgAt = Date.now();
            }

            // HTTP polling fallback — gọi server khi WS im lặng
            function startPolling() {
                if (pollTimer) return;
                pollTimer = setInterval(async () => {
                    if (wsActive) { stopPolling(); return; }
                    try {
                        const r = await fetch(`/price.json?symbol=${symbolLower}`);
                        if (r.ok) { const d = await r.json(); applyPrice(d.price); }
                    } catch(_) {}
                }, 3000);
            }

            function stopPolling() {
                if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
            }

            function connectWs() {
                if (ws) { ws.onclose = null; ws.onerror = null; try { ws.close(); } catch(_) {} }
                wsActive = false;
                ws = new WebSocket(`wss://fstream.binance.com/ws/${symbolLower}@aggTrade`);

                ws.onopen = () => { wsActive = true; stopPolling(); };

                ws.onmessage = function(e) {
                    wsActive = true;
                    const d = JSON.parse(e.data);
                    applyPrice(parseFloat(d.p));
                };

                ws.onclose = () => { wsActive = false; startPolling(); setTimeout(connectWs, 3000); };
                ws.onerror = () => { wsActive = false; startPolling(); ws.close(); };
            }

            // Watchdog: nếu WS không báo gì trong 8s thì bật polling
            setInterval(() => {
                if (lastMsgAt && Date.now() - lastMsgAt > 8000) { wsActive = false; startPolling(); }
            }, 5000);

            connectWs();

            // Expose để SPA engine gọi khi đổi symbol
            window.__reconnectPriceFeed = function(sym) {
                symbolLower = sym;
                connectWs();
            };
        })();

        // === CHART ===
        document.addEventListener('DOMContentLoaded', function() {
            const chartElement = document.getElementById('chart');
            if (!chartElement) return;

            const rawData = @json($klines);
            const analysis = @json($analysis);

            let candleSeries = null; // declared outside try so kline stream can always access it

            try {
                const chart = LightweightCharts.createChart(chartElement, {
                    autoSize: true,
                    layout: { background: { type: 'solid', color: '#0a0e17' }, textColor: '#94a3b8' },
                    grid: { vertLines: { color: 'rgba(255, 255, 255, 0.05)' }, horzLines: { color: 'rgba(255, 255, 255, 0.05)' } },
                    timeScale: { borderColor: 'rgba(255, 255, 255, 0.1)', timeVisible: true },
                    handleScroll: { mouseWheel: true, pressedMouseMove: true, horzTouchDrag: true },
                    handleScale: { pinch: true, mouseWheel: true },
                });

                candleSeries = chart.addCandlestickSeries({
                    upColor: '#22c55e', downColor: '#ef4444', borderDownColor: '#ef4444', borderUpColor: '#22c55e', wickDownColor: '#ef4444', wickUpColor: '#22c55e',
                });

                const candleData = rawData.map(d => ({
                    time: Math.floor(d[0] / 1000),
                    open: parseFloat(d[1]), high: parseFloat(d[2]), low: parseFloat(d[3]), close: parseFloat(d[4]),
                })).sort((a, b) => a.time - b.time);

                candleSeries.setData(candleData);

                // Expose instances to SPA engine
                if (window.__setChartInstances) window.__setChartInstances(chart, candleSeries);

                // Show chart after 2 animation frames so skeleton is visible during heavy render
                requestAnimationFrame(() => requestAnimationFrame(() => {
                    document.getElementById('chart-skeleton').style.transition = 'opacity 0.3s';
                    document.getElementById('chart-skeleton').style.opacity = '0';
                    document.getElementById('chart').style.display = '';
                    setTimeout(() => { document.getElementById('chart-skeleton').style.display = 'none'; }, 320);
                }));

                // Draw Order Blocks as Price Lines or Rectangles
                analysis.orderBlocks.forEach(ob => {
                    const priceLine = {
                        price: ob.price,
                        color: ob.type === 'demand' ? 'rgba(34, 197, 94, 0.4)' : 'rgba(239, 68, 68, 0.4)',
                        lineWidth: 2,
                        lineStyle: LightweightCharts.LineStyle.Dotted,
                        axisLabelVisible: true,
                        title: ob.label || ob.type.toUpperCase() + ' OB',
                    };
                    candleSeries.createPriceLine(priceLine);
                });

                // Draw POC (Point of Control)
                if (analysis.volumeProfile && analysis.volumeProfile.poc > 0) {
                    candleSeries.createPriceLine({
                        price: analysis.volumeProfile.poc,
                        color: '#f59e0b', // Amber/Gold
                        lineWidth: 2,
                        lineStyle: LightweightCharts.LineStyle.Solid,
                        axisLabelVisible: true,
                        title: 'POC',
                    });
                }

                // Draw FVGs as Price Lines (Simplified)
                if (analysis.method === 'smc') {
                    analysis.fvgs.forEach(f => {
                        candleSeries.createPriceLine({
                            price: f.price,
                            color: f.type === 'BULLISH' ? 'rgba(34, 197, 94, 0.15)' : 'rgba(239, 68, 68, 0.15)',
                            lineWidth: 1,
                            lineStyle: LightweightCharts.LineStyle.Dashed,
                            axisLabelVisible: false,
                            title: 'FVG',
                        });
                    });
                }

                // Draw Elliot Waves (ZigZag) với animation mượt mà
                if (analysis.method === 'elliot' && analysis.waves.length > 0) {
                    // Màu theo loại sóng
                    const impulseLabels  = ['1','3','5'];
                    const correctLabels  = ['2','4'];
                    const abcLabels      = ['A','B','C'];

                    const wavePointColor = (label) => {
                        if (impulseLabels.includes(label)) return '#22c55e';  // Xanh lá — sóng đẩy
                        if (correctLabels.includes(label)) return '#ef4444';  // Đỏ — sóng điều chỉnh
                        return '#a78bfa';                                       // Tím — A-B-C
                    };

                    const waveSeries = chart.addLineSeries({
                        color: 'rgba(245, 158, 11, 0.85)',
                        lineWidth: 2,
                        lineStyle: LightweightCharts.LineStyle.Solid,
                        lastValueVisible: false,
                        priceLineVisible: false,
                        crosshairMarkerVisible: false,
                    });

                    // Sắp xếp theo time để đảm bảo LightweightCharts không lỗi
                    const waveData = analysis.waves
                        .map(w => ({ time: Math.floor(w.time / 1000), value: w.price, label: w.label, type: w.type }))
                        .sort((a, b) => a.time - b.time);

                    // Tập hợp tất cả markers một lần — FIX bug setMarkers trong loop
                    const allMarkers = waveData.map(w => ({
                        time: w.time,
                        position: w.type === 'high' ? 'aboveBar' : 'belowBar',
                        color: wavePointColor(w.label),
                        shape: 'circle',
                        text: w.label,
                        size: abcLabels.includes(w.label) ? 1.5 : 2,
                    }));

                    // Price lines tại các điểm chốt quan trọng
                    waveData.forEach(w => {
                        if (['1','3','5'].includes(w.label)) {
                            candleSeries.createPriceLine({
                                price: w.value,
                                color: 'rgba(34, 197, 94, 0.25)',
                                lineWidth: 1,
                                lineStyle: LightweightCharts.LineStyle.Dashed,
                                axisLabelVisible: true,
                                title: `W${w.label}`,
                            });
                        }
                        if (w.label === 'C') {
                            candleSeries.createPriceLine({
                                price: w.value,
                                color: 'rgba(167, 139, 250, 0.35)',
                                lineWidth: 1,
                                lineStyle: LightweightCharts.LineStyle.Dashed,
                                axisLabelVisible: true,
                                title: 'WC',
                            });
                        }
                    });

                    // Animation: vẽ từng điểm một với delay 80ms
                    let step = 0;
                    function animateWave() {
                        if (step >= waveData.length) {
                            waveSeries.setMarkers(allMarkers);
                            return;
                        }
                        waveSeries.setData(waveData.slice(0, step + 1).map(d => ({ time: d.time, value: d.value })));
                        step++;
                        setTimeout(animateWave, 80);
                    }
                    // Delay nhỏ để chờ candles render xong
                    setTimeout(animateWave, 200);

                    // Draw Fibonacci levels
                    const fib = analysis.fibonacci;
                    if (fib && fib.swing_high) {
                        const isUp = fib.is_bullish;

                        (fib.retracement_levels || []).forEach(level => {
                            candleSeries.createPriceLine({
                                price: level.price,
                                color: isUp ? 'rgba(34, 197, 94, 0.55)' : 'rgba(239, 68, 68, 0.55)',
                                lineWidth: 1,
                                lineStyle: LightweightCharts.LineStyle.Dashed,
                                axisLabelVisible: true,
                                title: 'Fib ' + level.ratio,
                            });
                        });

                        (fib.extension_levels || []).forEach(level => {
                            candleSeries.createPriceLine({
                                price: level.price,
                                color: 'rgba(59, 130, 246, 0.45)',
                                lineWidth: 1,
                                lineStyle: LightweightCharts.LineStyle.Dotted,
                                axisLabelVisible: true,
                                title: 'Ext ' + level.ratio,
                            });
                        });

                        // Projected direction arrow at the last wave point
                        const lastWd = waveData[waveData.length - 1];
                        if (lastWd) {
                            allMarkers.push({
                                time: lastWd.time,
                                position: isUp ? 'belowBar' : 'aboveBar',
                                color: isUp ? '#22c55e' : '#ef4444',
                                shape: isUp ? 'arrowUp' : 'arrowDown',
                                text: isUp ? '▲ Kỳ vọng tăng' : '▼ Kỳ vọng giảm',
                                size: 2,
                            });
                        }
                    }
                }

                chart.timeScale().fitContent();

            } catch (err) {
                console.error("Chart Error:", err);
                // Show error state in skeleton instead of blank
                const sk = document.getElementById('chart-skeleton');
                if (sk) sk.innerHTML = `<div class="h-full flex items-center justify-center"><div class="text-center space-y-3"><div class="text-red-400/60 text-sm">⚠ Không tải được chart</div><a href="/" class="text-blue-400 text-xs underline">← Về trang chủ</a></div></div>`;
            }

            // === KLINE STREAM — outside try-catch, always starts if chart series is ready ===
            if (candleSeries) {
                (function connectKlines() {
                    const ws = new WebSocket(`wss://fstream.binance.com/ws/${symbolLower}@kline_${wsTimeframe}`);
                    ws.onmessage = function(event) {
                        try {
                            const msg = JSON.parse(event.data);
                            if (!msg.k) return; // skip ping/non-kline frames
                            const k = msg.k;
                            candleSeries.update({
                                time: Math.floor(k.t / 1000),
                                open: parseFloat(k.o), high: parseFloat(k.h),
                                low:  parseFloat(k.l), close: parseFloat(k.c),
                            });
                        } catch(e) {}
                    };
                    ws.onclose = () => setTimeout(connectKlines, 3000);
                    ws.onerror  = () => ws.close();
                })();
            }
        });

        function toggleReason() {
            const el = document.getElementById('detailed-reason');
            el.classList.toggle('hidden');
            if (!el.classList.contains('hidden')) {
                el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
        }

        // --- TOP LOADING BAR — shows on navigation/form submit ---
        (function() {
            const bar = document.getElementById('page-loader');
            function startLoad() {
                bar.style.opacity = '1';
                bar.style.width = '75%';
                bar.style.transition = 'width 2.5s ease-out, opacity .15s';
            }
            document.querySelectorAll('form').forEach(f => f.addEventListener('submit', startLoad));
            document.querySelectorAll('a[href]').forEach(a => {
                a.addEventListener('click', function(e) {
                    if (e.ctrlKey || e.metaKey || e.shiftKey) return;
                    const href = this.getAttribute('href');
                    if (!href || href.startsWith('#') || href.startsWith('javascript')) return;
                    startLoad();
                });
            });
            window.addEventListener('beforeunload', function() {
                bar.style.transition = 'width .2s';
                bar.style.width = '100%';
            });
        })();

        // --- WEBSOCKET: Live signal status updates ---
        document.addEventListener('DOMContentLoaded', function() {
            if (window.Echo) {
                window.Echo.channel('signals')
                    .listen('.signal.updated', function(data) {
                        updateSignalRow(data);
                    });
            }
        });

        function updateSignalRow(data) {
            const row = document.querySelector(`tr[data-signal-id="${data.id}"]`);
            if (!row) return;

            const statusCell = row.querySelector('.signal-status-cell');
            if (!statusCell) return;

            if (data.status === 'PENDING' && data.filled_at) {
                statusCell.innerHTML = `<span class="text-green-400 text-[9px] animate-pulse font-bold block">THEO DÕI</span><div class="text-slate-600 text-[8px] mt-0.5">${data.filled_at}</div>`;
            } else if (data.status === 'PENDING') {
                statusCell.innerHTML = `<span class="text-amber-400 text-[9px] font-bold block">CHỜ KHỚP</span><div class="text-slate-600 text-[8px] mt-0.5">Bot tự theo dõi</div>`;
            } else if (data.status === 'WIN') {
                statusCell.innerHTML = `<span class="bg-green-500 text-white text-[9px] px-2 py-0.5 rounded font-bold uppercase">Thắng</span>`;
            } else if (data.status === 'LOSS') {
                statusCell.innerHTML = `<span class="bg-red-500 text-white text-[9px] px-2 py-0.5 rounded font-bold uppercase">Thua</span>`;
            } else if (data.status === 'CANCELLED') {
                statusCell.innerHTML = `<span class="bg-slate-500 text-white text-[9px] px-2 py-0.5 rounded font-bold uppercase">Huỷ</span>`;
            }

            // Flash row to signal update
            row.style.transition = 'background 0.4s';
            row.style.background = 'rgba(59, 130, 246, 0.12)';
            setTimeout(() => { row.style.background = ''; }, 1200);
        }

        // === SPA ENGINE — đổi TF/symbol/method không reload trang ===
        (function initSPA() {
            let priceFeedWs = null;
            let klineWs     = null;
            let chartObj    = null;  // LightweightCharts chart instance
            let candleSeries_ = null;

            // Expose cho chart init code để SPA có thể lấy lại instance
            window.__setChartInstances = function(chart, series) {
                chartObj    = chart;
                candleSeries_ = series;
            };

            function showChartLoading() {
                const sk = document.getElementById('chart-skeleton');
                const ch = document.getElementById('chart');
                if (sk) { sk.style.display = ''; sk.style.opacity = '1'; }
                if (ch) ch.style.display = 'none';
            }

            function hideChartLoading() {
                const sk = document.getElementById('chart-skeleton');
                const ch = document.getElementById('chart');
                if (sk) { sk.style.transition = 'opacity .25s'; sk.style.opacity = '0'; setTimeout(() => sk.style.display = 'none', 260); }
                if (ch) ch.style.display = '';
            }

            function updateActiveButtons() {
                document.querySelectorAll('.tf-btn').forEach(b => {
                    const active = b.dataset.tf === currentTimeframe;
                    b.className = b.className
                        .replace(/bg-blue-600 text-white shadow-lg shadow-blue-500\/20/g, '')
                        .replace(/bg-white\/5 text-slate-400 hover:bg-white\/10/g, '')
                        .trim();
                    b.classList.add(...(active
                        ? ['bg-blue-600','text-white','shadow-lg','shadow-blue-500/20']
                        : ['bg-white/5','text-slate-400','hover:bg-white/10']));
                });
                // Update watchlist bar active state
                document.querySelectorAll('.watchlist-coin').forEach(b => {
                    const active = b.dataset.sym === currentSymbol;
                    b.className = b.className
                        .replace(/bg-blue-600\/20 text-blue-300 border border-blue-500\/40/g, '')
                        .replace(/text-slate-500 hover:text-slate-200 hover:bg-white\/5 border border-transparent/g, '')
                        .trim();
                    b.classList.add(...(active
                        ? ['bg-blue-600/20','text-blue-300','border','border-blue-500/40']
                        : ['text-slate-500','hover:text-slate-200','hover:bg-white/5','border','border-transparent']));
                    const dot = b.querySelector('span');
                    if (dot) { dot.className = dot.className.replace(/bg-blue-400|bg-slate-600|bg-slate-700/g,'').trim() + (active ? ' bg-blue-400' : ' bg-slate-600'); }
                });

                document.querySelectorAll('.method-btn').forEach(b => {
                    const active = b.dataset.method === currentMethod;
                    b.className = b.className
                        .replace(/bg-blue-600 text-white shadow-md/g, '')
                        .replace(/text-slate-500 hover:text-slate-300/g, '')
                        .trim();
                    b.classList.add(...(active ? ['bg-blue-600','text-white','shadow-md'] : ['text-slate-500','hover:text-slate-300']));
                });
            }

            function reconnectPriceFeed(sym) {
                if (priceFeedWs) { priceFeedWs.onclose = null; priceFeedWs.close(); }
                const priceEl = document.getElementById('current-price-display');
                const symDisplay = document.querySelector('[data-last-price]')?.closest?.('div')?.previousElementSibling;
                if (!priceEl) return;

                function connect() {
                    priceFeedWs = new WebSocket(`wss://fstream.binance.com/ws/${sym}@aggTrade`);
                    priceFeedWs.onmessage = function(e) {
                        const d     = JSON.parse(e.data);
                        const price = parseFloat(d.p);
                        const old   = parseFloat(priceEl.dataset.lastPrice || price);
                        const dec   = price < 10 ? 4 : 2;
                        const fmt   = new Intl.NumberFormat('en-US', { minimumFractionDigits: dec, maximumFractionDigits: dec }).format(price);
                        priceEl.style.color = price >= old ? '#22c55e' : '#ef4444';
                        priceEl.textContent = '$' + fmt;
                        priceEl.dataset.lastPrice = price;
                        document.title = `$${fmt} ${sym.toUpperCase()} — TOM AI`;
                    };
                    priceFeedWs.onclose = () => setTimeout(connect, 2000);
                    priceFeedWs.onerror = () => priceFeedWs.close();
                }
                connect();
            }

            function reconnectKlineStream(sym, tf) {
                if (klineWs) { klineWs.onclose = null; klineWs.close(); klineWs = null; }
                if (!candleSeries_) return;

                const tfMap = { '1m':'1m','5m':'5m','15m':'15m','1h':'1h','4h':'4h','1d':'1d' };
                const wsTf  = tfMap[tf] || tf;

                function connect() {
                    klineWs = new WebSocket(`wss://fstream.binance.com/ws/${sym}@kline_${wsTf}`);
                    klineWs.onmessage = function(e) {
                        try {
                            const msg = JSON.parse(e.data);
                            if (!msg.k) return;
                            const k = msg.k;
                            candleSeries_.update({
                                time: Math.floor(k.t / 1000),
                                open: parseFloat(k.o), high: parseFloat(k.h),
                                low:  parseFloat(k.l), close: parseFloat(k.c),
                            });
                        } catch(_) {}
                    };
                    klineWs.onclose = () => setTimeout(connect, 2000);
                    klineWs.onerror = () => klineWs.close();
                }
                connect();
            }

            function renderChart(klines) {
                if (!chartObj || !candleSeries_) return;
                const data = klines.map(d => ({
                    time: Math.floor(d[0] / 1000),
                    open: parseFloat(d[1]), high: parseFloat(d[2]),
                    low:  parseFloat(d[3]), close: parseFloat(d[4]),
                })).sort((a, b) => a.time - b.time);
                candleSeries_.setData(data);
                chartObj.timeScale().fitContent();
            }

            async function navigate(sym, tf, method) {
                if (sym === currentSymbol && tf === currentTimeframe && method === currentMethod) return;

                currentSymbol    = sym;
                currentTimeframe = tf;
                currentMethod    = method;
                symbolLower      = sym;
                wsTimeframe      = tf;

                updateActiveButtons();
                showChartLoading();

                // Update price feed ngay lập tức
                if (window.__reconnectPriceFeed) window.__reconnectPriceFeed(sym);
                else reconnectPriceFeed(sym);

                // Sync tất cả hidden inputs trong forms (propose, capital, v.v.)
                document.querySelectorAll('input[name="symbol"]').forEach(i => i.value = sym.toUpperCase());
                document.querySelectorAll('input[name="timeframe"]').forEach(i => i.value = tf);
                document.querySelectorAll('input[name="method"]').forEach(i => i.value = method);

                // Update URL
                const url = new URL(window.location);
                url.searchParams.set('symbol', sym.toUpperCase());
                url.searchParams.set('timeframe', tf);
                url.searchParams.set('method', method);
                history.pushState({ sym, tf, method }, '', url);

                // Fetch data
                try {
                    const res  = await fetch(`/analysis.json?symbol=${sym}&timeframe=${tf}&method=${method}`);
                    const data = await res.json();
                    if (data.error) { hideChartLoading(); return; }

                    // Update chart
                    renderChart(data.klines);
                    hideChartLoading();
                    reconnectKlineStream(sym, tf);

                    // Update analysis panel
                    updateAnalysisPanel(data);

                    // Update symbol display in header
                    const symDisp = document.querySelector('.text-\\[10px\\].text-slate-500.uppercase');
                    if (symDisp) symDisp.textContent = sym.toUpperCase();

                } catch(err) {
                    hideChartLoading();
                    console.error('SPA navigate error:', err);
                }
            }

            function updateAnalysisPanel(data) {
                const panel   = document.getElementById('analysis-panel');
                if (!panel) return;
                const sig     = data.analysis?.signal;
                const verdict = data.verdict;
                const price   = data.currentPrice;
                const sym     = data.symbol;
                const tf      = data.timeframe;

                if (!sig) {
                    const trend = data.analysis?.structure?.trend ?? 'không rõ';
                    const adx   = (data.analysis?.indicators?.adx ?? 0).toFixed(1);
                    panel.querySelector('#signal-body').innerHTML = `
                        <div class="text-center py-8 text-slate-500">
                            <div class="text-3xl mb-2">📊</div>
                            <p class="text-xs font-bold">Chưa có setup</p>
                            <p class="text-[10px] mt-1">Trend: ${trend} · ADX: ${adx}</p>
                        </div>`;
                    return;
                }

                const isLong   = sig.type?.includes('MUA') || sig.type === 'LONG';
                const slPct    = sig.entry > 0 ? Math.abs(sig.entry - sig.sl) / sig.entry * 100 : 0;
                const tpPct    = sig.entry > 0 ? Math.abs(sig.tp  - sig.entry) / sig.entry * 100 : 0;
                const rr       = slPct > 0 ? (tpPct / slPct).toFixed(1) : '0';
                const color    = sig.is_counter_trend ? 'amber' : (isLong ? 'green' : 'red');
                const colorMap = { green: ['border-green-500/50 bg-green-500/5','bg-green-500/20 text-green-400','text-green-400'],
                                   red:   ['border-red-500/50 bg-red-500/5',    'bg-red-500/20 text-red-400',    'text-red-400'],
                                   amber: ['border-amber-500/50 bg-amber-500/5','bg-amber-500/20 text-amber-400','text-amber-400'] };
                const [cardCls, badgeCls, textCls] = colorMap[color];

                const vDecision  = verdict?.decision ?? 'NEUTRAL';
                const vReasons   = (verdict?.reasons ?? []).join(' · ');
                const verdictMap = { ENTER: 'bg-green-500/20 text-green-300 border-green-500/30', CAUTION: 'bg-amber-500/20 text-amber-300 border-amber-500/30', SKIP: 'bg-red-500/20 text-red-300 border-red-500/30', NEUTRAL: 'bg-white/5 text-slate-400 border-white/10' };
                const vCls       = verdictMap[vDecision] ?? verdictMap.NEUTRAL;

                const aiScore = sig.ai_score ?? null;
                const aiEmoji = aiScore !== null ? (aiScore >= 80 ? '🟢' : aiScore >= 60 ? '🟡' : '🔴') : '';
                const aiHtml  = aiScore !== null ? `
                    <div class="space-y-2 mt-3 pt-3 border-t border-white/[0.06]">
                        <div class="flex items-center justify-between">
                            <span class="text-[10px] text-slate-500 font-bold uppercase">AI Score</span>
                            <span class="text-xs font-bold">${aiEmoji} ${aiScore}/100</span>
                        </div>
                        ${sig.ai_analysis ? `<p class="text-[11px] text-slate-300 leading-relaxed">${sig.ai_analysis}</p>` : ''}
                        ${sig.ai_risk     ? `<p class="text-[11px] text-amber-400/80 leading-relaxed">⚠️ ${sig.ai_risk}</p>` : ''}
                        ${sig.ai_recommendation ? `<p class="text-[11px] text-white font-medium">💡 ${sig.ai_recommendation}</p>` : ''}
                        ${sig.ai_entry_timing   ? `<p class="text-[11px] text-slate-400">⏱ ${sig.ai_entry_timing}</p>` : ''}
                    </div>` : '';

                // Kiểm tra AI có cho vào lệnh không
                const aiRec    = (sig.ai_recommendation ?? '').toUpperCase();
                const canEnter = !aiRec.startsWith('BỎ QUA');
                const proposeBtn = document.querySelector('button[type="submit"].text-blue-400, form [name="propose"] ~ button') ||
                                   document.querySelector('form input[name="propose"]')?.closest('form')?.querySelector('button[type="submit"]');
                if (proposeBtn) {
                    if (canEnter) {
                        proposeBtn.disabled = false;
                        proposeBtn.classList.remove('opacity-40','cursor-not-allowed','bg-red-500/20','text-red-400','border-red-500/30','bg-amber-500/20','text-amber-400','border-amber-500/30');
                        proposeBtn.classList.add('bg-blue-500/20','text-blue-400','border-blue-500/30');
                        proposeBtn.title = '';
                    } else {
                        proposeBtn.disabled = true;
                        proposeBtn.classList.add('opacity-40','cursor-not-allowed');
                        proposeBtn.classList.remove('bg-blue-500/20','text-blue-400','border-blue-500/30');
                        if (aiRec.startsWith('CHỜ RETEST')) {
                            proposeBtn.classList.add('bg-amber-500/20','text-amber-400','border-amber-500/30');
                        } else {
                            proposeBtn.classList.add('bg-red-500/20','text-red-400','border-red-500/30');
                        }
                        proposeBtn.title = `AI: ${sig.ai_recommendation}`;
                    }
                }

                panel.querySelector('#signal-body').innerHTML = `
                    <div class="border rounded-xl p-3 md:p-4 ${cardCls}">
                        <div class="flex items-center justify-between mb-3">
                            <span class="${badgeCls} text-[10px] px-2 py-0.5 rounded-full font-bold uppercase">${sig.type}</span>
                            <span class="${badgeCls} text-[10px] px-2 py-0.5 rounded-full font-bold">${sig.winrate}% Confluence</span>
                        </div>
                        <div class="space-y-1.5 text-xs">
                            <div class="flex justify-between"><span class="text-slate-500">Entry</span><span class="font-mono ${textCls}">$${sig.entry}</span></div>
                            <div class="flex justify-between"><span class="text-slate-500">TP</span><span class="font-mono text-green-400">$${sig.tp} <span class="text-[10px]">(+${tpPct.toFixed(2)}%)</span></span></div>
                            <div class="flex justify-between"><span class="text-slate-500">SL</span><span class="font-mono text-red-400">$${sig.sl} <span class="text-[10px]">(-${slPct.toFixed(2)}%)</span></span></div>
                            <div class="flex justify-between"><span class="text-slate-500">R:R</span><span class="font-mono text-white">1:${rr}</span></div>
                        </div>
                        <div class="mt-3 pt-2 border-t border-white/[0.06]">
                            <div class="border rounded-lg px-3 py-2 ${vCls} text-[10px] font-bold uppercase text-center">${vDecision}${vReasons ? ' — ' + vReasons : ''}</div>
                        </div>
                        ${!canEnter ? `<div class="mt-2 rounded-lg px-3 py-2 bg-red-500/10 text-red-400 border border-red-500/30 text-[10px] font-bold text-center">🚫 AI khuyến nghị BỎ QUA — không đề xuất</div>` : ''}
                        ${aiHtml}
                    </div>`;
            }

            // ── Intercept TF buttons ──
            document.querySelectorAll('.tf-btn').forEach(btn => {
                btn.addEventListener('click', () => navigate(currentSymbol, btn.dataset.tf, currentMethod));
            });

            // ── Intercept method buttons ──
            document.querySelectorAll('.method-btn').forEach(btn => {
                btn.addEventListener('click', () => navigate(currentSymbol, currentTimeframe, btn.dataset.method));
            });

            // ── Intercept symbol autocomplete ──
            document.addEventListener('spa:navigate', e => {
                navigate(e.detail.symbol.toLowerCase(), currentTimeframe, currentMethod);
                document.querySelectorAll('input[name="symbol"]').forEach(i => i.value = e.detail.symbol);
            });

            // ── Browser back/forward ──
            window.addEventListener('popstate', e => {
                if (e.state) navigate(e.state.sym, e.state.tf, e.state.method);
            });

            // ── Auto-refresh analysis panel mỗi 2 phút ──
            async function refreshAnalysis() {
                try {
                    const res  = await fetch(`/analysis.json?symbol=${currentSymbol}&timeframe=${currentTimeframe}&method=${currentMethod}`);
                    const data = await res.json();
                    if (!data.error) updateAnalysisPanel(data);
                } catch(_) {}
            }
            setInterval(refreshAnalysis, 120000);
        })();

        // --- SYMBOL AUTOCOMPLETE ---
        (function() {
            const SYMBOLS = [
                // Majors
                'BTCUSDT','ETHUSDT','BNBUSDT','SOLUSDT','XRPUSDT','ADAUSDT','AVAXUSDT','DOGEUSDT','TRXUSDT','DOTUSDT',
                'MATICUSDT','LINKUSDT','LTCUSDT','UNIUSDT','ATOMUSDT','ETCUSDT','XLMUSDT','NEARUSDT','APTUSDT','ARBUSDT',
                // Mid caps
                'OPUSDT','INJUSDT','SUIUSDT','SEIUSDT','TIAUSDT','WLDUSDT','ORDIUSDT','STXUSDT','MINAUSDT','KASUSDT',
                'RUNEUSDT','FETUSDT','RENDERUSDT','IMXUSDT','SANDUSDT','MANAUSDT','AXSUSDT','GALAUSDT','FTMUSDT','ALGOUSDT',
                'VETUSDT','ICPUSDT','AAVEUSDT','SNXUSDT','MKRUSDT','COMPUSDT','CRVUSDT','1INCHUSDT','LDOUSDT','RPLSUSDT',
                // Metals & others
                'XAGUSDT','XAUUSDT',
                // More futures
                'GMXUSDT','DYDXUSDT','PERPUSDT','BLURUSDT','JOEUSDT','PENDLEUSDT','WIFUSDT','BONKUSDT','PEPEUSDT',
                'FLOKIUSDT','SHIBUSDT','BOMEUSDT','JUPUSDT','PYTHUSDT','WUSDT','ENAUSDT','EIGENUSDT','REZUSDT',
            ];

            document.querySelectorAll('.symbol-autocomplete').forEach(function(wrapper) {
                const input = wrapper.querySelector('input[name="symbol"]');
                const dropdown = wrapper.querySelector('.symbol-dropdown');
                const formId = wrapper.dataset.form;
                const form = document.getElementById(formId);
                let activeIdx = -1;

                function renderDropdown(q) {
                    const filtered = q
                        ? SYMBOLS.filter(s => s.includes(q.toUpperCase())).slice(0, 20)
                        : SYMBOLS.slice(0, 20);

                    if (!filtered.length) { dropdown.classList.add('hidden'); return; }

                    dropdown.innerHTML = filtered.map((s, i) => {
                        const base = s.replace('USDT','');
                        const isActive = s === input.value.toUpperCase();
                        return `<li data-symbol="${s}" data-idx="${i}"
                            class="flex items-center gap-3 px-4 py-2.5 cursor-pointer hover:bg-white/5 transition-colors ${isActive ? 'bg-blue-600/20 text-blue-300' : 'text-slate-200'} text-sm font-mono">
                            <span class="w-6 h-6 rounded-md bg-white/5 flex items-center justify-center text-[9px] font-bold text-slate-400">${base.slice(0,3)}</span>
                            <span class="font-semibold tracking-wide">${s.replace('USDT','')}<span class="text-slate-500 font-normal">/USDT</span></span>
                        </li>`;
                    }).join('');

                    dropdown.querySelectorAll('li').forEach(li => {
                        li.addEventListener('mousedown', function(e) {
                            e.preventDefault();
                            const sym = this.dataset.symbol;
                            input.value = sym;
                            dropdown.classList.add('hidden');
                            document.dispatchEvent(new CustomEvent('spa:navigate', { detail: { symbol: sym } }));
                        });
                    });

                    activeIdx = -1;
                    dropdown.classList.remove('hidden');
                }

                input.addEventListener('focus', function() { renderDropdown(this.value); });
                input.addEventListener('input', function() { renderDropdown(this.value); activeIdx = -1; });
                input.addEventListener('blur', function() { setTimeout(() => dropdown.classList.add('hidden'), 150); });

                input.addEventListener('keydown', function(e) {
                    const items = dropdown.querySelectorAll('li');
                    if (!items.length) return;
                    if (e.key === 'ArrowDown') {
                        e.preventDefault();
                        activeIdx = Math.min(activeIdx + 1, items.length - 1);
                    } else if (e.key === 'ArrowUp') {
                        e.preventDefault();
                        activeIdx = Math.max(activeIdx - 1, 0);
                    } else if (e.key === 'Enter' && activeIdx >= 0) {
                        e.preventDefault();
                        const sym = items[activeIdx].dataset.symbol;
                        input.value = sym;
                        dropdown.classList.add('hidden');
                        document.dispatchEvent(new CustomEvent('spa:navigate', { detail: { symbol: sym } }));
                        return;
                    } else { return; }
                    items.forEach((li, i) => li.classList.toggle('bg-white/10', i === activeIdx));
                    items[activeIdx]?.scrollIntoView({ block: 'nearest' });
                });
            });
        })();

        // --- CHECKBOX & BULK DELETE LOGIC ---
        document.addEventListener('DOMContentLoaded', function() {
            const selectAll = document.getElementById('select-all');
            const checkboxes = document.querySelectorAll('.signal-checkbox');
            const bulkBtn = document.querySelector('.id-selected-actions');

            if (selectAll) {
                selectAll.addEventListener('change', function() {
                    checkboxes.forEach(cb => {
                        cb.checked = selectAll.checked;
                    });
                    toggleBulkBtn();
                });
            }

            checkboxes.forEach(cb => {
                cb.addEventListener('change', toggleBulkBtn);
            });

            function toggleBulkBtn() {
                const checkedCount = document.querySelectorAll('.signal-checkbox:checked').length;
                if (checkedCount > 0) {
                    bulkBtn.classList.remove('hidden');
                    bulkBtn.textContent = `Xoá ${checkedCount} mục đã chọn`;
                } else {
                    bulkBtn.classList.add('hidden');
                }
            }
        });

        // --- TRADE ADVISOR MODAL ---
        (function() {
            const btn     = document.getElementById('open-advisor-btn');
            const modal   = document.getElementById('advisor-modal');
            const overlay = document.getElementById('advisor-overlay');
            const form    = document.getElementById('advisor-form');
            const result  = document.getElementById('advisor-result');
            const spinner = document.getElementById('advisor-spinner');

            function openModal() {
                modal.classList.remove('hidden');
                overlay.classList.remove('hidden');
                document.getElementById('advisor-entry').focus();
            }
            function closeModal() {
                modal.classList.add('hidden');
                overlay.classList.add('hidden');
                result.innerHTML = '';
                form.reset();
            }

            btn?.addEventListener('click', openModal);
            overlay?.addEventListener('click', closeModal);
            document.getElementById('advisor-close')?.addEventListener('click', closeModal);
            document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });

            form?.addEventListener('submit', async function(e) {
                e.preventDefault();
                result.innerHTML = '';
                spinner.classList.remove('hidden');

                const data = {
                    symbol   : "{{ $symbol }}",
                    timeframe: "{{ $timeframe }}",
                    entry    : document.getElementById('advisor-entry').value,
                    type     : document.getElementById('advisor-type').value,
                    sl       : document.getElementById('advisor-sl').value || null,
                    tp       : document.getElementById('advisor-tp').value || null,
                };

                try {
                    const res  = await fetch('/advisor', {
                        method : 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                        body   : JSON.stringify(data),
                    });
                    const json = await res.json();
                    spinner.classList.add('hidden');

                    if (!res.ok) { result.innerHTML = `<p class="text-red-400 text-xs">${json.error || 'Lỗi không xác định'}</p>`; return; }

                    const verdictColor = {
                        'GIỮ LỆNH': 'green', 'CHỐT LỜI': 'blue',
                        'CẮT LỖ': 'red', 'DI CHUYỂN SL': 'amber', 'ĐIỀU CHỈNH': 'amber',
                    };
                    const c = Object.entries(verdictColor).find(([k]) => json.verdict?.toUpperCase().includes(k))?.[1] ?? 'slate';
                    const colorMap = {
                        green: 'bg-green-500/10 border-green-500/30 text-green-400',
                        blue : 'bg-blue-500/10 border-blue-500/30 text-blue-400',
                        red  : 'bg-red-500/10 border-red-500/30 text-red-400',
                        amber: 'bg-amber-500/10 border-amber-500/30 text-amber-400',
                        slate: 'bg-white/5 border-white/10 text-slate-300',
                    };

                    result.innerHTML = `
                        <div class="space-y-3 mt-4">
                            <div class="border rounded-xl p-3 ${colorMap[c]}">
                                <div class="text-[10px] font-bold uppercase tracking-widest mb-1">Phán quyết</div>
                                <div class="text-sm font-bold">${json.verdict ?? '—'}</div>
                            </div>
                            <div class="bg-white/5 border border-white/10 rounded-xl p-3">
                                <div class="text-[10px] text-slate-400 font-bold uppercase tracking-widest mb-1">Phân tích</div>
                                <p class="text-[11px] text-slate-200 leading-relaxed">${json.analysis ?? '—'}</p>
                            </div>
                            ${json.sl_advice ? `<div class="bg-red-500/5 border border-red-500/20 rounded-xl p-3">
                                <div class="text-[10px] text-red-400 font-bold uppercase tracking-widest mb-1">Khuyến nghị SL</div>
                                <p class="text-[11px] text-slate-200">${json.sl_advice}</p>
                            </div>` : ''}
                            ${json.tp_advice ? `<div class="bg-green-500/5 border border-green-500/20 rounded-xl p-3">
                                <div class="text-[10px] text-green-400 font-bold uppercase tracking-widest mb-1">Khuyến nghị TP</div>
                                <p class="text-[11px] text-slate-200">${json.tp_advice}</p>
                            </div>` : ''}
                            <div class="text-[10px] text-slate-500 text-right">Giá hiện tại: $${json.current_price ?? '—'}</div>
                        </div>`;
                } catch (err) {
                    spinner.classList.add('hidden');
                    result.innerHTML = `<p class="text-red-400 text-xs">Lỗi kết nối: ${err.message}</p>`;
                }
            });
        })();
    </script>

    {{-- Trade Advisor Modal --}}
    <div id="advisor-overlay" class="hidden fixed inset-0 bg-black/60 backdrop-blur-sm z-40"></div>
    <div id="advisor-modal" class="hidden fixed inset-0 z-50 flex items-center justify-center p-4">
        <div class="bg-[#111827] border border-white/10 rounded-2xl shadow-2xl w-full max-w-md">
            <div class="flex items-center justify-between px-5 pt-5 pb-4 border-b border-white/[0.06]">
                <div class="flex items-center gap-2">
                    <div class="w-7 h-7 rounded-lg bg-purple-500/20 flex items-center justify-center">
                        <svg class="w-4 h-4 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.347.347A3.75 3.75 0 0112 18.75a3.75 3.75 0 01-2.652-1.1l-.347-.347z"/></svg>
                    </div>
                    <div>
                        <div class="text-sm font-bold text-white">Tư vấn lệnh đang mở</div>
                        <div class="text-[10px] text-slate-500">{{ $symbol }} · {{ strtoupper($timeframe) }}</div>
                    </div>
                </div>
                <button id="advisor-close" class="text-slate-500 hover:text-white transition-colors">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            <form id="advisor-form" class="px-5 py-4 space-y-3">
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-[10px] text-slate-400 font-bold uppercase mb-1">Loại lệnh *</label>
                        <select id="advisor-type" required
                            class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-sm text-white focus:outline-none focus:border-purple-500/50">
                            <option value="LONG">LONG (Mua)</option>
                            <option value="SHORT">SHORT (Bán)</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[10px] text-slate-400 font-bold uppercase mb-1">Giá vào lệnh *</label>
                        <input id="advisor-entry" type="number" step="any" required placeholder="0.00"
                            class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-sm text-white font-mono focus:outline-none focus:border-purple-500/50 placeholder:text-slate-600">
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-[10px] text-slate-400 font-bold uppercase mb-1">Stop Loss</label>
                        <input id="advisor-sl" type="number" step="any" placeholder="tuỳ chọn"
                            class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-sm text-white font-mono focus:outline-none focus:border-red-500/30 placeholder:text-slate-600">
                    </div>
                    <div>
                        <label class="block text-[10px] text-slate-400 font-bold uppercase mb-1">Take Profit</label>
                        <input id="advisor-tp" type="number" step="any" placeholder="tuỳ chọn"
                            class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-sm text-white font-mono focus:outline-none focus:border-green-500/30 placeholder:text-slate-600">
                    </div>
                </div>

                <button type="submit"
                    class="w-full bg-purple-600 hover:bg-purple-500 text-white text-sm font-bold py-2.5 rounded-xl transition-all mt-1">
                    Phân tích lệnh
                </button>
            </form>

            <div id="advisor-spinner" class="hidden px-5 pb-4 flex items-center justify-center gap-2 text-slate-400 text-xs">
                <svg class="w-4 h-4 animate-spin text-purple-400" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/>
                </svg>
                AI đang phân tích lệnh của bạn...
            </div>

            <div id="advisor-result" class="px-5 pb-5"></div>
        </div>
    </div>

    <script>
    // ════ PRE-FLIGHT MODAL ════
    (function() {
        const modal      = document.getElementById('preflight-modal');
        const submitBtn  = document.getElementById('pf-submit');
        const checkboxes = ['pf-q1','pf-q2','pf-q3'].map(id => document.getElementById(id));
        let   pendingForm = null;

        function updateSubmitState() {
            const allChecked = checkboxes.every(cb => cb && cb.checked);
            submitBtn.disabled = !allChecked;
            if (allChecked) {
                submitBtn.classList.remove('opacity-25','cursor-not-allowed');
            } else {
                submitBtn.classList.add('opacity-25','cursor-not-allowed');
            }
            // Visual feedback per label
            checkboxes.forEach((cb, i) => {
                const label = document.getElementById('pf-label-' + (i + 1));
                if (!label) return;
                if (cb && cb.checked) {
                    label.classList.add('border-blue-500/30','bg-blue-500/5');
                    label.classList.remove('border-white/5');
                } else {
                    label.classList.remove('border-blue-500/30','bg-blue-500/5');
                    label.classList.add('border-white/5');
                }
            });
        }

        checkboxes.forEach(cb => cb && cb.addEventListener('change', updateSubmitState));

        window.openPreflight = function(form) {
            pendingForm = form;

            // Lấy SL price và risk amount từ form data-attributes
            const slPrice = form.dataset.sl || '—';
            const capital = parseFloat(document.getElementById('capital-input')?.value || 0);
            const riskAmt = capital > 0 ? '$' + (capital * 0.02).toFixed(2) : '2% vốn';

            document.getElementById('pf-sl-price').textContent = slPrice;
            document.getElementById('pf-risk-amt').textContent = riskAmt;

            // Reset checkboxes
            checkboxes.forEach(cb => { if (cb) cb.checked = false; });
            checkboxes.forEach((cb, i) => {
                const label = document.getElementById('pf-label-' + (i + 1));
                if (label) { label.classList.remove('border-blue-500/30','bg-blue-500/5'); label.classList.add('border-white/5'); }
            });
            updateSubmitState();

            modal.classList.remove('hidden');
            modal.classList.add('flex');
        };

        window.closePreflight = function() {
            modal.classList.add('hidden');
            modal.classList.remove('flex');
            pendingForm = null;
        };

        window.submitAfterPreflight = function() {
            if (pendingForm) {
                modal.classList.add('hidden');
                modal.classList.remove('flex');
                pendingForm.submit();
            }
        };

        // Đóng khi click overlay
        modal.addEventListener('click', function(e) {
            if (e.target === modal) closePreflight();
        });

        // Intercept propose form
        const proposeForm = document.getElementById('propose-form');
        if (proposeForm) {
            proposeForm.addEventListener('submit', function(e) {
                // Chỉ intercept nếu có tín hiệu (sl > 0)
                if (this.dataset.sl && parseFloat(this.dataset.sl) > 0) {
                    e.preventDefault();
                    openPreflight(this);
                }
                // Nếu không có signal (sl = ''), cho submit bình thường
            });
        }
    })();
    </script>
</body>
</html>
