<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tom AI - Price Action Terminal</title>
    <meta http-equiv="refresh" content="60">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="{{ asset('css/dashboard.css') }}">
    <script src="https://unpkg.com/lightweight-charts@4.1.1/dist/lightweight-charts.standalone.production.js"></script>
</head>
<body class="bg-[#0a0e17] text-white">
    <!-- Header -->
    <header class="p-6 border-b border-white/10 flex justify-between items-center bg-[#0a0e17]/80 sticky top-0 z-50 backdrop-blur-md">
        <div class="flex items-center space-x-4">
            <div class="text-2xl font-bold bg-gradient-to-r from-blue-500 to-cyan-400 bg-clip-text text-transparent">
                TOM AI <span class="text-xs font-normal text-slate-500">v1.0</span>
            </div>
            <div class="flex items-center text-sm text-slate-400">
                <span class="indicator-dot dot-online"></span> Market Online
            </div>
            <a href="/academy" class="ml-4 px-3 py-1 bg-purple-500/10 text-purple-400 text-[10px] font-bold rounded-lg border border-purple-500/20 hover:bg-purple-500/20 transition-all flex items-center space-x-1">
                <span class="w-1.5 h-1.5 bg-purple-500 rounded-full animate-pulse"></span>
                <span>TOM ACADEMY</span>
            </a>
            <a href="/planner" class="ml-2 px-3 py-1 bg-blue-500/10 text-blue-400 text-[10px] font-bold rounded-lg border border-blue-500/20 hover:bg-blue-500/20 transition-all flex items-center space-x-1">
                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path></svg>
                <span>KẾ HOẠCH</span>
            </a>
        </div>
        
        <div class="flex items-center space-x-6">
            <!-- Symbol Search -->
            <form action="/" method="GET" class="relative group">
                <input type="text" name="symbol" value="{{ $symbol }}" 
                    class="bg-white/5 border border-white/10 rounded-lg px-4 py-1.5 text-sm focus:outline-none focus:border-blue-500/50 w-32 transition-all group-hover:w-48 font-mono uppercase"
                    placeholder="Search Symbol...">
                <button type="submit" class="absolute right-3 top-2 text-slate-500 hover:text-blue-400">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                </button>
            </form>

            <div class="text-right">
                <div class="text-xs text-slate-500 uppercase">Current {{ $symbol }}</div>
                <div id="current-price-display" class="text-xl font-mono text-green-400" data-last-price="{{ $currentPrice }}">
                    ${{ number_format($currentPrice, 2) }}
                </div>
            </div>
            <div class="h-10 w-px bg-white/10"></div>
            <button class="bg-blue-600 hover:bg-blue-500 px-6 py-2 rounded-lg font-semibold transition-all">
                Connect API
            </button>
        </div>
    </header>

    <main class="trading-container">
        <!-- Chart Section -->
        <div class="glass-card p-4">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-lg font-semibold flex items-center">
                    <svg class="w-5 h-5 mr-2 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 12l3-3 3 3 4-4M8 21l4-4 4 4M3 4h18M4 4h16v12a1 1 0 01-1 1H5a1 1 0 01-1-1V4z"></path></svg>
                    Price Action Terminal
                </h2>
                <div class="flex space-x-2">
                    <!-- Method Toggle -->
                    <div class="flex bg-white/5 p-1 rounded-lg mr-4">
                        <a href="{{ request()->fullUrlWithQuery(['method' => 'smc']) }}" class="px-3 py-1 rounded-md text-[10px] font-bold transition-all {{ ($method ?? 'smc') == 'smc' ? 'bg-blue-600 text-white shadow-md' : 'text-slate-500 hover:text-slate-300' }}">
                            SMC
                        </a>
                        <a href="{{ request()->fullUrlWithQuery(['method' => 'elliot']) }}" class="px-3 py-1 rounded-md text-[10px] font-bold transition-all {{ ($method ?? 'smc') == 'elliot' ? 'bg-blue-600 text-white shadow-md' : 'text-slate-500 hover:text-slate-300' }}">
                            ELLIOT
                        </a>
                    </div>

                    <a href="/?symbol={{ $symbol }}&timeframe=15m" class="px-4 py-1.5 rounded-lg text-xs font-bold transition-all {{ $timeframe == '15m' ? 'bg-blue-600 text-white shadow-lg shadow-blue-500/20' : 'bg-white/5 text-slate-400 hover:bg-white/10' }}">
                        SCALP M15
                    </a>
                    <a href="/?symbol={{ $symbol }}&timeframe=1h" class="px-4 py-1.5 rounded-lg text-xs font-bold transition-all {{ $timeframe == '1h' ? 'bg-blue-600 text-white shadow-lg shadow-blue-500/20' : 'bg-white/5 text-slate-400 hover:bg-white/10' }}">
                        INTRADAY H1
                    </a>
                    <a href="/?symbol={{ $symbol }}&timeframe=4h" class="px-4 py-1.5 rounded-lg text-xs font-bold transition-all {{ $timeframe == '4h' ? 'bg-blue-600 text-white shadow-lg shadow-blue-500/20' : 'bg-white/5 text-slate-400 hover:bg-white/10' }}">
                        SWING H4
                    </a>
                </div>
            </div>
            <div id="chart" class="chart-container"></div>
        </div>

        <!-- Sidebar / Signals -->
        <div class="space-y-6">
            <div class="glass-card p-6">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-slate-400 text-xs font-bold uppercase tracking-wider">Dự đoán Vào lệnh AI</h3>
                    <a href="{{ request()->fullUrlWithQuery(['propose' => 1]) }}" class="text-[10px] bg-blue-500/20 text-blue-400 px-2 py-1 rounded border border-blue-500/30 hover:bg-blue-500/30 transition-all">
                        ĐỀ XUẤT LỆNH
                    </a>
                </div>
                
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
                            <span class="{{ $textColor }} font-bold text-lg">
                                Lệnh {{ $analysis['signal']['type'] }}
                                @if($isCounter) <span class="text-[10px] ml-1 px-1 rounded bg-amber-500/20">RỦI RO</span> @endif
                            </span>
                            <span class="{{ $badgeColor }} text-[10px] px-2 py-0.5 rounded-full uppercase font-bold mr-10">{{ $analysis['signal']['winrate'] }}% Tỉ lệ Thắng</span>
                        </div>
                        <div class="space-y-2 text-sm">
                            <div class="flex justify-between"><span class="text-slate-500">Điểm vào</span> <span class="font-mono text-white">${{ number_format($analysis['signal']['entry'], 2) }}</span></div>
                            <div class="flex justify-between"><span class="text-slate-500">Chốt lời</span> <span class="font-mono text-green-400">${{ number_format($analysis['signal']['tp'], 2) }}</span></div>
                            <div class="flex justify-between"><span class="text-slate-500">Cắt lỗ</span> <span class="font-mono text-red-400">${{ number_format($analysis['signal']['sl'], 2) }}</span></div>
                        </div>
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
                        <p class="text-green-300 font-bold mb-1">Cách tính Tỉ lệ Thắng (Winrate):</p>
                        <p class="text-slate-400">Tỉ lệ thắng được AI tính toán dựa trên trọng số điểm:</p>
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

            <div class="glass-card p-6">
                <h3 class="text-slate-400 text-xs font-bold uppercase mb-4 tracking-wider">Phân tích Thị trường</h3>
                <div class="space-y-4">
                    <div class="flex justify-between items-center text-sm">
                        <span class="text-slate-500">Xu hướng Hiện tại</span>
                        <div class="flex flex-col items-end">
                            <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase {{ $analysis['structure']['trend'] == 'TĂNG GIÁ' ? 'bg-green-500/20 text-green-400' : 'bg-red-500/20 text-red-400' }}">
                                {{ $analysis['structure']['trend'] }}
                            </span>
                            @if($analysis['structure']['bos']) <span class="text-[9px] text-blue-400 font-bold mt-1">BOS DETECTED</span> @endif
                            @if($analysis['structure']['choch']) <span class="text-[9px] text-amber-400 font-bold mt-1">CHoCH DETECTED</span> @endif
                        </div>
                    </div>
                    <div class="flex justify-between items-center text-sm">
                        <span class="text-slate-500">Xác nhận Khung lớn (HTF)</span>
                        <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase {{ $analysis['htf_trend'] == 'TĂNG GIÁ' ? 'bg-green-500/20 text-green-400' : 'bg-red-500/20 text-red-400' }}">
                            {{ $analysis['htf_trend'] }}
                        </span>
                    </div>
                    <div class="flex justify-between items-center text-sm">
                        <span class="text-slate-500">Vùng SMC OB</span>
                        <span class="text-white font-mono">Phát hiện {{ count($analysis['orderBlocks']) }} vùng</span>
                    </div>
                    <div class="flex justify-between items-center text-sm">
                        <span class="text-slate-500">Vùng SMC FVG</span>
                        <span class="text-amber-400 font-mono">{{ count($analysis['fvgs']) }} vùng trống</span>
                    </div>
                    <div class="flex justify-between items-center text-sm">
                        <span class="text-slate-500">Lực xu hướng (ADX)</span>
                        <span class="font-bold {{ $analysis['indicators']['adx'] > 25 ? 'text-green-400' : 'text-slate-500' }}">
                            {{ round($analysis['indicators']['adx'], 1) }}
                        </span>
                    </div>
                    <div class="flex justify-between items-center text-sm">
                        <span class="text-slate-500">Biến động (ATR)</span>
                        <span class="text-xs text-blue-400">
                            {{ number_format($analysis['indicators']['atr'], 2) }}
                        </span>
                    </div>
                    <div class="flex justify-between items-center text-sm">
                        <span class="text-slate-500">EMA 200</span>
                        <span class="text-xs {{ $currentPrice > $analysis['indicators']['ema200'] ? 'text-green-400' : 'text-red-400' }}">
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
        </div>
    </main>

    <!-- AI Signal History Table -->
    <div class="max-w-[1400px] mx-auto px-4 pb-12">
        <div class="glass-card p-6">
            <div class="flex justify-between items-center mb-6">
                <div>
                    <h3 class="text-white font-bold text-lg">Lịch sử Tín hiệu AI</h3>
                    <p class="text-slate-500 text-xs mt-1">Ghi lại kết quả thực tế của các lệnh AI đã đề xuất</p>
                </div>
                <div class="flex items-center gap-4">
                    <!-- Bulk Action Button -->
                    <button type="submit" form="bulk-delete-form" class="hidden id-selected-actions text-[10px] bg-amber-500/10 text-amber-400 px-3 py-1.5 rounded-lg border border-amber-500/20 hover:bg-amber-500/20 transition-all uppercase font-bold">
                        Xoá mục đã chọn
                    </button>

                    <form action="{{ route('signals.clearAll') }}" method="POST" onsubmit="return confirm('Bạn có chắc muốn xoá sạch lịch sử?')">
                        @csrf
                        <button type="submit" class="text-[10px] bg-red-500/10 text-red-400 px-3 py-1.5 rounded-lg border border-red-500/20 hover:bg-red-500/20 transition-all uppercase font-bold">
                            Làm sạch tất cả
                        </button>
                    </form>
                    <div class="flex flex-wrap gap-4">
                        @forelse($coinStats as $stat)
                        <div class="bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-center min-w-[120px]">
                            <p class="text-[10px] text-slate-500 uppercase font-bold mb-1">{{ $stat->symbol }}</p>
                            <p class="text-blue-400 font-bold text-lg">
                                {{ $stat->total > 0 ? round(($stat->wins / $stat->total) * 100) : 0 }}%
                            </p>
                            <p class="text-[9px] text-slate-600">{{ $stat->wins }}W - {{ $stat->losses }}L</p>
                        </div>
                        @empty
                        <p class="text-slate-600 text-xs italic">Chưa đủ dữ liệu thống kê theo Coin...</p>
                        @endforelse
                    </div>
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
                                <th class="pb-3 font-medium text-right">Điểm vào</th>
                                <th class="pb-3 font-medium text-right">TP / SL</th>
                                <th class="pb-3 font-medium text-center">Trạng thái</th>
                                <th class="pb-3 font-medium">Lý do phân tích</th>
                                <th class="pb-3 font-medium text-right">Xoá</th>
                            </tr>
                        </thead>
                        <tbody class="text-sm">
                            @forelse($signals as $signal)
                            <tr class="border-b border-white/5 hover:bg-white/[0.02] transition-colors">
                                <td class="py-4">
                                    <input type="checkbox" name="ids[]" value="{{ $signal->id }}" class="signal-checkbox rounded border-white/10 bg-white/5 text-blue-500 focus:ring-0">
                                </td>
                                <td class="py-4 text-slate-400 text-xs">
                                    {{ $signal->created_at->format('H:i d/m') }}
                                </td>
                                <td class="py-4">
                                    <span class="px-2 py-0.5 rounded text-[10px] font-bold {{ $signal->type == 'LONG' ? 'bg-green-500/20 text-green-400' : 'bg-red-500/20 text-red-400' }}">
                                        {{ $signal->type }}
                                    </span>
                                    <span class="text-[10px] text-slate-600 ml-1">{{ $signal->timeframe }}</span>
                                </td>
                                <td class="py-4 text-right font-mono text-white">
                                    ${{ number_format($signal->entry_price, 2) }}
                                </td>
                                <td class="py-4 text-right">
                                    <div class="text-green-400 text-[10px] font-mono">${{ number_format($signal->tp_price, 2) }}</div>
                                    <div class="text-red-400 text-[10px] font-mono">${{ number_format($signal->sl_price, 2) }}</div>
                                </td>
                                <td class="py-4 text-center">
                                    @if($signal->status == 'PENDING')
                                        <span class="text-blue-400 text-[10px] animate-pulse">ĐANG THEO DÕI...</span>
                                    @elseif($signal->status == 'WIN')
                                        <span class="bg-green-500 text-white text-[10px] px-2 py-0.5 rounded font-bold uppercase">Thắng 🚀</span>
                                    @else
                                        <span class="bg-red-500 text-white text-[10px] px-2 py-0.5 rounded font-bold uppercase">Thua 💀</span>
                                    @endif
                                </td>
                                <td class="py-4 text-slate-500 text-xs max-w-xs truncate">
                                    {{ $signal->reason }}
                                </td>
                                <td class="py-4 text-right">
                                    <button type="button" onclick="event.preventDefault(); if(confirm('Xoá lệnh này?')) document.getElementById('delete-form-{{ $signal->id }}').submit();" class="text-red-500/50 hover:text-red-400 transition-colors">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                                    </button>
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="8" class="py-12 text-center text-slate-600 italic">
                                    Chưa có dữ liệu lịch sử lệnh. Bấm "ĐỀ XUẤT LỆNH" để bắt đầu ghi lại.
                                </td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </form>

                <!-- Hidden Individual Delete Forms -->
                @foreach($signals as $signal)
                <form id="delete-form-{{ $signal->id }}" action="{{ route('signals.delete', $signal->id) }}" method="POST" class="hidden">
                    @csrf
                    @method('DELETE')
                </form>
                @endforeach
            </div>
        </div>
    </div>


    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const chartElement = document.getElementById('chart');
            if (!chartElement) return;

            const rawData = @json($klines);
            const analysis = @json($analysis);

            try {
                const chart = LightweightCharts.createChart(chartElement, {
                    width: chartElement.clientWidth,
                    height: chartElement.clientHeight,
                    layout: { background: { type: 'solid', color: '#0a0e17' }, textColor: '#94a3b8' },
                    grid: { vertLines: { color: 'rgba(255, 255, 255, 0.05)' }, horzLines: { color: 'rgba(255, 255, 255, 0.05)' } },
                    timeScale: { borderColor: 'rgba(255, 255, 255, 0.1)', timeVisible: true },
                });

                const candleSeries = chart.addCandlestickSeries({
                    upColor: '#22c55e', downColor: '#ef4444', borderDownColor: '#ef4444', borderUpColor: '#22c55e', wickDownColor: '#ef4444', wickUpColor: '#22c55e',
                });

                const candleData = rawData.map(d => ({
                    time: Math.floor(d[0] / 1000),
                    open: parseFloat(d[1]), high: parseFloat(d[2]), low: parseFloat(d[3]), close: parseFloat(d[4]),
                })).sort((a, b) => a.time - b.time);

                candleSeries.setData(candleData);

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

                // Draw Elliot Waves (ZigZag)
                if (analysis.method === 'elliot' && analysis.waves.length > 0) {
                    const waveSeries = chart.addLineSeries({
                        color: '#f59e0b',
                        lineWidth: 2,
                        lineStyle: LightweightCharts.LineStyle.Solid,
                    });
                    
                    const waveData = analysis.waves.map(w => ({
                        time: w.time / 1000,
                        value: w.price
                    }));
                    waveSeries.setData(waveData);

                    // Add Labels for waves
                    analysis.waves.forEach(w => {
                        waveSeries.setMarkers([{
                            time: w.time / 1000,
                            position: w.type === 'high' ? 'aboveBar' : 'belowBar',
                            color: '#f59e0b',
                            shape: 'circle',
                            text: w.label,
                            size: 1.5
                        }]);
                    });
                }

                chart.timeScale().fitContent();

                // --- REALTIME WEBSOCKET INTEGRATION ---
                const symbolLower = "{{ strtolower($symbol) }}";
                const timeframe = "{{ $timeframe }}";
                
                // Stream nến cho biểu đồ
                const socketKline = new WebSocket(`wss://fstream.binance.com/ws/${symbolLower}@kline_${timeframe}`);
                socketKline.onmessage = function(event) {
                    const message = JSON.parse(event.data);
                    const k = message.k;
                    candleSeries.update({
                        time: k.t / 1000,
                        open: parseFloat(k.o), high: parseFloat(k.h), low: parseFloat(k.l), close: parseFloat(k.c),
                    });
                };

                // Stream ticker cho giá header (nhảy liên tục)
                const socketTicker = new WebSocket(`wss://fstream.binance.com/ws/${symbolLower}@ticker`);
                socketTicker.onmessage = function(event) {
                    const data = JSON.parse(event.data);
                    const priceElement = document.getElementById('current-price-display');
                    if (priceElement) {
                        const price = parseFloat(data.c);
                        const oldPrice = parseFloat(priceElement.dataset.lastPrice || 0);
                        const formattedPrice = new Intl.NumberFormat('en-US', { minimumFractionDigits: 2 }).format(price);
                        
                        priceElement.style.color = price >= oldPrice ? '#22c55e' : '#ef4444';
                        priceElement.innerText = `$${formattedPrice}`;
                        priceElement.dataset.lastPrice = price;
                    }
                };

                window.addEventListener('resize', () => {
                    chart.resize(chartElement.clientWidth, chartElement.clientHeight);
                });
            } catch (err) {
                console.error("Chart Error:", err);
            }
        });

        function toggleReason() {
            const el = document.getElementById('detailed-reason');
            el.classList.toggle('hidden');
            if (!el.classList.contains('hidden')) {
                el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
        }

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
    </script>
</body>
</html>
