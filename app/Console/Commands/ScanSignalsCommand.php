<?php

namespace App\Console\Commands;

use App\Services\BinanceService;
use App\Services\PriceActionService;
use App\Services\TelegramService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class ScanSignalsCommand extends Command
{
    protected $signature   = 'signals:scan {--interval=300 : Giây giữa mỗi lần quét (mặc định 5 phút)}';
    protected $description = 'Tự động quét setup SMC và gửi Telegram khi có kèo mới';

    private array $watchlist = [];

    public function __construct(
        private BinanceService     $binanceService,
        private PriceActionService $priceActionService,
        private TelegramService    $telegramService,
    ) {
        parent::__construct();

        // Đọc từ .env, fallback về 2 coin mặc định
        $raw = env('SCAN_SYMBOLS', 'XAGUSDT:15m,XAGUSDT:1h,XAGUSDT:4h,VVVUSDT:15m,VVVUSDT:1h,VVVUSDT:4h');
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
        $this->info("Scanner bắt đầu — watchlist: {$symbols} — mỗi {$interval}s");
        $this->telegramService->sendRaw("🔍 <b>Scanner khởi động</b>\nWatchlist: <code>{$symbols}</code>\nQuét mỗi <b>{$interval}s</b>");

        while (true) {
            try {
                $this->scan();
            } catch (\Exception $e) {
                $this->warn('[' . now()->format('H:i:s') . '] Lỗi: ' . $e->getMessage());
                \Log::error('ScanSignals: ' . $e->getMessage());
            }
            sleep($interval);
        }
    }

    private function scan(): void
    {
        foreach ($this->watchlist as ['symbol' => $symbol, 'timeframe' => $timeframe]) {
            $this->scanPair($symbol, $timeframe);
        }
    }

    private function scanPair(string $symbol, string $timeframe): void
    {
        $klines       = $this->binanceService->getKlines($symbol, $timeframe, 500);
        $currentPrice = $this->binanceService->getPrice($symbol);

        if (empty($klines) || $currentPrice === null) {
            $this->warn("[{$symbol}] Không lấy được dữ liệu");
            return;
        }

        $htf = match ($timeframe) {
            '1m', '5m', '15m' => '1h',
            '1h'              => '4h',
            default           => '1d',
        };
        $klinesHTF = $this->binanceService->getKlines($symbol, $htf, 50);

        $analysis = $this->priceActionService->analyze($klines, $klinesHTF, 'smc', $symbol, $timeframe);
        $signal   = $analysis['signal'] ?? null;

        if (!$signal) {
            $this->line('[' . now()->format('H:i:s') . "] {$symbol}/{$timeframe} — không có setup");
            return;
        }

        // ── Bộ lọc AI: bắt buộc phải qua đánh giá trước khi gửi Telegram ──
        $aiScore = (int) ($signal['ai_score'] ?? 0);
        $aiRec   = strtoupper($signal['ai_recommendation'] ?? '');
        $aiError = $signal['ai_error'] ?? null;

        if ($aiError) {
            $this->warn('[' . now()->format('H:i:s') . "] {$symbol}/{$timeframe} — AI lỗi ({$aiError}), bỏ qua để tránh sai lầm");
            return;
        }

        if ($aiScore < 60) {
            $this->line('[' . now()->format('H:i:s') . "] {$symbol}/{$timeframe} — AI score {$aiScore}/100 < 60, bỏ qua");
            return;
        }

        if (str_starts_with($aiRec, 'BỎ QUA')) {
            $this->line('[' . now()->format('H:i:s') . "] {$symbol}/{$timeframe} — AI recommend BỎ QUA, bỏ qua");
            return;
        }

        // Dedup: cùng entry (round 4 chữ số) + type = cùng setup, bỏ qua
        $entryKey = round((float) $signal['entry'], 4);
        $dedupKey = "scan_sent_{$symbol}_{$timeframe}_{$signal['type']}_{$entryKey}";

        if (Cache::has($dedupKey)) {
            $this->line('[' . now()->format('H:i:s') . "] {$symbol}/{$timeframe} — setup đã thông báo, chờ hết hạn");
            return;
        }

        Cache::put($dedupKey, true, now()->addHours(6));

        $this->telegramService->sendScanAlert($symbol, $timeframe, $signal, (float) $currentPrice);

        // Lưu vào cache để TelegramBot nhận "ok/có" và theo dõi
        $isLong  = str_contains(strtolower($signal['type'] ?? ''), 'mua') || strtolower($signal['type'] ?? '') === 'long';
        $chatId  = config('services.telegram.chat_id');
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
        ], now()->addHours(8));

        $this->info('[' . now()->format('H:i:s') . "] ✅ Gửi alert [{$aiScore}/100]: {$symbol} {$signal['type']} @ {$signal['entry']}");
    }
}
