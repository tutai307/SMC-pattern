<?php

namespace App\Console\Commands;

use App\Services\MarketDataService;
use App\Services\PriceActionService;
use App\Services\SignalFormatterService;
use App\Services\TelegramService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * v5.3 — Felix Gold Scanner — Pure Math, No AI.
 *
 * Luồng phân tích Top-Down:
 *   H4 (macro bias) → M15 (channel detect) → Entry/TP/SL
 *
 * Không có AI scoring. Tín hiệu dựa hoàn toàn vào hình học swing points.
 *
 * Chạy:
 *   php artisan signals:scan --capital=1000 --interval=300
 */
class ScanSignalsCommand extends Command
{
    protected $signature = 'signals:scan
        {--interval=300     : Giây giữa mỗi lần quét (mặc định 5 phút)}
        {--capital=0        : Vốn tài khoản USD}
        {--daily-target=50  : Mục tiêu ngày (giá) — đạt rồi khóa máy nghỉ}';

    protected $description = 'v5.3 — Quét kênh giá Gold/Silver theo hình học thuần túy + Telegram';

    private int   $lastScanAt = 0;
    private array $watchlist  = [];

    public function __construct(
        private MarketDataService     $marketData,
        private PriceActionService    $priceActionService,
        private SignalFormatterService $signalFormatter,
        private TelegramService       $telegramService,
    ) {
        parent::__construct();

        $raw = env('SCAN_SYMBOLS', 'XAUUSDT:15m,XAGUSDT:15m');
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

        $this->info("Felix v5.3 Gold Scanner — {$symbols} — mỗi {$interval}s — Pure Math, No AI");
        $this->telegramService->sendRaw(
            "🥇 <b>Felix v5.3 Gold Scanner</b>\n"
            . "Watchlist: <code>{$symbols}</code>\n"
            . "⏱ Scan mỗi <b>{$interval}s</b> | Phương pháp: Hình học kênh giá ông Quyết"
        );

        while (true) {
            $now = time();
            if ($now - $this->lastScanAt >= $interval) {
                try {
                    $this->scan();
                } catch (\Exception $e) {
                    $this->warn('[' . now()->format('H:i:s') . '] Scan lỗi: ' . $e->getMessage());
                    \Log::error('ScanSignals: ' . $e->getMessage());
                }
                $this->lastScanAt = $now;
            }
            sleep(10);
        }
    }

    // ──────────────────────────────────────────────────────────────
    // SCAN LOOP
    // ──────────────────────────────────────────────────────────────

    private function scan(): void
    {
        $ts = now()->format('H:i:s');
        $this->info("[{$ts}] === SCAN ===");

        foreach ($this->watchlist as ['symbol' => $symbol, 'timeframe' => $tf]) {
            if ($this->isDailyTargetReached($symbol)) {
                $this->line("[{$ts}] [{$symbol}] 🏆 Đã đạt mục tiêu ngày — skip");
                continue;
            }
            $this->scanGold($symbol, $tf);
        }
    }

    /**
     * Pipeline Top-Down cho 1 cặp:
     *   1. Check data availability
     *   2. H4 macro bias (optional, graceful fallback nếu không có data)
     *   3. M15 channel detection (4 mô hình)
     *   4. HTF alignment filter
     *   5. Proximity check (bounce channels)
     *   6. ATR calculation
     *   7. Dedup
     *   8. generateSafeSignal (3 lớp bảo vệ)
     *   9. Telegram alert
     */
    private function scanGold(string $symbol, string $timeframe): void
    {
        $ts      = now()->format('H:i:s');
        $capital = (float) $this->option('capital');

        // ── 1. Kiểm tra data từ MT5 EA ────────────────────────────
        if (!$this->marketData->hasData($symbol, $timeframe)) {
            $this->warn("[{$ts}] [{$symbol}] Chưa có data từ EA — chờ EA push");
            return;
        }

        $klines       = $this->marketData->getKlines($symbol, $timeframe, 200);
        $currentPrice = (float) ($this->marketData->getPrice($symbol) ?? 0);

        if (empty($klines) || $currentPrice <= 0) {
            $this->warn("[{$ts}] [{$symbol}] Data trống — EA cần push lại");
            return;
        }

        // ── 2. Top-Down: HTF bias từ H4 ───────────────────────────
        // Nếu EA chưa push H4 data → htfBias = null (không lọc hướng)
        $h4Klines = $this->marketData->getKlines($symbol, '4h', 100);
        $htfBias  = !empty($h4Klines)
            ? $this->priceActionService->getHTFBias($h4Klines)
            : null;

        $htfLabel = match ($htfBias) {
            'LONG'  => '⬆ TĂNG',
            'SHORT' => '⬇ GIẢM',
            default => '➡ không rõ / không có data H4',
        };
        $this->line("[{$ts}] [{$symbol}] HTF(H4): {$htfLabel}");

        // ── 3. M15 Channel Detection (4 mô hình) ─────────────────
        $channel     = $this->priceActionService->detectUnpredictableChannel($klines, lookback: 100);
        $channelType = $channel['type'] ?? 'none';
        $channelDir  = $channel['direction'] ?? null;

        $lh = $channel['lh_count']; $hl = $channel['hl_count'];
        $hh = $channel['hh_count']; $ll = $channel['ll_count'];

        if (!$channel['is_channel']) {
            // Log lý do bị loại
            $reason = match ($channelType) {
                'expanding'     => "EXPANDING (HH={$hh} LL={$ll}) — Biên mở rộng, NGỒI CHƠI",
                'triangle_weak' => "TRIANGLE yếu " . round($channel['compression'] * 100) . "% < 50% — chưa đủ nén",
                default         => "Không có mô hình (LH={$lh} HL={$hl} HH={$hh} LL={$ll})",
            };
            $this->line("[{$ts}] [{$symbol}] {$reason}");
            return;
        }

        $upper = $channel['upper'];
        $lower = $channel['lower'];
        $this->line("[{$ts}] [{$symbol}] Kênh {$channelType} | upper={$upper} lower={$lower} | LH={$lh} HL={$hl} HH={$hh} LL={$ll}");

        // ── 4. HTF alignment filter ───────────────────────────────
        // M15 direction PHẢI khớp với H4 macro (nếu H4 có data rõ ràng)
        // Triangle (dir=null) được chấp nhận bất kể HTF vì đánh cả hai chiều
        if ($htfBias !== null && $channelDir !== null && $htfBias !== $channelDir) {
            $this->line("[{$ts}] [{$symbol}] HTF={$htfBias} ngược chiều M15={$channelDir} — skip (top-down filter)");
            return;
        }

        // ── 5. Proximity check (Bounce channels) ─────────────────
        // SELL LIMIT chỉ kích hoạt khi giá đang sát biên trên (± 0.5 giá)
        // BUY LIMIT chỉ kích hoạt khi giá đang sát biên dưới (± 0.5 giá)
        if ($channelType === 'descending' && $currentPrice < $upper - 0.5) {
            $minPx = round($upper - 0.5, 2);
            $this->line("[{$ts}] [{$symbol}] Giá {$currentPrice} chưa chạm upper={$upper} (cần ≥ {$minPx}) — chờ quét biên");
            return;
        }
        if ($channelType === 'ascending' && $currentPrice > $lower + 0.5) {
            $maxPx = round($lower + 0.5, 2);
            $this->line("[{$ts}] [{$symbol}] Giá {$currentPrice} chưa chạm lower={$lower} (cần ≤ {$maxPx}) — chờ quét biên");
            return;
        }

        // ── 6. ATR ────────────────────────────────────────────────
        $atr = $this->priceActionService->calculateATR($klines, 14);
        $this->line("[{$ts}] [{$symbol}] ATR(14) = {$atr} giá");

        // ── 7. Dedup — cùng kênh chỉ báo 1 lần/2 giờ ─────────────
        $dedupKey = "v5_scan_{$symbol}_{$timeframe}_" . round($upper, 0) . '_' . round($lower, 0);
        if (Cache::has($dedupKey)) {
            $this->line("[{$ts}] [{$symbol}] Alert đã gửi cho kênh này — skip (dedup 2h)");
            return;
        }
        Cache::put($dedupKey, true, now()->addHours(2));

        // ── 8. Generate safe signal — 3 lớp bảo vệ ──────────────
        try {
            $signals = $this->signalFormatter->generateSafeSignal([
                'symbol'        => $symbol,
                'current_price' => $currentPrice,
                'channel'       => $channel,
                'atr'           => $atr,
            ], $capital);
        } catch (\RuntimeException $e) {
            // Vốn không đủ — log, không phát Telegram, xóa dedup để thử lại sau
            Cache::forget($dedupKey);
            $this->warn("[{$ts}] [{$symbol}] ❌ {$e->getMessage()}");
            return;
        }

        if ($signals === null) {
            Cache::forget($dedupKey);
            $this->line("[{$ts}] [{$symbol}] Setup không đạt: R:R < 1 hoặc entry mâu thuẫn giá — skip");
            return;
        }

        // ── 9. Format + Send Telegram ─────────────────────────────
        $msg = $this->signalFormatter->formatTelegramMessage(
            $symbol, $timeframe, $channel, $signals, $currentPrice, $htfBias
        );
        $this->telegramService->sendRaw($msg);

        $slGia = $signals['sl_gia'];
        $tpGia = $signals['tp_gia'];
        $rr    = $signals['rr'];
        $lot   = $signals['lot'];
        $this->info("[{$ts}] [{$symbol}] ✅ Alert gửi | {$channelType} | TP:{$tpGia}g SL:{$slGia}g R:R=1:{$rr} Lot:{$lot}");
    }

    // ──────────────────────────────────────────────────────────────
    // DAILY TARGET
    // ──────────────────────────────────────────────────────────────

    public function recordDailyWin(string $symbol, float $gainGia): void
    {
        $target  = (float) $this->option('daily-target');
        $key     = $this->dailyKey($symbol);
        $current = (float) Cache::get($key, 0.0);
        $new     = round($current + $gainGia, 1);

        Cache::put($key, $new, now()->endOfDay());
        $this->line('[' . now()->format('H:i:s') . "] [{$symbol}] Giá hôm nay: {$new} / {$target}");

        if ($new >= $target) {
            $msg = $this->signalFormatter->formatDailyTargetMessage($symbol, $new, $target);
            $this->telegramService->sendRaw($msg);
            $this->info("[{$symbol}] 🏆 Đạt mục tiêu ngày ({$new} giá) — scan đã khóa đến ngày mai");
        }
    }

    private function isDailyTargetReached(string $symbol): bool
    {
        $target = (float) $this->option('daily-target');
        return $target > 0 && (float) Cache::get($this->dailyKey($symbol), 0.0) >= $target;
    }

    private function dailyKey(string $symbol): string
    {
        return 'v5_daily_gia_' . strtoupper($symbol) . '_' . now()->format('Y-m-d');
    }
}
