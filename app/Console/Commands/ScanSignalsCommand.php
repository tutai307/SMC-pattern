<?php

namespace App\Console\Commands;

use App\Services\MarketDataService;
use App\Services\PriceActionService;
use App\Services\SignalFormatterService;
use App\Services\TelegramService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * v4 — Gold/Silver Price Action Terminal Scanner.
 *
 * Chỉ quét XAUUSD + XAGUSDT.
 * Không tự bắn lệnh — chỉ gửi Telegram để user copy-paste vào Exness.
 *
 * Chạy:
 *   php artisan signals:scan --capital=1000 --multi=7 --daily-target=50 --tp-pips=15
 */
class ScanSignalsCommand extends Command
{
    protected $signature = 'signals:scan
        {--interval=300         : Giây giữa mỗi lần quét (mặc định 5 phút)}
        {--capital=0            : Vốn tài khoản USD}
        {--multi=7              : Hệ số nhân lot khi breakout (probe × multi)}
        {--daily-target=50      : Mục tiêu ngày (pips) — đạt rồi khóa máy nghỉ}
        {--ai-score=70          : Ngưỡng AI score tối thiểu để gửi alert}
        {--min-compression=0.2  : Bỏ qua kênh nén < giá trị này (0.2 = 20%)}';

    protected $description = 'v4 — Quét kênh nén Gold/Silver + gửi Telegram breakout setup';

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

        $this->info("Felix v4 Gold Scanner — {$symbols} — mỗi {$interval}s");
        $this->telegramService->sendRaw(
            "🥇 <b>Felix v4 Gold Scanner khởi động</b>\n"
            . "Watchlist: <code>{$symbols}</code>\n"
            . "⏱ Scan mỗi <b>{$interval}s</b> | AI ≥ <b>{$this->option('ai-score')}</b>"
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
     * Pipeline quét cho 1 cặp:
     *   1. Fetch klines từ Binance
     *   2. detectUnpredictableChannel()
     *   3. Nếu không có kênh → skip
     *   4. scoreWithAI() — không chặn nếu AI chậm (fallback score=50)
     *   5. Nếu AI score ≥ ngưỡng → format + gửi Telegram
     */
    private function scanGold(string $symbol, string $timeframe): void
    {
        $ts          = now()->format('H:i:s');
        $aiThreshold = (int)   $this->option('ai-score');
        $capital     = (float) $this->option('capital');
        $multi       = (int)   $this->option('multi');

        if (!$this->marketData->hasData($symbol, $timeframe)) {
            $this->warn("[{$ts}] [{$symbol}] Chưa có data từ MT5 EA — chờ EA push");
            return;
        }

        $klines       = $this->marketData->getKlines($symbol, $timeframe, 200);
        $currentPrice = (float) ($this->marketData->getPrice($symbol) ?? 0);

        if (empty($klines) || $currentPrice <= 0) {
            $this->warn("[{$ts}] [{$symbol}] Data trống — EA cần push lại");
            return;
        }

        // ── 1. Phát hiện kênh nén ──
        $channel = $this->priceActionService->detectUnpredictableChannel($klines, lookback: 100);

        if (!$channel['is_channel']) {
            $lh = $channel['lh_count']; $hl = $channel['hl_count'];
            $this->line("[{$ts}] [{$symbol}] Không có kênh — LH={$lh} HL={$hl} (cần >=1 mỗi loại)");
            return;
        }

        $upper       = $channel['upper'];
        $lower       = $channel['lower'];
        $channelType = $channel['type'] ?? 'triangle';
        $channelDir  = $channel['direction'] ?? null;

        // Trending channels: skip compression check, force AI direction
        if ($channelType === 'descending' || $channelType === 'ascending') {
            $this->line("[{$ts}] [{$symbol}] Kenh {$channelType} | upper={$upper} lower={$lower}");
            // continue to step 2 (ATR), force direction after AI scoring
        } else {
            // Triangle: apply compression filter
            $minComp = (float) $this->option('min-compression');
            if ($channel['compression'] < $minComp) {
                $comp = round($channel['compression'] * 100);
                $this->line("[{$ts}] [{$symbol}] Kenh nen yeu {$comp}% < " . round($minComp * 100) . "% — skip");
                return;
            }
            $compression = round($channel['compression'] * 100);
            $this->line("[{$ts}] [{$symbol}] Kenh nen {$compression}% | upper={$upper} lower={$lower}");
        }

        // ── 2. Tính ATR ──
        $atr = $this->priceActionService->calculateATR($klines, 14);

        // ── 3. AI scoring ──
        $aiResult = $this->priceActionService->scoreWithAI(
            $symbol, $timeframe, $channel, $atr, $currentPrice, $klines
        );

        $aiScore     = $aiResult['score'] ?? 0;
        $aiDirection = $aiResult['breakout_direction'] ?? 'BOTH';
        $cachedLabel = ($aiResult['cached'] ?? false) ? '[cache]' : '[live]';
        $this->line("[{$ts}] [{$symbol}] AI={$aiScore}/100 dir={$aiDirection} {$cachedLabel}");

        // Force direction for trending channels regardless of AI
        if ($channelDir !== null) {
            $aiDirection = $channelDir;
        }

        if ($aiScore < $aiThreshold) {
            $this->line("[{$ts}] [{$symbol}] AI {$aiScore} < threshold {$aiThreshold} — skip");
            return;
        }

        if ($aiDirection === 'WAIT') {
            $this->line("[{$ts}] [{$symbol}] AI khuyên CHỜ — skip");
            return;
        }

        // ── 4. Dedup — tránh spam cùng kênh trong 2 giờ ──
        $dedupKey = "v4_scan_{$symbol}_{$timeframe}_" . round($upper, 0) . '_' . round($lower, 0);
        if (Cache::has($dedupKey)) {
            $this->line("[{$ts}] [{$symbol}] Alert đã gửi cho kênh này — skip");
            return;
        }
        Cache::put($dedupKey, true, now()->addHours(2));

        // ── 5. Build tham số lệnh + format Telegram ──
        $signals = $this->signalFormatter->buildSignals($symbol, $channel, $capital, $multi);

        $msg = $this->signalFormatter->formatTelegramMessage(
            $symbol, $timeframe, $channel, $signals,
            $aiResult, $currentPrice, $aiDirection
        );

        $this->telegramService->sendRaw($msg);

        $slPips = $signals['sl_pips'];
        $tpPips = $signals['tp_pips'];
        $this->info("[{$ts}] [{$symbol}] ✅ Alert gửi — Score:{$aiScore} | TP:{$tpPips}p SL:{$slPips}p | dir:{$aiDirection}");
    }

    // ──────────────────────────────────────────────────────────────
    // DAILY TARGET — "1 hiệp trong ngày"
    // ──────────────────────────────────────────────────────────────

    /**
     * Gọi từ ngoài (TelegramBotCommand hoặc webhook) khi user báo WIN.
     * Tích lũy pips thắng trong ngày, khóa scan nếu đạt target.
     */
    public function recordDailyWin(string $symbol, float $pipsWon): void
    {
        $target  = (float) $this->option('daily-target');
        $key     = $this->dailyKey($symbol);
        $current = (float) Cache::get($key, 0.0);
        $new     = round($current + $pipsWon, 1);

        Cache::put($key, $new, now()->endOfDay());
        $this->line('[' . now()->format('H:i:s') . "] [{$symbol}] Pips hôm nay: {$new} / {$target}");

        if ($new >= $target) {
            $msg = $this->signalFormatter->formatDailyTargetMessage($symbol, $new, $target);
            $this->telegramService->sendRaw($msg);
            $this->info("[{$symbol}] 🏆 Đạt mục tiêu ngày ({$new} pips) — scan đã khóa đến ngày mai");
        }
    }

    private function isDailyTargetReached(string $symbol): bool
    {
        $target = (float) $this->option('daily-target');
        return $target > 0 && (float) Cache::get($this->dailyKey($symbol), 0.0) >= $target;
    }

    private function dailyKey(string $symbol): string
    {
        return 'v4_daily_pips_' . strtoupper($symbol) . '_' . now()->format('Y-m-d');
    }
}
