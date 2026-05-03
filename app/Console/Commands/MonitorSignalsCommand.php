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

        $startTime = $signal->created_at->timestamp * 1000;
        $klines    = $this->binanceService->getKlines($signal->symbol, $signal->timeframe, 200, $startTime);

        if (empty($klines)) return;

        $isLong = $signal->type === 'LONG';

        foreach ($klines as $k) {
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
        $klines = $this->binanceService->getKlines($signal->symbol, $signal->timeframe, 100);

        if (empty($klines)) {
            $this->warn("  [{$signal->symbol}] Không lấy được klines.");
            return;
        }

        $currentPrice = (float) $this->binanceService->getPrice($signal->symbol);
        $lastCandle   = end($klines);
        $high         = (float) $lastCandle[2];
        $low          = (float) $lastCandle[3];
        $isLong       = $signal->type === 'LONG';

        // 1. TP hit
        $tpHit = $isLong ? ($high >= $signal->tp_price) : ($low <= $signal->tp_price);
        if ($tpHit && !$signal->notified_tp) {
            $signal->update(['status' => 'WIN', 'notified_tp' => true]);
            broadcast(new SignalStatusChanged($signal->fresh()));
            $this->telegramService->sendTpHit($signal, $currentPrice);
            $this->info("  [{$signal->symbol}] ✅ TP hit → WIN");
            return;
        }

        // 2. SL hit
        $slHit = $isLong ? ($low <= $signal->sl_price) : ($high >= $signal->sl_price);
        if ($slHit && !$signal->notified_sl) {
            $signal->update(['status' => 'LOSS', 'notified_sl' => true]);
            broadcast(new SignalStatusChanged($signal->fresh()));
            $this->telegramService->sendSlHit($signal, $currentPrice);
            $this->info("  [{$signal->symbol}] 🔴 SL hit → LOSS");
            return;
        }

        // 3. Cấu trúc phá vỡ (CHoCH ngược chiều lệnh)
        if (!$signal->notified_structure_break) {
            $structure = $this->priceActionService->getStructure($klines);

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

        // 4. Tiến gần TP (còn ≤ 1.5%) — gợi ý dời SL hoặc chốt một phần
        if (!$signal->notified_near_tp && $signal->tp_price > 0) {
            $tpDistance = abs($currentPrice - $signal->tp_price) / $signal->tp_price;
            if ($tpDistance <= 0.015) {
                $signal->update(['notified_near_tp' => true]);
                $this->telegramService->sendNearTp($signal, $currentPrice);
                $this->info("  [{$signal->symbol}] 🎯 Tiến gần TP ({$currentPrice} → {$signal->tp_price})");
            }
        }

        // 5. Tiến gần SL (còn ≤ 0.5%)
        if (!$signal->notified_near_sl && $signal->sl_price > 0) {
            $slDistance = abs($currentPrice - $signal->sl_price) / $signal->sl_price;
            if ($slDistance <= 0.005) {
                $signal->update(['notified_near_sl' => true]);
                $this->telegramService->sendNearSl($signal, $currentPrice);
                $this->info("  [{$signal->symbol}] ⚠️ Tiến gần SL");
            }
        }

        $tpDist = round(abs($currentPrice - $signal->tp_price) / $signal->tp_price * 100, 2);
        $slDist = round(abs($currentPrice - $signal->sl_price) / $signal->sl_price * 100, 2);
        $this->line("  [{$signal->symbol}] {$signal->type} giá={$currentPrice} | TP còn {$tpDist}% | SL còn {$slDist}%");
    }
}
