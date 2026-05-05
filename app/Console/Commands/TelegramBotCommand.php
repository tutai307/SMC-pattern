<?php

namespace App\Console\Commands;

use App\Models\TradingSignal;
use App\Services\BinanceService;
use App\Services\PriceActionService;
use App\Services\TelegramService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class TelegramBotCommand extends Command
{
    protected $signature   = 'telegram:bot';
    protected $description = 'Long polling Telegram bot — chat tự nhiên để lấy và theo dõi lệnh';

    private int    $offset = 0;
    private string $chatId = '';

    // Timeframe mapping theo loại giao dịch
    private array $tfMap = [
        'scalp'    => ['tf' => '15m', 'htf' => '1h',  'label' => 'Scalp (15m)'],
        'intraday' => ['tf' => '1h',  'htf' => '4h',  'label' => 'Intraday (1h)'],
        'swing'    => ['tf' => '4h',  'htf' => '1d',  'label' => 'Swing (4h)'],
    ];

    // Từ đồng nghĩa xác nhận / từ chối
    private array $confirmWords = ['có', 'co', 'ok', 'yes', 'ừ', 'u', 'vào', 'vao', 'theo dõi', 'ghi', 'xác nhận', 'xac nhan', 'đồng ý', 'dong y'];
    private array $rejectWords  = ['không', 'khong', 'no', 'thôi', 'thoi', 'bỏ', 'bo', 'hủy', 'huy', 'cancel', 'skip', 'bỏ qua'];

    public function __construct(
        private TelegramService    $telegram,
        private BinanceService     $binance,
        private PriceActionService $priceAction,
    ) {
        parent::__construct();
    }

    public function handle(): void
    {
        if (!$this->telegram->isConfigured()) {
            $this->error('Telegram chưa cấu hình. Thêm TELEGRAM_BOT_TOKEN và TELEGRAM_CHAT_ID vào .env');
            return;
        }

        $this->chatId = (string) config('services.telegram.chat_id');
        $this->info('Telegram bot đang lắng nghe...');

        while (true) {
            try {
                $updates = $this->telegram->getUpdates($this->offset);
                foreach ($updates as $update) {
                    $this->processUpdate($update);
                    $this->offset = $update['update_id'] + 1;
                }
            } catch (\Exception $e) {
                $this->warn('Lỗi polling: ' . $e->getMessage());
                sleep(5);
            }
        }
    }

    // ─── Router ────────────────────────────────────────────────────────────────

    private function processUpdate(array $update): void
    {
        $message = $update['message'] ?? null;
        if (!$message || !isset($message['text'])) return;

        if ((string) ($message['chat']['id'] ?? '') !== $this->chatId) return;

        $text = trim($message['text']);

        if (str_starts_with($text, '/')) {
            $this->handleCommand($text);
        } else {
            $this->handleFreeText($text);
        }
    }

    // ─── Slash commands ─────────────────────────────────────────────────────────

    private function handleCommand(string $text): void
    {
        $parts   = explode(' ', $text);
        $command = strtolower($parts[0]);
        $args    = array_slice($parts, 1);

        match (true) {
            in_array($command, ['/start', '/help']) => $this->cmdHelp(),
            $command === '/list'                     => $this->cmdList(),
            $command === '/signal'                   => $this->cmdSignal($args),
            $command === '/filled'                   => $this->cmdFilled($args),
            $command === '/cancel'                   => $this->cmdCancel($args),
            $command === '/status'                   => $this->cmdStatus(),
            default                                  => $this->telegram->reply("Lệnh không hợp lệ. Gõ /help để xem danh sách."),
        };
    }

    // ─── Free text handler ───────────────────────────────────────────────────────

    private function handleFreeText(string $text): void
    {
        $lower      = mb_strtolower($text);
        $pendingKey = "tg_pending_{$this->chatId}";
        $hasPending = Cache::has($pendingKey);

        // Xác nhận vào lệnh
        foreach ($this->confirmWords as $w) {
            if ($hasPending && str_contains($lower, $w)) {
                $this->confirmPendingSignal();
                return;
            }
        }

        // Từ chối lệnh
        foreach ($this->rejectWords as $w) {
            if ($hasPending && str_contains($lower, $w)) {
                Cache::forget($pendingKey);
                $this->telegram->reply("Đã bỏ qua. Nhắn lại bất cứ lúc nào để lấy lệnh mới.");
                return;
            }
        }

        // Parse yêu cầu lấy lệnh
        $parsed = $this->parseSignalRequest($text);
        if ($parsed) {
            $this->runAnalysis($parsed);
            return;
        }

        // Fallback
        $this->telegram->reply(
            "Tôi chưa hiểu yêu cầu này.\n\n"
            . "<b>Ví dụ:</b>\n"
            . "• <code>cho tôi lệnh scalp xagusdt vốn 70u</code>\n"
            . "• <code>kèo intraday btcusdt 100$</code>\n"
            . "• <code>swing ethusdt 50u</code>\n\n"
            . "Gõ /help để xem thêm."
        );
    }

    // ─── Natural language parser ─────────────────────────────────────────────────

    private function parseSignalRequest(string $text): ?array
    {
        $lower = mb_strtolower($text);

        // Phải có từ khóa liên quan đến giao dịch
        $keywords = ['lệnh', 'lenh', 'kèo', 'keo', 'cho tôi', 'cho toi', 'phân tích', 'phan tich', 'xem', 'scalp', 'intraday', 'swing'];
        $hasKeyword = false;
        foreach ($keywords as $kw) {
            if (str_contains($lower, $kw)) { $hasKeyword = true; break; }
        }

        // Detect loại giao dịch → timeframe
        $tradeType = 'scalp';
        if (str_contains($lower, 'swing') || str_contains($lower, '4h')) {
            $tradeType = 'swing';
        } elseif (str_contains($lower, 'intraday') || str_contains($lower, 'day') || str_contains($lower, '1h')) {
            $tradeType = 'intraday';
        }

        // Detect symbol (ưu tiên pattern XYZusdt, sau đó alias)
        $symbol = null;
        if (preg_match('/\b([a-zA-Z]{2,8}usdt)\b/i', $text, $m)) {
            $symbol = strtoupper($m[1]);
        } else {
            $aliases = [
                'btc' => 'BTCUSDT', 'eth' => 'ETHUSDT', 'sol' => 'SOLUSDT',
                'bnb' => 'BNBUSDT', 'xrp' => 'XRPUSDT', 'xag' => 'XAGUSDT',
                'xau' => 'XAUUSDT', 'doge' => 'DOGEUSDT', 'ada' => 'ADAUSDT',
                'link' => 'LINKUSDT', 'avax' => 'AVAXUSDT', 'dot' => 'DOTUSDT',
                'ton' => 'TONUSDT', 'trx' => 'TRXUSDT', 'near' => 'NEARUSDT',
            ];
            foreach ($aliases as $alias => $full) {
                if (preg_match('/\b' . $alias . '\b/i', $lower)) { $symbol = $full; break; }
            }
        }

        if (!$symbol) return null;
        if (!$hasKeyword && !$symbol) return null;

        // Detect vốn
        $capital = 0;
        if (preg_match('/(\d+(?:[.,]\d+)?)\s*(?:u\b|usd\b|\$|usdt\b)/i', $text, $m)) {
            $capital = (float) str_replace(',', '.', $m[1]);
        } elseif (preg_match('/vốn\s+(\d+(?:[.,]\d+)?)/iu', $text, $m)) {
            $capital = (float) str_replace(',', '.', $m[1]);
        }

        return [
            'symbol'     => $symbol,
            'tradeType'  => $tradeType,
            'tf'         => $this->tfMap[$tradeType]['tf'],
            'htf'        => $this->tfMap[$tradeType]['htf'],
            'label'      => $this->tfMap[$tradeType]['label'],
            'capital'    => $capital,
        ];
    }

    // ─── Analysis runner ─────────────────────────────────────────────────────────

    private function runAnalysis(array $parsed): void
    {
        ['symbol' => $symbol, 'tf' => $tf, 'htf' => $htf, 'label' => $label, 'capital' => $capital] = $parsed;

        $this->telegram->reply("🔍 Đang phân tích <b>{$symbol}</b> — {$label}...\nVui lòng chờ ~15 giây.");

        try {
            $klines = $this->binance->getKlines($symbol, $tf, 500);
            if (empty($klines)) {
                $this->telegram->reply("❌ Không lấy được dữ liệu <b>{$symbol}</b>. Kiểm tra lại tên coin.");
                return;
            }

            $klinesHTF    = $this->binance->getKlines($symbol, $htf, 100);
            $currentPrice = (float) $this->binance->getPrice($symbol);
            $analysis     = $this->priceAction->analyze($klines, $klinesHTF, 'smc', $symbol, $tf);

            if (!$analysis['signal']) {
                $adx   = round($analysis['indicators']['adx'] ?? 0, 1);
                $trend = $analysis['structure']['trend'] ?? 'không rõ';
                $this->telegram->reply(
                    "📊 <b>{$symbol}</b> | {$label}\n"
                    . "━━━━━━━━━━━━━━━\n"
                    . "Xu hướng: <b>{$trend}</b> | ADX: {$adx}\n"
                    . "━━━━━━━━━━━━━━━\n"
                    . "❌ Chưa có setup đủ điều kiện.\n"
                    . "Thử khung khác hoặc đợi thị trường rõ hơn."
                );
                return;
            }

            $sig    = $analysis['signal'];
            $isLong = in_array($sig['type'], ['MUA', 'LONG', 'MUA (SÓNG 3)', 'MUA (HỒI SAU C)']);
            $type   = $isLong ? 'LONG' : 'SHORT';
            $slPct  = $sig['entry'] > 0 ? round(abs($sig['entry'] - $sig['sl']) / $sig['entry'] * 100, 2) : 0;
            $tpPct  = $sig['entry'] > 0 ? round(abs($sig['tp'] - $sig['entry']) / $sig['entry'] * 100, 2) : 0;
            $rr     = $slPct > 0 ? round($tpPct / $slPct, 1) : 0;

            // Capital management block
            $posBlock = '';
            if ($capital > 0 && $slPct > 0) {
                $riskAmt  = round($capital * 0.02, 2);
                $slFrac   = $slPct / 100;
                $leverage = max(1, min(20, (int) floor(1 / ($slFrac * 2))));
                $volume   = round($riskAmt / $slFrac, 2);
                $margin   = round($volume / $leverage, 2);
                $liqDist  = round((1 / $leverage) * 90, 2);
                $posBlock = "\n━━━━━━━━━━━━━━━\n"
                          . "💰 Vốn: <b>\${$capital}</b> | Đòn bẩy: <b>{$leverage}x</b>\n"
                          . "📊 Ký quỹ: <code>\${$margin}</code> | KL: <code>\${$volume}</code>\n"
                          . "💀 Lỗ tối đa: <code>\${$riskAmt}</code> (2%) | Liq ~{$liqDist}% từ entry";
            }

            // AI block
            $aiBlock = '';
            if (!empty($sig['ai_score'])) {
                $emoji   = $sig['ai_score'] >= 80 ? '🟢' : ($sig['ai_score'] >= 60 ? '🟡' : '🔴');
                $aiBlock = "\n━━━━━━━━━━━━━━━\n"
                         . "🤖 AI Score: {$emoji} <b>{$sig['ai_score']}/100</b>\n";
                if (!empty($sig['ai_analysis']))       $aiBlock .= "🔍 {$sig['ai_analysis']}\n";
                if (!empty($sig['ai_risk']))           $aiBlock .= "⚠️ {$sig['ai_risk']}\n";
                if (!empty($sig['ai_recommendation'])) $aiBlock .= "💡 {$sig['ai_recommendation']}";
            }

            $dirEmoji = $isLong ? '📈' : '📉';

            $msg = "{$dirEmoji} <b>{$type} — {$symbol} {$label}</b>\n"
                 . "━━━━━━━━━━━━━━━\n"
                 . "💰 Giá: <code>{$currentPrice}</code>\n"
                 . "📌 Entry: <code>{$sig['entry']}</code>\n"
                 . "🎯 TP: <code>{$sig['tp']}</code> <b>(+{$tpPct}%)</b>\n"
                 . "🛑 SL: <code>{$sig['sl']}</code> (-{$slPct}%)\n"
                 . "📐 R:R = 1:{$rr} | Winrate: {$sig['winrate']}%"
                 . $posBlock
                 . $aiBlock . "\n"
                 . "━━━━━━━━━━━━━━━\n"
                 . "📝 <i>{$sig['reason']}</i>\n\n"
                 . "❓ <b>Bạn muốn theo dõi lệnh này không?</b>\n"
                 . "✅ Gõ <b>có</b> → ghi vào hệ thống, bot tự động theo dõi\n"
                 . "❌ Gõ <b>không</b> → bỏ qua";

            $this->telegram->reply($msg);

            // Lưu pending vào cache 5 phút để chờ xác nhận
            Cache::put("tg_pending_{$this->chatId}", [
                'symbol'    => $symbol,
                'timeframe' => $tf,
                'type'      => $type,
                'entry'     => $sig['entry'],
                'tp'        => $sig['tp'],
                'sl'        => $sig['sl'],
                'winrate'   => $sig['winrate'],
                'reason'    => $sig['reason'],
                'capital'   => $capital,
            ], 300);

            $this->info("  [{$symbol}] Phân tích xong → {$type}, chờ xác nhận từ user.");

        } catch (\Exception $e) {
            $this->telegram->reply("❌ Lỗi phân tích: " . $e->getMessage());
            \Log::error('Telegram analysis: ' . $e->getMessage());
        }
    }

    // ─── Confirm / save pending signal ───────────────────────────────────────────

    private function confirmPendingSignal(): void
    {
        $pendingKey = "tg_pending_{$this->chatId}";
        $p          = Cache::get($pendingKey);

        if (!$p) {
            $this->telegram->reply("Không có lệnh nào đang chờ xác nhận. Nhắn tôi tên coin và loại lệnh để phân tích mới.");
            return;
        }

        Cache::forget($pendingKey);

        $signal = TradingSignal::create([
            'symbol'      => $p['symbol'],
            'timeframe'   => $p['timeframe'],
            'type'        => $p['type'],
            'entry_price' => $p['entry'],
            'tp_price'    => $p['tp'],
            'sl_price'    => $p['sl'],
            'winrate'     => $p['winrate'],
            'reason'      => $p['reason'],
            'capital'     => $p['capital'] ?: null,
            'status'      => 'PENDING',
            'filled_at'   => now(), // lệnh thật, theo dõi ngay
        ]);

        $dir  = $signal->type === 'LONG' ? '📈 LONG' : '📉 SHORT';
        $this->telegram->reply(
            "✅ <b>Đã ghi vào hệ thống!</b>\n\n"
            . "{$dir} <b>{$signal->symbol}</b> | {$signal->timeframe}\n"
            . "━━━━━━━━━━━━━━━\n"
            . "📌 Entry: <code>{$signal->entry_price}</code>\n"
            . "🎯 TP: <code>{$signal->tp_price}</code>\n"
            . "🛑 SL: <code>{$signal->sl_price}</code>\n"
            . "━━━━━━━━━━━━━━━\n"
            . "🆔 ID: <b>#{$signal->id}</b>\n"
            . "🔔 Bot sẽ cảnh báo khi giá tiến gần TP/SL hoặc cấu trúc phá vỡ."
        );

        $this->info("  [{$signal->symbol}] Lệnh #{$signal->id} được tạo + fill từ Telegram.");
    }

    // ─── Slash command handlers ──────────────────────────────────────────────────

    private function cmdHelp(): void
    {
        $this->telegram->reply(
            "🤖 <b>Felix — Felix Bot</b>\n\n"
            . "<b>Chat tự nhiên:</b>\n"
            . "• <code>cho tôi lệnh scalp xagusdt vốn 70u</code>\n"
            . "• <code>kèo intraday btcusdt 100$</code>\n"
            . "• <code>swing ethusdt 50u</code>\n"
            . "Sau đó gõ <b>có</b> để ghi vào hệ thống, <b>không</b> để bỏ qua.\n\n"
            . "🤖 Bot tự động theo dõi từng lệnh — sẽ báo ngay khi:\n"
            . "  🟢 Entry được khớp\n"
            . "  🎯 Giá tiến gần TP\n"
            . "  ⚠️ Giá tiến gần SL\n"
            . "  ✅ TP chạm (WIN) / 🔴 SL chạm (LOSS)\n"
            . "  🚨 Cấu trúc phá vỡ\n\n"
            . "<b>Lệnh nhanh:</b>\n"
            . "/status — P&L realtime lệnh đang chạy\n"
            . "/list — Tất cả lệnh PENDING\n"
            . "/signal &lt;id&gt; — Chi tiết lệnh\n"
            . "/cancel &lt;id&gt; — Huỷ lệnh\n"
            . "/filled &lt;id&gt; — Khớp thủ công (nếu bot chưa nhận ra)"
        );
    }

    private function cmdList(): void
    {
        $signals = TradingSignal::where('status', 'PENDING')->orderBy('created_at', 'desc')->limit(10)->get();

        if ($signals->isEmpty()) {
            $this->telegram->reply("Không có lệnh PENDING nào.\n\nNhắn tên coin + loại lệnh để phân tích mới.");
            return;
        }

        $lines = ["📋 <b>Lệnh đang theo dõi:</b>\n"];
        foreach ($signals as $s) {
            $filled  = $s->filled_at ? '🟢 ĐANG CHẠY' : '⏳ CHỜ KHỚP';
            $dir     = $s->type === 'LONG' ? '📈' : '📉';
            $lines[] = "{$dir} <b>#{$s->id} {$s->symbol}</b> {$s->timeframe} — {$filled}";
            $lines[] = "   Entry: <code>{$s->entry_price}</code> | TP: <code>{$s->tp_price}</code> | SL: <code>{$s->sl_price}</code>";
            $lines[] = "";
        }

        $this->telegram->reply(implode("\n", $lines));
    }

    private function cmdSignal(array $args): void
    {
        if (empty($args[0]) || !is_numeric($args[0])) {
            $this->telegram->reply("Cú pháp: /signal &lt;id&gt;\nVí dụ: /signal 42");
            return;
        }
        $signal = TradingSignal::find((int) $args[0]);
        if (!$signal) { $this->telegram->reply("Không tìm thấy lệnh #{$args[0]}."); return; }

        $currentPrice = (float) $this->binance->getPrice($signal->symbol);
        $this->telegram->sendSignalDetail($signal, $currentPrice);
    }

    private function cmdFilled(array $args): void
    {
        if (empty($args[0]) || !is_numeric($args[0])) {
            $this->telegram->reply("Cú pháp: /filled &lt;id&gt;"); return;
        }
        $signal = TradingSignal::where('id', (int) $args[0])->where('status', 'PENDING')->first();
        if (!$signal) { $this->telegram->reply("Không tìm thấy lệnh PENDING #{$args[0]}."); return; }
        if ($signal->filled_at) { $this->telegram->reply("Lệnh #{$signal->id} đã khớp rồi."); return; }

        $signal->update(['filled_at' => now()]);
        $this->telegram->reply("✅ Lệnh #{$signal->id} {$signal->symbol} đã khớp. Bot bắt đầu theo dõi.");
    }

    private function cmdCancel(array $args): void
    {
        if (empty($args[0]) || !is_numeric($args[0])) {
            $this->telegram->reply("Cú pháp: /cancel &lt;id&gt;"); return;
        }
        $signal = TradingSignal::where('id', (int) $args[0])->where('status', 'PENDING')->first();
        if (!$signal) { $this->telegram->reply("Không tìm thấy lệnh PENDING #{$args[0]}."); return; }

        $signal->update(['status' => 'CANCELLED']);
        $this->telegram->reply("🚫 Lệnh #{$signal->id} {$signal->symbol} đã bị huỷ.");
    }

    private function cmdStatus(): void
    {
        $filled = TradingSignal::where('status', 'PENDING')->whereNotNull('filled_at')->get();

        if ($filled->isEmpty()) {
            $this->telegram->reply("Không có lệnh nào đang chạy.\n\nNhắn tên coin + loại lệnh để phân tích mới.");
            return;
        }

        $lines = ["📊 <b>Lệnh đang chạy:</b>\n"];
        foreach ($filled as $s) {
            $price    = (float) $this->binance->getPrice($s->symbol);
            $isLong   = $s->type === 'LONG';
            $pnlPct   = $isLong
                ? round(($price - $s->entry_price) / $s->entry_price * 100, 2)
                : round(($s->entry_price - $price) / $s->entry_price * 100, 2);
            $tpDist   = round(abs($price - $s->tp_price) / $s->tp_price * 100, 2);
            $slDist   = round(abs($price - $s->sl_price) / $s->sl_price * 100, 2);
            $pnlEmoji = $pnlPct >= 0 ? '🟢' : '🔴';
            $sign     = $pnlPct >= 0 ? '+' : '';
            $dir      = $isLong ? '📈' : '📉';

            $lines[] = "{$dir} <b>#{$s->id} {$s->symbol}</b> | {$s->timeframe}";
            $lines[] = "   Giá: <code>{$price}</code> | P&L: {$pnlEmoji} <b>{$sign}{$pnlPct}%</b>";
            $lines[] = "   TP còn: {$tpDist}% | SL còn: {$slDist}%";
            $lines[] = "";
        }

        $this->telegram->reply(implode("\n", $lines));
    }
}
