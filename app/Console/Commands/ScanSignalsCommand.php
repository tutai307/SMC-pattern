<?php

namespace App\Console\Commands;

use App\Events\SignalStatusChanged;
use App\Models\TradingSignal;
use App\Services\BinanceService;
use App\Services\PriceActionService;
use App\Services\TelegramService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class ScanSignalsCommand extends Command
{
    protected $signature   = 'signals:scan {--interval=300 : Giây giữa mỗi lần quét setup mới (mặc định 5 phút)} {--capital=0 : Vốn hiện tại (USDT) để tính vol/margin}';
    protected $description = 'Quét setup SMC mới + theo dõi lệnh đang mở trong cùng 1 vòng lặp';

    private array $watchlist      = [];
    private int   $lastScanAt     = 0;
    private int   $lastMonitorAt  = 0;
    private int   $lastAiReviewAt = 0;
    private int   $lastTrendAt    = 0;

    // Struct-exit per-pair: chỉ cancel khi CHoCH ngược chiều (backtest-validated)
    private array $structExitSymbols = ['SOLUSDT'];

    public function __construct(
        private BinanceService     $binanceService,
        private PriceActionService $priceActionService,
        private TelegramService    $telegramService,
    ) {
        parent::__construct();

        $raw = env('SCAN_SYMBOLS', 'SOLUSDT:15m,XAGUSDT:15m,LINKUSDT:15m,ETHUSDT:15m,BTCUSDT:15m,XAUUSDT:15m');
        foreach (explode(',', $raw) as $item) {
            [$sym, $tf] = array_pad(explode(':', trim($item)), 2, '15m');
            $this->watchlist[] = ['symbol' => strtoupper($sym), 'timeframe' => $tf];
        }
    }

    public function handle(): void
    {
        if (!$this->telegramService->isConfigured()) {
            $this->error('Telegram chưa cấu hình. Thêm TELEGRAM_BOT_TOKEN + TELEGRAM_CHAT_ID vào .env');
            return;
        }

        $interval = (int) $this->option('interval');
        $symbols  = implode(', ', array_map(fn($w) => "{$w['symbol']}({$w['timeframe']})", $this->watchlist));
        $this->info("Scanner + Monitor bắt đầu — watchlist: {$symbols} — scan mỗi {$interval}s, monitor mỗi 30s");
        $this->telegramService->sendRaw(
            "🔍 <b>Felix Scanner khởi động</b>\n"
            . "Watchlist: <code>{$symbols}</code>\n"
            . "⏱ Scan setup: mỗi <b>{$interval}s</b> | Theo dõi lệnh: mỗi <b>30s</b>"
        );

        while (true) {
            $now = time();

            // ── Monitor lệnh đang mở mỗi 30 giây ──
            if ($now - $this->lastMonitorAt >= 30) {
                try {
                    $this->monitorActiveSignals();
                } catch (\Exception $e) {
                    $this->warn('[' . now()->format('H:i:s') . '] Monitor lỗi: ' . $e->getMessage());
                    \Log::error('ScanSignals monitor: ' . $e->getMessage());
                }
                $this->lastMonitorAt = $now;
            }

            // ── AI review lệnh đang chạy mỗi 10 phút ──
            if ($now - $this->lastAiReviewAt >= 300) {
                try {
                    $this->autoReviewRunningSignals();
                } catch (\Exception $e) {
                    $this->warn('[' . now()->format('H:i:s') . '] AI review lỗi: ' . $e->getMessage());
                    \Log::error('ScanSignals ai_review: ' . $e->getMessage());
                }
                $this->lastAiReviewAt = $now;
            }

            // ── Báo cáo xu hướng mỗi 1 giờ ──
            if ($now - $this->lastTrendAt >= 7200) {
                try {
                    \Illuminate\Support\Facades\Artisan::call('trend:hourly');
                } catch (\Exception $e) {
                    $this->warn('[' . now()->format('H:i:s') . '] Trend report lỗi: ' . $e->getMessage());
                }
                $this->lastTrendAt = $now;
            }

            // ── Scan setup mới theo interval ──
            if ($now - $this->lastScanAt >= $interval) {
                try {
                    $this->scan();
                } catch (\Exception $e) {
                    $this->warn('[' . now()->format('H:i:s') . '] Scan lỗi: ' . $e->getMessage());
                    \Log::error('ScanSignals scan: ' . $e->getMessage());
                }
                $this->lastScanAt = $now;
            }

            sleep(10);
        }
    }

    // ══════════════════════════════════════════════════════════════
    // PHẦN 1: MONITOR LỆNH ĐANG MỞ
    // ══════════════════════════════════════════════════════════════

    private function monitorActiveSignals(): void
    {
        $ts = now()->format('H:i:s');

        // Lệnh chưa khớp: auto-detect fill + check cấu trúc
        $unfilled = TradingSignal::where('status', 'PENDING')->whereNull('filled_at')->get();
        foreach ($unfilled as $signal) {
            $this->autoDetectFill($signal);
        }
        $stillUnfilled = TradingSignal::where('status', 'PENDING')->whereNull('filled_at')->get();
        foreach ($stillUnfilled as $signal) {
            $this->checkUnfilledExpiry($signal);
            $this->checkUnfilledStructure($signal);
        }

        // Lệnh đã khớp: theo dõi TP/SL/cấu trúc
        $running = TradingSignal::where('status', 'PENDING')->whereNotNull('filled_at')->get();
        if ($running->isEmpty()) {
            if (!$unfilled->isEmpty()) {
                $this->line("[{$ts}] Monitor: {$unfilled->count()} chờ khớp, 0 đang chạy");
            }
            return;
        }

        $this->line("[{$ts}] Monitor: {$running->count()} lệnh đang chạy");
        foreach ($running as $signal) {
            $this->checkSignal($signal);
        }
    }

    private function autoDetectFill(TradingSignal $signal): void
    {
        if (!$signal->entry_price) return;

        $klines = $this->binanceService->getKlines($signal->symbol, $signal->timeframe, 500);
        if (empty($klines)) return;

        $createdAtMs = $signal->created_at->timestamp * 1000;
        $isLong      = $signal->type === 'LONG';

        foreach ($klines as $k) {
            if ((int) $k[0] < $createdAtMs) continue;
            $high   = (float) $k[2];
            $low    = (float) $k[3];
            $filled = $isLong ? ($low <= (float) $signal->entry_price) : ($high >= (float) $signal->entry_price);

            if ($filled) {
                $signal->update(['filled_at' => now()]);
                broadcast(new SignalStatusChanged($signal->fresh()));
                $currentPrice = (float) $this->binanceService->getPrice($signal->symbol);
                $this->telegramService->sendEntryFilled($signal, $currentPrice);
                $this->info("  [{$signal->symbol}] 🟢 #{$signal->id} khớp tự động @ {$signal->entry_price}");
                return;
            }
        }
    }

    private function checkUnfilledExpiry(TradingSignal $signal): void
    {
        if ($signal->notified_expiry) return;

        $expiryHours = 8;
        if ($signal->created_at->diffInHours(now()) < $expiryHours) return;

        $signal->update(['notified_expiry' => true, 'status' => 'CANCELLED']);
        broadcast(new SignalStatusChanged($signal->fresh()));
        $currentPrice = (float) $this->binanceService->getPrice($signal->symbol);
        $this->telegramService->sendUnfilledExpiry($signal, $currentPrice, $expiryHours);
        $this->info("  [{$signal->symbol}] ⏰ #{$signal->id} chưa khớp sau {$expiryHours}h → CANCELLED");
    }

    private function checkUnfilledStructure(TradingSignal $signal): void
    {
        if ($signal->notified_structure_break) return;
        if (!in_array($signal->symbol, $this->structExitSymbols)) return;

        $recentKlines = $this->binanceService->getKlines($signal->symbol, $signal->timeframe, 100);
        if (empty($recentKlines)) return;

        $structure       = $this->priceActionService->getStructure($recentKlines);
        $isLong          = $signal->type === 'LONG';
        // Bearish CHoCH (trend TĂNG → price dưới lastLow) → cancel LONG
        // Bullish CHoCH (trend GIẢM → price trên lastHigh) → cancel SHORT
        $structureBroken = $isLong
            ? ($structure['choch'] && $structure['trend'] === 'TĂNG GIÁ')
            : ($structure['choch'] && $structure['trend'] === 'GIẢM GIÁ');

        if (!$structureBroken) return;

        $signal->update(['notified_structure_break' => true, 'status' => 'CANCELLED']);
        broadcast(new SignalStatusChanged($signal->fresh()));
        $currentPrice = (float) $this->binanceService->getPrice($signal->symbol);
        $this->telegramService->sendPreEntryStructureBreak($signal, $currentPrice, $structure['trend']);
        $this->info("  [{$signal->symbol}] 🚨 #{$signal->id} cấu trúc phá vỡ trước entry → CANCELLED");
    }

    private function checkSignal(TradingSignal $signal): void
    {
        $klines = $this->binanceService->getKlines($signal->symbol, $signal->timeframe, 500);
        if (empty($klines)) return;

        $filledAtMs   = $signal->filled_at->timestamp * 1000;
        $klines       = array_values(array_filter($klines, fn($k) => (int) $k[0] >= $filledAtMs));
        $currentPrice = (float) $this->binanceService->getPrice($signal->symbol);
        $isLong       = $signal->type === 'LONG';

        foreach ($klines as $k) {
            $high = (float) $k[2];
            $low  = (float) $k[3];

            // TP hit
            if (!$signal->notified_tp) {
                $tpHit = $isLong ? ($high >= $signal->tp_price) : ($low <= $signal->tp_price);
                if ($tpHit) {
                    $signal->update(['status' => 'WIN', 'notified_tp' => true]);
                    broadcast(new SignalStatusChanged($signal->fresh()));
                    $this->telegramService->sendTpHit($signal, $currentPrice);
                    $this->info("  [{$signal->symbol}] ✅ #{$signal->id} TP hit → WIN");
                    return;
                }
            }

            // SL hit
            if (!$signal->notified_sl) {
                $slHit = $isLong ? ($low <= $signal->sl_price) : ($high >= $signal->sl_price);
                if ($slHit) {
                    $signal->update(['status' => 'LOSS', 'notified_sl' => true]);
                    broadcast(new SignalStatusChanged($signal->fresh()));
                    $this->telegramService->sendSlHit($signal, $currentPrice);
                    $this->info("  [{$signal->symbol}] 🔴 #{$signal->id} SL hit → LOSS");
                    return;
                }
            }
        }

        // Cấu trúc phá vỡ (chỉ với symbols đã bật struct-exit)
        if (!$signal->notified_structure_break && in_array($signal->symbol, $this->structExitSymbols)) {
            $recentKlines    = $this->binanceService->getKlines($signal->symbol, $signal->timeframe, 100);
            $structure       = $this->priceActionService->getStructure($recentKlines);
            $structureBroken = $isLong
                ? ($structure['choch'] && $structure['trend'] === 'TĂNG GIÁ')
                : ($structure['choch'] && $structure['trend'] === 'GIẢM GIÁ');

            if ($structureBroken) {
                $signal->update(['notified_structure_break' => true, 'status' => 'CANCELLED']);
                broadcast(new SignalStatusChanged($signal->fresh()));
                $this->telegramService->sendStructureBreak($signal, $currentPrice, $structure['trend']);
                $this->info("  [{$signal->symbol}] 🚨 #{$signal->id} cấu trúc phá vỡ → CANCELLED");
                return;
            }
        }

        // Tiến gần TP (≤ 2%)
        if (!$signal->notified_near_tp && $signal->tp_price > 0) {
            if (abs($currentPrice - $signal->tp_price) / $signal->tp_price <= 0.02) {
                $signal->update(['notified_near_tp' => true]);
                $this->telegramService->sendNearTp($signal, $currentPrice);
                $this->info("  [{$signal->symbol}] 🎯 #{$signal->id} tiến gần TP");
            }
        }

        // Tiến gần SL (≤ 1.5%)
        if (!$signal->notified_near_sl && $signal->sl_price > 0) {
            if (abs($currentPrice - $signal->sl_price) / $signal->sl_price <= 0.015) {
                $signal->update(['notified_near_sl' => true]);
                $this->telegramService->sendNearSl($signal, $currentPrice);
                $this->info("  [{$signal->symbol}] ⚠️ #{$signal->id} tiến gần SL");
            }
        }

        $tpDist = $signal->tp_price > 0 ? round(abs($currentPrice - $signal->tp_price) / $signal->tp_price * 100, 2) : 0;
        $slDist = $signal->sl_price > 0 ? round(abs($currentPrice - $signal->sl_price) / $signal->sl_price * 100, 2) : 0;
        $this->line("  [{$signal->symbol}] {$signal->type} #{$signal->id} giá={$currentPrice} | TP còn {$tpDist}% | SL còn {$slDist}%");
    }

    private function autoReviewRunningSignals(): void
    {
        $running = TradingSignal::where('status', 'PENDING')->whereNotNull('filled_at')->get();
        if ($running->isEmpty()) return;

        $ts = now()->format('H:i:s');
        $this->line("[{$ts}] AI Review: kiểm tra {$running->count()} lệnh đang chạy...");

        foreach ($running as $signal) {
            try {
                $currentPrice = (float) $this->binanceService->getPrice($signal->symbol);
                $reviewKey    = "auto_review_sig_{$signal->id}";
                $pnlSignKey   = "auto_review_pnl_{$signal->id}";
                $last         = Cache::get($reviewKey);

                // Bỏ qua nếu lệnh mới khớp < 10 phút
                if ($signal->filled_at && $signal->filled_at->diffInMinutes(now()) < 10) continue;

                // Phát hiện P&L đổi chiều (dương → âm hoặc ngược lại)
                $isLong     = $signal->type === 'LONG';
                $pnlPct     = $signal->entry_price > 0
                    ? round((($isLong ? ($currentPrice - $signal->entry_price) : ($signal->entry_price - $currentPrice)) / $signal->entry_price) * 100, 2)
                    : 0;
                $currentSign = $pnlPct >= 0 ? '+' : '-';
                $lastSign    = Cache::get($pnlSignKey);
                $pnlFlipped  = $lastSign && $lastSign !== $currentSign;
                Cache::put($pnlSignKey, $currentSign, now()->addHours(24));

                // Skip nếu không có sự kiện đáng chú ý
                if (!$pnlFlipped && $last) {
                    $priceShift = abs($currentPrice - $last['price']) / max($last['price'], 0.0001) * 100;
                    if ($priceShift < 1.0) continue;
                }

                $htfMap = ['1m' => '5m', '5m' => '15m', '15m' => '1h', '1h' => '4h', '4h' => '1d', '1d' => '1w'];
                $htf    = $htfMap[$signal->timeframe] ?? '4h';

                $klines    = $this->binanceService->getKlines($signal->symbol, $signal->timeframe, 200);
                $klinesHTF = $this->binanceService->getKlines($signal->symbol, $htf, 100);
                if (empty($klines)) continue;

                // ── Pre-check cứng trước khi gọi AI ──
                $hardAdvice = $this->hardReviewCheck(
                    $klines, $klinesHTF,
                    $signal->type, (float) $signal->entry_price,
                    (float) $signal->sl_price, $currentPrice, $pnlPct
                );

                $advice = $hardAdvice ?? $this->priceActionService->adviseOpenPosition(
                    $klines, $klinesHTF,
                    $signal->symbol, $signal->timeframe, $signal->type,
                    (float) $signal->entry_price,
                    (float) $signal->sl_price,
                    (float) $signal->tp_price,
                    $currentPrice
                );

                Cache::put($reviewKey, ['price' => $currentPrice, 'at' => time()], now()->addMinutes(30));

                $pnlStr   = $pnlPct >= 0 ? "+{$pnlPct}%" : "{$pnlPct}%";
                $pnlEmoji = $pnlPct >= 0 ? '🟢' : '🔴';

                $verdict      = strtoupper($advice['verdict'] ?? '');
                $verdictEmoji = match (true) {
                    str_contains($verdict, 'GIỮ')        => '✋',
                    str_contains($verdict, 'CHỐT LỜI')   => '💰',
                    str_contains($verdict, 'CẮT LỖ')     => '🔴',
                    str_contains($verdict, 'DI CHUYỂN')   => '🔧',
                    str_contains($verdict, 'ĐIỀU CHỈNH')  => '🔧',
                    str_contains($verdict, '50%')          => '⚖️',
                    default                               => '💡',
                };

                // Chỉ gửi khi có hành động cụ thể hoặc P&L đảo chiều
                $isActionable = str_contains($verdict, 'CẮT LỖ')
                    || str_contains($verdict, 'CHỐT LỜI')
                    || str_contains($verdict, 'DI CHUYỂN')
                    || str_contains($verdict, 'ĐIỀU CHỈNH')
                    || str_contains($verdict, '50%');

                if (!$isActionable && !$pnlFlipped) {
                    $this->line("  [{$signal->symbol}] #{$signal->id} verdict=GIỮ, skip gửi");
                    continue;
                }

                $flipAlert = $pnlFlipped && $currentSign === '-'
                    ? "🚨 <b>P&amp;L ĐẢO CHIỀU — từ dương sang âm!</b>\n"
                    : '';

                $msg = "🤖 <b>Auto Review #{$signal->id} — {$signal->symbol} {$signal->type}</b>\n"
                     . $flipAlert
                     . "━━━━━━━━━━━━━━━\n"
                     . "💰 Giá: <code>{$currentPrice}</code> | P&amp;L: {$pnlEmoji} <b>{$pnlStr}</b>\n"
                     . "📌 Entry: <code>{$signal->entry_price}</code> | TP: <code>{$signal->tp_price}</code> | SL: <code>{$signal->sl_price}</code>\n"
                     . "━━━━━━━━━━━━━━━\n"
                     . "{$verdictEmoji} <b>Phán quyết: {$advice['verdict']}</b>\n"
                     . "🔍 {$advice['analysis']}";

                if (!empty($advice['sl_advice'])) $msg .= "\n🛑 SL: {$advice['sl_advice']}";
                if (!empty($advice['tp_advice']))  $msg .= "\n🎯 TP: {$advice['tp_advice']}";

                $this->telegramService->sendRaw($msg);
                $this->line("  [{$signal->symbol}] 🤖 #{$signal->id} auto review — verdict: {$advice['verdict']}" . ($pnlFlipped ? ' [P&L FLIP]' : ''));

            } catch (\Exception $e) {
                \Log::error("auto_review #{$signal->id}: " . $e->getMessage());
            }
        }
    }

    // ══════════════════════════════════════════════════════════════
    // PHẦN 2: SCAN SETUP MỚI
    // ══════════════════════════════════════════════════════════════

    private function scan(): void
    {
        $this->info('[' . now()->format('H:i:s') . '] === BẮT ĐẦU SCAN ===');
        foreach ($this->watchlist as ['symbol' => $symbol, 'timeframe' => $timeframe]) {
            $this->scanPair($symbol, $timeframe, 'smc');
        }
    }

    private function getGoldTrend(): array
    {
        $cacheKey = 'gold_trend_4h';
        if ($cached = Cache::get($cacheKey)) return $cached;

        $klines = $this->binanceService->getKlines('XAUUSDT', '4h', 100);
        $price  = (float) ($this->binanceService->getPrice('XAUUSDT') ?? 0);

        if (empty($klines)) {
            return ['trend' => 'không rõ', 'price' => $price];
        }

        $structure = $this->priceActionService->getStructure($klines);
        $result    = ['trend' => $structure['trend'] ?? 'không rõ', 'price' => $price];
        Cache::put($cacheKey, $result, now()->addMinutes(15));
        return $result;
    }

    private function scanPair(string $symbol, string $timeframe, string $method = 'smc'): bool
    {
        $klines       = $this->binanceService->getKlines($symbol, $timeframe, 500);
        $currentPrice = $this->binanceService->getPrice($symbol);

        if (empty($klines) || $currentPrice === null) {
            $this->warn("[{$symbol}] Không lấy được dữ liệu");
            return false;
        }

        if ($this->isVolatilityCircuitBreakerActive($symbol, $timeframe, $klines)) {
            $this->line('[' . now()->format('H:i:s') . "] [{$symbol}] Circuit breaker active — skip signal");
            return false;
        }

        $htf = match ($timeframe) {
            '1m', '5m'  => '1h',
            '15m', '1h' => '4h',
            default     => '1d',
        };
        $klinesHTF    = $this->binanceService->getKlines($symbol, $htf,  50);
        $klinesDaily  = $this->binanceService->getKlines($symbol, '1d',  60);
        $klinesWeekly = $this->binanceService->getKlines($symbol, '1w',  60);
        $btcDaily     = $symbol !== 'BTCUSDT'
            ? $this->binanceService->getKlines('BTCUSDT', '1d', 30)
            : $klinesDaily;

        // ADX=15, minConfidence=75 — backtest 1/1-13/5/2026: WR 46.7%, +131% với local-score ai-risk
        $this->priceActionService->setThresholds(15, 75);
        $analysis = $this->priceActionService->analyze($klines, $klinesHTF, $method, $symbol, $timeframe, true, $klinesDaily, false, $klinesWeekly);
        // Zone-based alerts — chạy kể cả khi không có signal
        $this->checkZones($symbol, $timeframe, $analysis, (float) $currentPrice);

        $signal   = $analysis['signal'] ?? null;

        if (!$signal) {
            $this->line('[' . now()->format('H:i:s') . "] {$symbol}/{$timeframe}/{$method} — không có setup");
            return false;
        }

        // BTC sentiment filter: không SHORT khi BTC daily EMA20 bullish, không LONG khi bearish
        if (!empty($btcDaily) && count($btcDaily) >= 5) {
            $btcCloses = array_map(fn($k) => (float)$k[4], $btcDaily);
            $period    = min(20, count($btcCloses) - 1);
            $kk        = 2 / ($period + 1);
            $ema       = $btcCloses[0];
            for ($i = 1; $i < count($btcCloses); $i++) {
                $ema = $btcCloses[$i] * $kk + $ema * (1 - $kk);
            }
            $btcMacro  = end($btcCloses) > $ema ? 'TĂNG GIÁ' : 'GIẢM GIÁ';
            $isShort   = !str_contains(strtolower($signal['type'] ?? ''), 'mua');
            $isLong    = !$isShort;
            if ($isShort && $btcMacro === 'TĂNG GIÁ') {
                $this->line('[' . now()->format('H:i:s') . "] {$symbol} — SHORT blocked (BTC daily bullish)");
                return false;
            }
            if ($isLong && $btcMacro === 'GIẢM GIÁ') {
                $this->line('[' . now()->format('H:i:s') . "] {$symbol} — LONG blocked (BTC daily bearish)");
                return false;
            }
        }

        // LocalScore thay AI — backtest 15m: ADX≥15, score≥75 → WR 46.7%, +131% với ai-risk
        $localScore = $this->priceActionService->computeConfidenceScore(
            $signal,
            array_slice($this->priceActionService->formatCandlesPublic($klines), -5),
            $analysis['structure']    ?? [],
            ['trend' => $analysis['htf_trend'] ?? 'không rõ'],
            $analysis['indicators']   ?? [],
            $analysis['orderBlocks']  ?? []
        );

        if ($localScore < 75) {
            $this->line('[' . now()->format('H:i:s') . "] {$symbol}/{$timeframe}/{$method} — LocalScore={$localScore} < 75, skip");
            return false;
        }

        $riskPct = $localScore >= 85 ? 8 : 2;
        $this->line('[' . now()->format('H:i:s') . "] {$symbol}/{$timeframe}/{$method} — LocalScore={$localScore} → Risk {$riskPct}%");

        // Override TP → 1:2.5 R:R (backtest Jan-Apr 2026: WR 39.4%, +73% với AI-risk vs +60% ở 1:3)
        $entry  = (float) $signal['entry'];
        $sl     = (float) $signal['sl'];
        $slDist = abs($entry - $sl);
        $isLongSig = str_contains(strtolower($signal['type'] ?? ''), 'mua');
        if ($slDist > 0) {
            $signal['tp'] = $isLongSig
                ? round($entry + $slDist * 2.5, 8)
                : round($entry - $slDist * 2.5, 8);
        }

        // ── Silver/Gold correlation filter ──
        if ($symbol === 'XAGUSDT') {
            $gold        = $this->getGoldTrend();
            $isLongSig   = str_contains(strtolower($signal['type'] ?? ''), 'mua');
            $goldTrend   = $gold['trend'];

            if ($isLongSig && $goldTrend === 'GIẢM GIÁ') {
                $this->warn('[' . now()->format('H:i:s') . "] XAGUSDT MUA bị block — XAU đang {$goldTrend}");
                return false;
            }
            if (!$isLongSig && $goldTrend === 'TĂNG GIÁ') {
                $this->warn('[' . now()->format('H:i:s') . "] XAGUSDT BÁN bị block — XAU đang {$goldTrend}");
                return false;
            }

            $goldLabel   = $goldTrend === 'TĂNG GIÁ' ? '📈 TĂNG' : ($goldTrend === 'GIẢM GIÁ' ? '📉 GIẢM' : '↔ ĐI NGANG');
            $signal['reason'] = "🥇 XAU/USD {$goldLabel} @ " . number_format($gold['price'], 2) . " — bạc align\n" . ($signal['reason'] ?? '');
        }

        $entryKey = round((float) $signal['entry'], 4);
        $dedupKey = "scan_sent_{$symbol}_{$timeframe}_{$method}_{$signal['type']}_{$entryKey}";

        if (Cache::has($dedupKey)) {
            $this->line('[' . now()->format('H:i:s') . "] {$symbol}/{$timeframe}/{$method} — setup đã thông báo, chờ hết hạn");
            return true; // đã gửi trước đó → vẫn tính là "có setup"
        }

        Cache::put($dedupKey, true, now()->addHours(6));
        $scanCapital = (float) $this->option('capital');
        $this->telegramService->sendScanAlert($symbol, $timeframe, $signal, (float) $currentPrice, $method, $riskPct, $scanCapital);

        $isLong = str_contains(strtolower($signal['type'] ?? ''), 'mua') || strtolower($signal['type'] ?? '') === 'long';
        $chatId = config('services.telegram.chat_id');
        Cache::put("scan_pending_{$chatId}", [
            'symbol'    => $symbol,
            'timeframe' => $timeframe,
            'type'      => $isLong ? 'LONG' : 'SHORT',
            'entry'     => $signal['entry'],
            'tp'        => $signal['tp'],
            'sl'        => $signal['sl'],
            'winrate'   => $signal['winrate'] ?? 0,
            'reason'    => $signal['reason'] ?? '',
            'capital'   => 0,
            'method'    => $method,
        ], now()->addHours(8));

        $methodLabel = strtoupper($method);
        $riskLabel = $localScore >= 85 ? "⚡ HIGH ({$riskPct}%)" : "📊 NORMAL ({$riskPct}%)";
        $this->info('[' . now()->format('H:i:s') . "] ✅ {$methodLabel} Alert [Score:{$localScore} {$riskLabel}]: {$symbol} {$signal['type']} @ {$signal['entry']}");
        return true;
    }

    // ── Zone Alerts (predictive) ─────────────────────────────────────

    private function checkZones(string $symbol, string $timeframe, array $analysis, float $price): void
    {
        $htfTrend = $analysis['htf_trend'] ?? '';
        $obs      = $analysis['orderBlocks'] ?? [];

        foreach ($obs as $ob) {
            $high = (float) ($ob['high'] ?? $ob['top'] ?? 0);
            $low  = (float) ($ob['low'] ?? $ob['bottom'] ?? 0);
            if ($high <= 0 || $low <= 0 || $high <= $low) continue;

            $isDemand = ($ob['type'] ?? '') === 'demand';

            // HTF alignment — chỉ alert khi HTF có xu hướng rõ, NGANG thì bỏ qua
            if ($htfTrend === 'ĐI NGANG') continue;
            if ($isDemand && $htfTrend === 'GIẢM GIÁ') continue;
            if (!$isDemand && $htfTrend === 'TĂNG GIÁ') continue;

            // Zone Hit: giá trong OB
            if ($price >= $low && $price <= $high) {
                $this->fireZoneHit($symbol, $timeframe, $ob, $price, $analysis);
                continue;
            }

            // Zone Approach: giá cách OB ≤ 0.5%
            $dist = $isDemand
                ? ($price > $high ? ($price - $high) / $price : -1)
                : ($price < $low  ? ($low - $price) / $price  : -1);

            if ($dist > 0 && $dist <= 0.005) {
                $atr = (float) ($analysis['indicators']['atr'] ?? 0);
                $this->fireZoneApproach($symbol, $timeframe, $ob, $price, $dist, $htfTrend, $atr);
            }
        }
    }

    private function fireZoneHit(string $symbol, string $timeframe, array $ob, float $price, array $analysis): void
    {
        $high     = (float) ($ob['high'] ?? $ob['top']);
        $low      = (float) ($ob['low'] ?? $ob['bottom']);
        $isDemand = ($ob['type'] ?? '') === 'demand';

        $atr    = (float) ($analysis['indicators']['atr'] ?? 0);
        $buffer = $price * 0.001;
        $obSl   = $isDemand ? ($low - $buffer) : ($high + $buffer);
        // ATR-based SL: wider of OB-edge vs ATR*1.5 — SL never narrower than before
        $atrSl  = $atr > 0
            ? ($isDemand ? ($price - $atr * 1.5) : ($price + $atr * 1.5))
            : $obSl;
        $sl     = $isDemand ? round(min($obSl, $atrSl), 4) : round(max($obSl, $atrSl), 4);
        $slDist = abs($price - $sl);
        if ($slDist <= 0) return;

        // Bỏ qua nếu SL quá nhỏ so với ATR — tránh noise stopout
        if ($atr > 0 && $slDist < $atr * 1.0) return;

        $tp    = $isDemand
            ? round($price + $slDist * 2.5, 4)
            : round($price - $slDist * 2.5, 4);
        $tpPct = abs($tp - $price) / $price * 100;

        // TP phải tối thiểu 1.5% — dưới đó không đáng trade
        if ($tpPct < 1.5) return;

        $klines = $this->binanceService->getKlines($symbol, $timeframe, 200);
        $sig = [
            'type'    => $isDemand ? 'MUA' : 'BÁN',
            'entry'   => $price,
            'tp'      => $tp,
            'sl'      => $sl,
            'winrate' => 60,
            'reason'  => '',
            'ai_score' => 0,
        ];
        $score = $this->priceActionService->computeConfidenceScore(
            $sig,
            array_slice($this->priceActionService->formatCandlesPublic($klines), -5),
            $analysis['structure']   ?? [],
            ['trend' => $analysis['htf_trend'] ?? ''],
            $analysis['indicators']  ?? [],
            $analysis['orderBlocks'] ?? []
        );

        if ($score < 75) return;

        $riskPct = $score >= 85 ? 8 : 5;
        $dedupKey = "zone_hit_{$symbol}_{$timeframe}_{$ob['type']}_" . round($low, 2);
        if (Cache::has($dedupKey)) return;
        Cache::put($dedupKey, true, now()->addHours(2));

        $capital = (float) $this->option('capital');
        $this->telegramService->sendZoneHitAlert($symbol, $timeframe, $isDemand, $price, $tp, $sl, $score, $riskPct, $capital);

        // Lưu pending vào cache — chờ user reply "ok" mới lưu DB
        $chatId = config('services.telegram.chat_id');
        Cache::put("scan_pending_{$chatId}", [
            'symbol'    => $symbol,
            'timeframe' => $timeframe,
            'type'      => $isDemand ? 'LONG' : 'SHORT',
            'entry'     => $price,
            'tp'        => $tp,
            'sl'        => $sl,
            'winrate'   => $score,
            'reason'    => 'Zone Hit — OB ' . ($isDemand ? 'demand' : 'supply') . ' ' . number_format($low, 2) . '-' . number_format($high, 2),
            'capital'   => $capital,
        ], now()->addHours(2));

        $this->info('[' . now()->format('H:i:s') . "] 🎯 Zone Hit pending (chờ ok): {$symbol} " . ($isDemand ? 'DEMAND' : 'SUPPLY') . " @ {$price} Score:{$score}");
    }

    private function fireZoneApproach(string $symbol, string $timeframe, array $ob, float $price, float $dist, string $htfTrend, float $atr = 0): void
    {
        $high     = (float) ($ob['high'] ?? $ob['top']);
        $low      = (float) ($ob['low'] ?? $ob['bottom']);
        $isDemand = ($ob['type'] ?? '') === 'demand';

        // Tính SL của approach alert để check ATR minimum
        $entry  = $isDemand ? $high : $low;
        $buffer = $entry * 0.001;
        $obSl   = $isDemand ? ($low - $buffer) : ($high + $buffer);
        // ATR-based SL: wider of OB-edge vs ATR*1.5 — SL never narrower than before
        $atrSl  = $atr > 0
            ? ($isDemand ? ($entry - $atr * 1.5) : ($entry + $atr * 1.5))
            : $obSl;
        $sl     = $isDemand ? round(min($obSl, $atrSl), 4) : round(max($obSl, $atrSl), 4);
        $slDist = abs($entry - $sl);

        // Bỏ qua nếu SL quá nhỏ — zone không đủ rộng để trade thực tế
        if ($atr > 0 && $slDist < $atr * 1.0) return;

        // TP minimum threshold theo timeframe — loại bỏ alert khi TP quá gần
        $tpMinPct = match ($timeframe) {
            '15m'   => 0.4,
            '1h'    => 0.8,
            '4h'    => 1.5,
            default => 0.4,
        };
        $tp    = $isDemand ? $entry + $slDist * 2.5 : $entry - $slDist * 2.5;
        $tpPct = abs($tp - $entry) / $entry * 100;
        if ($tpPct < $tpMinPct) return;

        $dedupKey = "zone_approach_{$symbol}_{$timeframe}_{$ob['type']}_" . round($low, 2);
        if (Cache::has($dedupKey)) return;
        Cache::put($dedupKey, true, now()->addHours(2));

        $this->telegramService->sendZoneApproachAlert($symbol, $timeframe, $isDemand, $high, $low, $price, $dist, $htfTrend);
        $this->line('[' . now()->format('H:i:s') . "] ⚠️ Zone Approach: {$symbol} " . ($isDemand ? 'DEMAND' : 'SUPPLY') . " cách " . round($dist * 100, 2) . "%");
    }

    /**
     * Kiểm tra volatility circuit breaker.
     * Kích hoạt khi nến vừa đóng có range > 2.5x ATR(14) — dấu hiệu black swan.
     * Cooldown = 3 candles (set qua Cache).
     */
    private function isVolatilityCircuitBreakerActive(string $symbol, string $timeframe, array $klines): bool
    {
        if (count($klines) < 15) return false;

        // ATR(14) từ 14 nến trước (index -15 đến -2, không tính nến đang chạy)
        $ranges = [];
        for ($i = count($klines) - 15; $i < count($klines) - 1; $i++) {
            $ranges[] = (float)$klines[$i][2] - (float)$klines[$i][3];
        }
        $atr = array_sum($ranges) / 14;

        // Nến vừa đóng (index -2, không phải nến đang chạy index -1)
        $lastClosed = $klines[count($klines) - 2];
        $lastRange  = (float)$lastClosed[2] - (float)$lastClosed[3];

        $cacheKey = "volatility_breaker_{$symbol}_{$timeframe}";

        if ($atr > 0 && $lastRange > $atr * 2.5) {
            $candleMinutes = match($timeframe) {
                '15m' => 15, '1h' => 60, '4h' => 240, default => 15
            };
            $cooldownMinutes = $candleMinutes * 3;
            Cache::put($cacheKey, [
                'triggered_at' => now()->toDateTimeString(),
                'last_range'   => $lastRange,
                'atr'          => $atr,
                'ratio'        => round($lastRange / $atr, 1),
            ], now()->addMinutes($cooldownMinutes));

            \Log::warning("Circuit breaker [{$symbol} {$timeframe}]: range {$lastRange} = " . round($lastRange / $atr, 1) . "x ATR — pause {$cooldownMinutes}min");

            // Cancel tất cả PENDING signals của symbol này
            $cancelled = TradingSignal::where('symbol', $symbol)
                ->where('status', 'PENDING')
                ->update(['status' => 'CANCELLED']);

            if ($cancelled > 0) {
                \Log::warning("Circuit breaker cancelled {$cancelled} PENDING signal(s) for {$symbol}");
                $ratio = round($lastRange / $atr, 1);
                $this->telegramService->sendRaw(
                    "⚡ <b>Circuit Breaker — {$symbol}</b>\n"
                    . "Nến vừa đóng: range <b>{$ratio}x ATR</b> (black swan)\n"
                    . "Đã huỷ <b>{$cancelled}</b> lệnh PENDING — tạm dừng scan <b>{$cooldownMinutes} phút</b>"
                );
            }

            // FOMO opportunity alert — gửi trước alert lệnh đang chạy để user thấy cơ hội trước
            $currentPrice = $this->binanceService->getPrice($symbol);
            $lastCandleOpen  = (float)$lastClosed[1];
            $lastCandleClose = (float)$lastClosed[4];
            $isBearish = $lastCandleClose < $lastCandleOpen;

            $direction  = $isBearish ? '🔴 SHORT' : '🟢 LONG';
            $movePct    = round(abs($lastCandleClose - $lastCandleOpen) / $lastCandleOpen * 100, 2);
            $suggestion = $isBearish
                ? "Chờ pullback lên để SHORT, hoặc SHORT ngay nếu momentum mạnh"
                : "Chờ pullback xuống để LONG, hoặc LONG ngay nếu momentum mạnh";

            $msg = "⚡ <b>BLACK SWAN — {$symbol}</b>\n"
                 . "Nến vừa di chuyển <b>{$movePct}%</b> ({$direction})\n"
                 . "Giá hiện tại: <b>{$currentPrice}</b>\n\n"
                 . "🎯 <b>Cơ hội FOMO:</b> {$suggestion}\n"
                 . "⚠️ High risk — không có OB/FVG confirm, thuần momentum";

            $this->telegramService->sendRaw($msg);

            // Alert khan cho RUNNING signals — khong the auto-close, chi canh bao de user dong thu cong
            $running = TradingSignal::where('symbol', $symbol)
                ->where('status', 'RUNNING')
                ->get();

            foreach ($running as $sig) {
                $currentPrice = $this->binanceService->getPrice($symbol);
                $pnl = $sig->type === 'LONG'
                    ? round(($currentPrice - $sig->entry_price) / $sig->entry_price * 100, 2)
                    : round(($sig->entry_price - $currentPrice) / $sig->entry_price * 100, 2);
                $pnlEmoji = $pnl >= 0 ? '&#x1F7E2;' : '&#x1F534;';
                $ratio    = round($lastRange / $atr, 1);

                $msg = "&#x26A1; <b>BIEN DONG CUC MANH — {$symbol}</b>\n"
                     . "Nen vua dong: " . round($lastRange, 2) . "$ = {$ratio}x ATR binh thuong\n\n"
                     . "&#x1F6A8; <b>LENH #{$sig->id} {$sig->type} dang chay</b>\n"
                     . "Entry: {$sig->entry_price} | Gia: {$currentPrice}\n"
                     . "P&amp;L: {$pnlEmoji} {$pnl}%\n\n"
                     . "&#x26A0;&#xFE0F; <b>Xem xet dong lenh ngay — OB cu khong con hieu luc</b>";

                $this->telegramService->sendRaw($msg);
                \Log::warning("Circuit breaker RUNNING alert #{$sig->id} {$symbol} {$sig->type} pnl={$pnl}%");
            }

            return true;
        }

        // Kiểm tra cooldown từ lần kích hoạt trước
        return Cache::has($cacheKey);
    }

    /**
     * Hard pre-check trước khi gọi AI review.
     * Trả về verdict cứng nếu có điều kiện rõ ràng, null nếu cần AI quyết định.
     */
    private function hardReviewCheck(
        array  $klines,
        array  $klinesHTF,
        string $type,
        float  $entry,
        ?float $sl,
        float  $currentPrice,
        float  $pnlPct
    ): ?array {
        $isLong = $type === 'LONG';

        // ── Điều kiện 1: ≥5 nến LTF liên tiếp ngược chiều + đang âm ──
        // Nâng từ 4 → 5 để tránh cắt lỗ khi rung lắc ngắn hạn
        $candles = array_slice($klines, -9);
        $consecutive = 0;
        foreach (array_reverse($candles) as $c) {
            $isBull = (float)$c[4] > (float)$c[1];
            if ($isLong && !$isBull) $consecutive++;
            elseif (!$isLong && $isBull) $consecutive++;
            else break;
        }

        if ($consecutive >= 5 && $pnlPct < 0) {
            return [
                'verdict'  => 'CẮT LỖ NGAY',
                'analysis' => "{$consecutive} nến LTF liên tiếp ngược chiều lệnh {$type} — momentum đã đổi chiều rõ ràng. P&L {$pnlPct}%, cắt lỗ để bảo vệ vốn.",
                'sl_advice' => null,
                'tp_advice' => null,
            ];
        }

        // ── Điều kiện 2: HTF trend ngược chiều lệnh ──
        // Yêu cầu đồng thời: (a) swing structure ngược + (b) 3 candles HTF liên tiếp ngược + (c) P&L < -2%
        // Trước đây chỉ cần swing ngược + P&L < -1% — quá nhạy, 1 cây tăng tạm là kích hoạt
        if (!empty($klinesHTF) && count($klinesHTF) >= 10) {
            $htfCandles = array_slice($klinesHTF, -10);
            $htfHighs   = array_map(fn($k) => (float)$k[2], $htfCandles);
            $htfLows    = array_map(fn($k) => (float)$k[3], $htfCandles);
            $htfBull    = end($htfHighs) > $htfHighs[0] && end($htfLows) > $htfLows[0];
            $htfBear    = end($htfHighs) < $htfHighs[0] && end($htfLows) < $htfLows[0];

            // Đếm candles HTF liên tiếp ngược chiều (3 cây liên tiếp)
            $htfConsecutive = 0;
            foreach (array_reverse($htfCandles) as $c) {
                $isBull = (float)$c[4] > (float)$c[1];
                if ($isLong && !$isBull) $htfConsecutive++;
                elseif (!$isLong && $isBull) $htfConsecutive++;
                else break;
            }
            $htfMomentumConfirmed = $htfConsecutive >= 3;

            if ($isLong && $htfBear && $htfMomentumConfirmed && $pnlPct < -2.0) {
                return [
                    'verdict'  => 'CẮT LỖ NGAY',
                    'analysis' => "HTF đang GIẢM ({$htfConsecutive} nến liên tiếp xuống) trong khi lệnh LONG — bias ngược chiều xác nhận. P&L {$pnlPct}%, cắt lỗ.",
                    'sl_advice' => null,
                    'tp_advice' => null,
                ];
            }
            if (!$isLong && $htfBull && $htfMomentumConfirmed && $pnlPct < -2.0) {
                return [
                    'verdict'  => 'CẮT LỖ NGAY',
                    'analysis' => "HTF đang TĂNG ({$htfConsecutive} nến liên tiếp lên) trong khi lệnh SHORT — bias ngược chiều xác nhận. P&L {$pnlPct}%, cắt lỗ.",
                    'sl_advice' => null,
                    'tp_advice' => null,
                ];
            }
        }

        // ── Điều kiện 3: SL đã dùng ≥70% ──
        if ($sl && $entry > 0 && $pnlPct < 0) {
            $slDist   = abs($entry - $sl);
            $usedDist = abs($currentPrice - $entry);
            $usedPct  = $slDist > 0 ? ($usedDist / $slDist * 100) : 0;

            if ($usedPct >= 70) {
                return [
                    'verdict'  => 'CẮT LỖ NGAY',
                    'analysis' => "Đã dùng " . round($usedPct) . "% quãng đường đến SL. Cắt lỗ theo kỷ luật.",
                    'sl_advice' => null,
                    'tp_advice' => null,
                ];
            }
        }

        return null; // Không có tín hiệu cứng → để AI quyết định
    }
}
