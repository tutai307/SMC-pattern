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

    public function __construct(
        private BinanceService     $binanceService,
        private PriceActionService $priceActionService,
        private TelegramService    $telegramService,
    ) {
        parent::__construct();

        $raw = env('SCAN_SYMBOLS', 'XAGUSDT:1h,XAGUSDT:4h');
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

            // ── AI review lệnh đang chạy mỗi 30 phút ──
            if ($now - $this->lastAiReviewAt >= 1800) {
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

    private function checkUnfilledStructure(TradingSignal $signal): void
    {
        if ($signal->notified_structure_break) return;

        $recentKlines = $this->binanceService->getKlines($signal->symbol, $signal->timeframe, 100);
        if (empty($recentKlines)) return;

        $structure       = $this->priceActionService->getStructure($recentKlines);
        $isLong          = $signal->type === 'LONG';
        $structureBroken = $isLong
            ? ($structure['choch'] && $structure['trend'] === 'GIẢM GIÁ')
            : ($structure['choch'] && $structure['trend'] === 'TĂNG GIÁ');

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

        // Cấu trúc phá vỡ
        if (!$signal->notified_structure_break) {
            $recentKlines    = $this->binanceService->getKlines($signal->symbol, $signal->timeframe, 100);
            $structure       = $this->priceActionService->getStructure($recentKlines);
            $structureBroken = $isLong
                ? ($structure['choch'] && $structure['trend'] === 'GIẢM GIÁ')
                : ($structure['choch'] && $structure['trend'] === 'TĂNG GIÁ');

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
                $cacheKey     = "auto_review_sig_{$signal->id}";
                $last         = Cache::get($cacheKey);

                // Bỏ qua nếu lệnh mới khớp < 15 phút
                if ($signal->filled_at && $signal->filled_at->diffInMinutes(now()) < 15) continue;

                // Bỏ qua nếu đã review gần đây VÀ giá không biến động > 1.5%
                if ($last) {
                    $priceShift = abs($currentPrice - $last['price']) / max($last['price'], 0.0001) * 100;
                    if ($priceShift < 1.5) continue;
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

                Cache::put($cacheKey, ['price' => $currentPrice, 'at' => time()], now()->addMinutes(90));

                $isLong   = $signal->type === 'LONG';
                $pnlPct   = $signal->entry_price > 0
                    ? round((($isLong ? ($currentPrice - $signal->entry_price) : ($signal->entry_price - $currentPrice)) / $signal->entry_price) * 100, 2)
                    : 0;
                $pnlSign  = $pnlPct >= 0 ? "+{$pnlPct}%" : "{$pnlPct}%";
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

                $msg = "🤖 <b>Auto Review #{$signal->id} — {$signal->symbol} {$signal->type}</b>\n"
                     . "━━━━━━━━━━━━━━━\n"
                     . "💰 Giá: <code>{$currentPrice}</code> | P&amp;L: {$pnlEmoji} <b>{$pnlSign}</b>\n"
                     . "📌 Entry: <code>{$signal->entry_price}</code> | TP: <code>{$signal->tp_price}</code> | SL: <code>{$signal->sl_price}</code>\n"
                     . "━━━━━━━━━━━━━━━\n"
                     . "{$verdictEmoji} <b>Phán quyết: {$advice['verdict']}</b>\n"
                     . "🔍 {$advice['analysis']}";

                if (!empty($advice['sl_advice'])) $msg .= "\n🛑 SL: {$advice['sl_advice']}";
                if (!empty($advice['tp_advice']))  $msg .= "\n🎯 TP: {$advice['tp_advice']}";

                $this->telegramService->sendRaw($msg);
                $this->line("  [{$signal->symbol}] 🤖 #{$signal->id} auto review gửi xong — verdict: {$advice['verdict']}");

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
        $anySignalSent = false;
        foreach ($this->watchlist as ['symbol' => $symbol, 'timeframe' => $timeframe]) {
            if ($this->scanPair($symbol, $timeframe, 'smc')) {
                $anySignalSent = true;
            }
        }

        if (!$anySignalSent) {
            $this->sendNoSetupReminder();
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

    private function sendNoSetupReminder(): void
    {
        $dedupKey = 'scan_no_setup_reminder';
        if (Cache::has($dedupKey)) return;

        Cache::put($dedupKey, true, now()->addMinutes(30));

        $messages = [
            "🧘 <b>Không có setup nào đủ điều kiện lúc này.</b>\n\nThị trường chưa cho bạn cơ hội — đây <b>không phải lúc để vào lệnh</b>.\n\nNgồi chờ là một quyết định giao dịch. Trader giỏi nhất thế giới bỏ qua 90% ngày không có setup rõ ràng.",
            "⏳ <b>Chưa có kèo A+ nào.</b>\n\nThị trường ranging hoặc ADX quá thấp. Vào lúc này = đánh bạc, không phải giao dịch.\n\n💡 Nhắc nhở: <i>Tiền bạn giữ được khi không vào lệnh cũng là tiền kiếm được.</i>",
            "🚫 <b>Không có tín hiệu hợp lệ.</b>\n\nHTF chưa align, không có OB/FVG đủ mạnh, hoặc ADX chưa đủ trend.\n\nHãy làm việc khác. Bot sẽ báo ngay khi có setup thật.",
            "🔕 <b>Thị trường im lặng — bạn cũng nên im lặng.</b>\n\nKhông có setup = không có lệnh. Đơn giản vậy thôi.\n\n<i>\"The goal is not to trade every day. The goal is to be profitable.\"</i>",
            "📵 <b>Scan xong — trắng tay.</b>\n\nĐây là tín hiệu tốt nhất hôm nay: <b>ở ngoài thị trường.</b>\n\nBot đang theo dõi 24/7. Khi có kèo thật, bạn sẽ biết ngay.",
        ];

        $idx = Cache::get('scan_no_setup_idx', 0);
        Cache::put('scan_no_setup_idx', ($idx + 1) % count($messages), now()->addDays(7));

        $this->telegramService->sendRaw($messages[$idx]);
        $this->info('[' . now()->format('H:i:s') . '] Nhắc nhở không có setup → đã gửi Telegram.');
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
        $klinesHTF = $this->binanceService->getKlines($symbol, $htf, 50);

        $analysis = $this->priceActionService->analyze($klines, $klinesHTF, $method, $symbol, $timeframe);
        $signal   = $analysis['signal'] ?? null;

        if (!$signal) {
            $this->line('[' . now()->format('H:i:s') . "] {$symbol}/{$timeframe}/{$method} — không có setup");
            return false;
        }

        $aiScore = (int) ($signal['ai_score'] ?? 0);
        $aiRec   = strtoupper($signal['ai_recommendation'] ?? '');
        $aiError = $signal['ai_error'] ?? null;

        if ($aiError) {
            $this->warn('[' . now()->format('H:i:s') . "] {$symbol}/{$timeframe}/{$method} — AI lỗi ({$aiError}), bỏ qua");
            return false;
        }
        if ($aiScore < 70) {
            $this->line('[' . now()->format('H:i:s') . "] {$symbol}/{$timeframe}/{$method} — AI score {$aiScore}/100 < 70, bỏ qua");
            return false;
        }

        // Chỉ gửi SNIPER (OB + CHoCH confirmed) — bỏ qua standard SMC
        if (empty($signal['sniper'])) {
            $this->line('[' . now()->format('H:i:s') . "] {$symbol}/{$timeframe}/{$method} — không phải SNIPER, bỏ qua");
            return false;
        }
        if (str_starts_with($aiRec, 'BỎ QUA')) {
            $this->line('[' . now()->format('H:i:s') . "] {$symbol}/{$timeframe}/{$method} — AI: {$aiRec}, bỏ qua");
            return false;
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
        $this->telegramService->sendScanAlert($symbol, $timeframe, $signal, (float) $currentPrice, $method);

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
        $this->info('[' . now()->format('H:i:s') . "] ✅ {$methodLabel} Alert [{$aiScore}/100]: {$symbol} {$signal['type']} @ {$signal['entry']}");
        return true;
    }
}
