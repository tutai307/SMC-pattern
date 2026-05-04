<?php

namespace App\Console\Commands;

use App\Events\SignalStatusChanged;
use App\Models\TradingSignal;
use App\Services\BinanceService;
use App\Services\PriceActionService;
use App\Services\TelegramService;
use Illuminate\Console\Command;

class MonitorSignalsCommand extends Command
{
    protected $signature   = 'signals:monitor {--test : Gửi tin nhắn test để kiểm tra kết nối Telegram}';
    protected $description = 'Theo dõi lệnh đang chờ, gửi cảnh báo Telegram khi cấu trúc phá vỡ hoặc giá tiến gần SL/TP';

    public function __construct(
        private BinanceService     $binanceService,
        private PriceActionService $priceActionService,
        private TelegramService    $telegramService,
    ) {
        parent::__construct();
    }

    public function handle(): void
    {
        if (!$this->telegramService->isConfigured()) {
            $this->warn('Telegram chưa cấu hình. Thêm TELEGRAM_BOT_TOKEN và TELEGRAM_CHAT_ID vào .env');
            return;
        }

        if ($this->option('test')) {
            $this->telegramService->sendTestMessage();
            $this->info('Đã gửi tin nhắn test đến Telegram.');
            return;
        }

        $this->info('Monitor bắt đầu — kiểm tra mỗi 30 giây...');

        while (true) {
            try {
                $this->runCycle();
            } catch (\Exception $e) {
                $this->warn('[' . now()->format('H:i:s') . '] Lỗi: ' . $e->getMessage());
                \Log::error('MonitorSignals: ' . $e->getMessage());
            }
            sleep(30);
        }
    }

    private function runCycle(): void
    {
        $ts = now()->format('H:i:s');

        // Bước 1: Tự động phát hiện lệnh khớp (filled_at IS NULL)
        $unfilled = TradingSignal::where('status', 'PENDING')->whereNull('filled_at')->get();
        foreach ($unfilled as $signal) {
            $this->autoDetectFill($signal);
        }

        // Bước 2: Theo dõi TP/SL/cấu trúc cho lệnh đã khớp (filled_at IS NOT NULL)
        $pending = TradingSignal::where('status', 'PENDING')->whereNotNull('filled_at')->get();

        if ($pending->isEmpty()) {
            $this->line("[{$ts}] Chờ: {$unfilled->count()} lệnh chờ khớp, 0 đang chạy.");
            return;
        }

        $this->info("[{$ts}] Kiểm tra {$pending->count()} lệnh đang chạy...");
        foreach ($pending as $signal) {
            $this->checkSignal($signal);
        }
    }

    private function autoDetectFill(TradingSignal $signal): void
    {
        if (!$signal->entry_price) return;

        // Fetch recent candles without startTime — ensures we always have the latest data.
        // Using startTime+limit=200 caused fills to be missed for signals older than 200 candles.
        $klines = $this->binanceService->getKlines($signal->symbol, $signal->timeframe, 500);

        if (empty($klines)) return;

        $createdAtMs = $signal->created_at->timestamp * 1000;
        $isLong      = $signal->type === 'LONG';

        foreach ($klines as $k) {
            if ((int) $k[0] < $createdAtMs) continue; // Skip candles before signal creation

            $high   = (float) $k[2];
            $low    = (float) $k[3];
            $filled = $isLong ? ($low <= (float) $signal->entry_price) : ($high >= (float) $signal->entry_price);

            if ($filled) {
                $signal->update(['filled_at' => now()]);
                broadcast(new SignalStatusChanged($signal->fresh()));
                $currentPrice = (float) $this->binanceService->getPrice($signal->symbol);
                $this->telegramService->sendEntryFilled($signal, $currentPrice);
                $this->info("  [{$signal->symbol}] 🟢 Lệnh #{$signal->id} khớp tự động tại entry {$signal->entry_price}");
                return;
            }
        }
    }

    private function checkSignal(TradingSignal $signal): void
    {
        // Fetch recent candles without startTime — same reason as autoDetectFill:
        // startTime+limit can leave a blind spot for positions open longer than limit candles.
        $klines = $this->binanceService->getKlines($signal->symbol, $signal->timeframe, 500);

        if (empty($klines)) {
            $this->warn("  [{$signal->symbol}] Không lấy được klines.");
            return;
        }

        $filledAtMs = $signal->filled_at->timestamp * 1000;
        $klines = array_filter($klines, fn($k) => (int) $k[0] >= $filledAtMs);
        $klines = array_values($klines);

        $currentPrice = (float) $this->binanceService->getPrice($signal->symbol);
        $isLong       = $signal->type === 'LONG';

        // Walk every candle in chronological order — first TP or SL touch wins
        foreach ($klines as $k) {
            $high = (float) $k[2];
            $low  = (float) $k[3];

            // 1. TP hit (check before SL — if same candle hits both, TP came first for trend direction)
            if (!$signal->notified_tp) {
                $tpHit = $isLong ? ($high >= $signal->tp_price) : ($low <= $signal->tp_price);
                if ($tpHit) {
                    $signal->update(['status' => 'WIN', 'notified_tp' => true]);
                    broadcast(new SignalStatusChanged($signal->fresh()));
                    $this->telegramService->sendTpHit($signal, $currentPrice);
                    $this->info("  [{$signal->symbol}] ✅ TP hit → WIN");
                    return;
                }
            }

            // 2. SL hit
            if (!$signal->notified_sl) {
                $slHit = $isLong ? ($low <= $signal->sl_price) : ($high >= $signal->sl_price);
                if ($slHit) {
                    $signal->update(['status' => 'LOSS', 'notified_sl' => true]);
                    broadcast(new SignalStatusChanged($signal->fresh()));
                    $this->telegramService->sendSlHit($signal, $currentPrice);
                    $this->info("  [{$signal->symbol}] 🔴 SL hit → LOSS");
                    return;
                }
            }
        }

        // 3. Structure break — use recent candles (last 100 from now, no startTime)
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
                $this->info("  [{$signal->symbol}] 🚨 Cấu trúc phá vỡ → CANCELLED");
                return;
            }
        }

        // 4. Near TP (≤ 1.5% away by current price)
        if (!$signal->notified_near_tp && $signal->tp_price > 0) {
            if (abs($currentPrice - $signal->tp_price) / $signal->tp_price <= 0.015) {
                $signal->update(['notified_near_tp' => true]);
                $this->telegramService->sendNearTp($signal, $currentPrice);
                $this->info("  [{$signal->symbol}] 🎯 Tiến gần TP ({$currentPrice} → {$signal->tp_price})");
            }
        }

        // 5. Near SL (≤ 0.5% away by current price)
        if (!$signal->notified_near_sl && $signal->sl_price > 0) {
            if (abs($currentPrice - $signal->sl_price) / $signal->sl_price <= 0.005) {
                $signal->update(['notified_near_sl' => true]);
                $this->telegramService->sendNearSl($signal, $currentPrice);
                $this->info("  [{$signal->symbol}] ⚠️ Tiến gần SL");
            }
        }

        $tpDist = $signal->tp_price > 0 ? round(abs($currentPrice - $signal->tp_price) / $signal->tp_price * 100, 2) : 0;
        $slDist = $signal->sl_price > 0 ? round(abs($currentPrice - $signal->sl_price) / $signal->sl_price * 100, 2) : 0;
        $this->line("  [{$signal->symbol}] {$signal->type} giá={$currentPrice} | TP còn {$tpDist}% | SL còn {$slDist}%");
    }
}
