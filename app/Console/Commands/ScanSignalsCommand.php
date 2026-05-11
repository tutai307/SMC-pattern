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
    protected $signature   = 'signals:scan {--interval=300 : Giây giữa mỗi lần quét setup mới (mặc định 5 phút)}';
    protected $description = 'Quét setup SMC mới + theo dõi lệnh đang mở trong cùng 1 vòng lặp';

    private array $watchlist      = [];
    private int   $lastScanAt     = 0;
    private int   $lastMonitorAt  = 0;
    private int   $lastAiReviewAt = 0;

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
            if ($now - $this->lastAiReviewAt >= 600) {
                try {
                    $this->autoReviewRunningSignals();
                } catch (\Exception $e) {
                    $this->warn('[' . now()->format('H:i:s') . '] AI review lỗi: ' . $e->getMessage());
                    \Log::error('ScanSignals ai_review: ' . $e->getMessage());
                }
                $this->lastAiReviewAt = $now;
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

                $advice = $this->priceActionService->adviseOpenPosition(
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

        $htf = match ($timeframe) {
            '1m', '5m', '15m' => '1h',
            '1h'              => '4h',
            default           => '1d',
        };
        $klinesHTF    = $this->binanceService->getKlines($symbol, $htf,  50);
        $klinesDaily  = $this->binanceService->getKlines($symbol, '1d',  60);
        $klinesWeekly = $this->binanceService->getKlines($symbol, '1w',  60);

        // Session filter OFF — backtest data cho thấy tắt session filter cho WR tốt hơn
        // skipAI = false — AI score dùng để điều chỉnh position size: ≥85→5%, <85→2%
        $analysis = $this->priceActionService->analyze($klines, $klinesHTF, $method, $symbol, $timeframe, false, $klinesDaily, false, $klinesWeekly);
        $signal   = $analysis['signal'] ?? null;

        if (!$signal) {
            $this->line('[' . now()->format('H:i:s') . "] {$symbol}/{$timeframe}/{$method} — không có setup");
            return false;
        }

        $aiScore = (int) ($signal['ai_score'] ?? 0);
        $aiRec   = strtoupper($signal['ai_recommendation'] ?? '');
        $aiError = $signal['ai_error'] ?? null;

        // Tính LocalScore để dùng làm fallback khi AI lỗi
        $localScore = $this->priceActionService->computeConfidenceScore(
            $signal,
            array_slice($this->priceActionService->formatCandlesPublic($klines), -5),
            $analysis['structure']    ?? [],
            ['trend' => $analysis['htf_trend'] ?? 'không rõ'],
            $analysis['indicators']   ?? [],
            $analysis['orderBlocks']  ?? []
        );

        // AI-RISK: ≥85 → risk 8% (backtest XAGUSDT +73% / 4th), <85 → risk 2%
        if ($aiError) {
            // Fallback sang LocalScore khi AI không khả dụng
            $riskPct = $localScore >= 85 ? 8 : 2;
            $this->warn('[' . now()->format('H:i:s') . "] {$symbol}/{$timeframe}/{$method} — AI lỗi ({$aiError}), LocalScore={$localScore} → Risk {$riskPct}%");
        } else {
            $riskPct = $aiScore >= 85 ? 8 : 2;
            if ($aiScore > 0) {
                $this->line('[' . now()->format('H:i:s') . "] {$symbol}/{$timeframe}/{$method} — AI {$aiScore}/100 LocalScore={$localScore} → Risk {$riskPct}%" . ($aiRec ? " | {$aiRec}" : ''));
            }
        }

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
        $this->telegramService->sendScanAlert($symbol, $timeframe, $signal, (float) $currentPrice, $method, $riskPct);

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
        $riskLabel   = $aiScore >= 85 ? "⚡ HIGH ({$riskPct}%)" : "📊 NORMAL ({$riskPct}%)";
        $this->info('[' . now()->format('H:i:s') . "] ✅ {$methodLabel} Alert [AI:{$aiScore} {$riskLabel}]: {$symbol} {$signal['type']} @ {$signal['entry']}");
        return true;
    }
}
