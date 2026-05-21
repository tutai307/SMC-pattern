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
        {--daily-target=50  : Mục tiêu ngày (giá) — đạt rồi khóa máy nghỉ}
        {--swing-strength=12 : Fractal bars mỗi bên để confirm MacroSwing}
        {--swing-lookback=100: Số nến M15 tối đa quét Swing}
        {--lot=0.05          : Lot mỗi lệnh (EA default 0.05)}
        {--fixed-sl=5.0      : SL cố định (giá) từ entry}
        {--entry-buf=1.5     : Buffer trên SwingHigh / dưới SwingLow}
        {--wedge-ratio=0.80  : Wedge convergence ratio}
        {--rr=3.0            : Risk:Reward cho TP cụ thể (default 3.0 = TP = entry ± SL×3)}';

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

    private function scanGold(string $symbol, string $timeframe): void
    {
        $ts = now()->format('H:i:s');

        // ── 1. Load klines từ MT5 EA ─────────────────────────────
        if (!$this->marketData->hasData($symbol, $timeframe)) {
            $this->warn("[{$ts}] [{$symbol}] Chưa có data từ EA — chờ EA push");
            return;
        }
        $klines       = $this->marketData->getKlines($symbol, $timeframe, 200);
        $currentPrice = (float) ($this->marketData->getPrice($symbol) ?? 0);
        if (count($klines) < 50 || $currentPrice <= 0) {
            $this->warn("[{$ts}] [{$symbol}] Data không đủ ({$currentPrice})");
            return;
        }

        // ── 2. MacroSwing detection ───────────────────────────────
        $swingStr  = (int)   $this->option('swing-strength');
        $swingLook = (int)   $this->option('swing-lookback');
        $entryBuf  = (float) $this->option('entry-buf');
        $fixedSL   = (float) $this->option('fixed-sl');
        $lot       = (float) $this->option('lot');
        $wedgeRatio= (float) $this->option('wedge-ratio');
        $rr        = (float) $this->option('rr');

        $n = count($klines);
        [$sh1, $sh2, $sl1, $sl2] = $this->findFractalSwings($klines, $n - 1, $swingStr, $swingLook);

        if ($sh1 <= 0 || $sl1 <= 0) {
            $this->line("[{$ts}] [{$symbol}] Không tìm được MacroSwing đủ dùng");
            return;
        }
        $this->line("[{$ts}] [{$symbol}] SH={$sh1}/{$sh2} SL={$sl1}/{$sl2}");

        // ── 3. Smart Trend & Wedge Filter ────────────────────────
        $allowBuy = true; $allowSell = true; $trendReason = 'SIDEWAY→BOTH';
        if ($sh2 > 0 && $sl2 > 0) {
            $downtrend = ($sh1 < $sh2 && $sl1 < $sl2);
            $uptrend   = ($sh1 > $sh2 && $sl1 > $sl2);
            if ($downtrend) {
                $dH = $sh2 - $sh1; $dL = $sl2 - $sl1;
                if ($dH > 0 && $dL < $dH * $wedgeRatio) {
                    $allowBuy = true; $allowSell = false; $trendReason = 'DOWN+FALLING_WEDGE→BUY';
                } else {
                    $allowBuy = false; $allowSell = true; $trendReason = 'DOWNTREND→SELL';
                }
            } elseif ($uptrend) {
                $dH = $sh1 - $sh2; $dL = $sl1 - $sl2;
                if ($dH > 0 && $dH < $dL * $wedgeRatio) {
                    $allowBuy = false; $allowSell = true; $trendReason = 'UP+RISING_WEDGE→SELL';
                } else {
                    $allowBuy = true; $allowSell = false; $trendReason = 'UPTREND→BUY';
                }
            }
        }
        $this->line("[{$ts}] [{$symbol}] Trend: {$trendReason}");

        // ── 4. Tính entry / SL / TP ──────────────────────────────
        $entryBuyPrice  = round($sh1 + $entryBuf, 2);
        $entrySellPrice = round($sl1 - $entryBuf, 2);
        $slBuyPrice     = round($entryBuyPrice  - $fixedSL, 2);
        $slSellPrice    = round($entrySellPrice + $fixedSL, 2);
        $tpBuyPrice     = round($entryBuyPrice  + $fixedSL * $rr, 2);
        $tpSellPrice    = round($entrySellPrice - $fixedSL * $rr, 2);

        $hasBuy  = $allowBuy  && $entryBuyPrice  > $currentPrice;
        $hasSell = $allowSell && $entrySellPrice < $currentPrice;

        if (!$hasBuy && !$hasSell) {
            $this->line("[{$ts}] [{$symbol}] Breakout đã qua hoặc bị filter — không có setup hợp lệ");
            return;
        }

        // ── 5. Dedup — cùng swing chỉ báo 1 lần / 4 giờ ──────────
        $dedupKey = "v8_scan_{$symbol}_" . round($sh1) . '_' . round($sl1);
        if (Cache::has($dedupKey)) {
            $this->line("[{$ts}] [{$symbol}] Cùng swing — dedup 4h");
            return;
        }
        Cache::put($dedupKey, true, now()->addHours(4));

        // ── 6. Format + Send Telegram ─────────────────────────────
        $msg = $this->signalFormatter->formatMacroSwingMessage(
            $symbol, $timeframe, $currentPrice,
            $sh1, $sh2, $sl1, $sl2,
            $hasBuy  ? $entryBuyPrice  : null,
            $hasBuy  ? $slBuyPrice     : null,
            $hasBuy  ? $tpBuyPrice     : null,
            $hasSell ? $entrySellPrice : null,
            $hasSell ? $slSellPrice    : null,
            $hasSell ? $tpSellPrice    : null,
            $trendReason, $lot, $fixedSL, $rr
        );
        $this->telegramService->sendRaw($msg);

        $setups = collect([
            $hasBuy  ? "BUY@{$entryBuyPrice}  SL={$slBuyPrice}"  : null,
            $hasSell ? "SELL@{$entrySellPrice} SL={$slSellPrice}" : null,
        ])->filter()->implode(' | ');
        $this->info("[{$ts}] [{$symbol}] ✅ Alert gửi | {$trendReason} | {$setups} | Lot={$lot}");
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

    /**
     * Tìm 2 SwingHigh + 2 SwingLow gần nhất bằng fractal (giống BacktestCommand v7.7).
     * klines: [[ts,o,h,l,c], ...] — index 2=high, 3=low
     * Returns: [sh1, sh2, sl1, sl2] — 0 nếu không tìm được
     */
    private function findFractalSwings(array $klines, int $idx, int $sw, int $lookback): array
    {
        $sh1 = 0.0; $sh2 = 0.0; $sl1 = 0.0; $sl2 = 0.0;
        $limit = max(0, $idx - $lookback);
        for ($i = $idx - $sw - 1; $i >= $limit + $sw; $i--) {
            if ($sh1 > 0 && $sh2 > 0 && $sl1 > 0 && $sl2 > 0) break;
            $h = (float)($klines[$i][2] ?? 0);
            $l = (float)($klines[$i][3] ?? 0);
            $isH = ($sh1 == 0 || $sh2 == 0) && $h > 0;
            $isL = ($sl1 == 0 || $sl2 == 0) && $l > 0;
            for ($j = 1; $j <= $sw && ($isH || $isL); $j++) {
                if ($isH) {
                    if (($klines[$i - $j][2] ?? 0) >= $h) $isH = false;
                    if (($klines[$i + $j][2] ?? 0) >= $h) $isH = false;
                }
                if ($isL) {
                    if (($klines[$i - $j][3] ?? 0) <= $l) $isL = false;
                    if (($klines[$i + $j][3] ?? 0) <= $l) $isL = false;
                }
            }
            if ($isH) { if ($sh1 == 0) $sh1 = $h; else $sh2 = $h; }
            if ($isL) { if ($sl1 == 0) $sl1 = $l; else $sl2 = $l; }
        }
        return [$sh1, $sh2, $sl1, $sl2];
    }
}
