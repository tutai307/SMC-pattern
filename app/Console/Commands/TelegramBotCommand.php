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
    // Dùng word boundary (\b) khi match, và chỉ áp dụng khi message ≤ 3 từ
    private array $confirmWords = ['có', 'ok', 'yes', 'ừ', 'vào', 'vao', 'theo dõi', 'ghi', 'xác nhận', 'xac nhan', 'đồng ý', 'dong y'];
    private array $rejectWords  = ['không', 'khong', 'no', 'thôi', 'thoi', 'hủy', 'huy', 'cancel', 'skip', 'bỏ qua'];

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
        $lower          = mb_strtolower($text);
        $pendingKey     = "tg_pending_{$this->chatId}";
        $scanPendingKey = "scan_pending_{$this->chatId}";
        $hasPending     = Cache::has($pendingKey) || Cache::has($scanPendingKey);

        // Xác nhận / từ chối — chỉ áp dụng khi message ngắn (≤ 3 từ)
        $wordCount = count(array_filter(preg_split('/\s+/u', trim($text))));
        if ($wordCount <= 3) {
            $isConfirmWord = false;
            foreach ($this->confirmWords as $w) {
                if (preg_match('/(?<![a-zA-Z])' . preg_quote($w, '/') . '(?![a-zA-Z])/ui', $lower)) {
                    $isConfirmWord = true;
                    break;
                }
            }
            if ($isConfirmWord) {
                if ($hasPending) {
                    $this->confirmPendingSignal();
                } else {
                    $this->telegram->reply("Không có lệnh nào đang chờ xác nhận.\n\nNhắn tên coin để phân tích, ví dụ: <code>xagusdt h1</code>");
                }
                return;
            }

            foreach ($this->rejectWords as $w) {
                if ($hasPending && preg_match('/(?<![a-zA-Z])' . preg_quote($w, '/') . '(?![a-zA-Z])/ui', $lower)) {
                    Cache::forget($pendingKey);
                    Cache::forget($scanPendingKey);
                    $this->telegram->reply("Ok, bỏ qua. Nhắn lại bất cứ lúc nào.");
                    return;
                }
            }
        }

        // Yêu cầu phân tích coin cụ thể → dùng parser nhanh
        $parsed = $this->parseSignalRequest($text);
        if ($parsed) {
            $this->runAnalysis($parsed);
            return;
        }

        // Mọi thứ còn lại → AI trả lời tự nhiên
        $this->askAI($text);
    }

    // ─── AI conversational brain ─────────────────────────────────────────────────

    private function askAI(string $userMessage): void
    {
        $apiKey = env('OPENROUTER_API_KEY');
        if (!$apiKey) {
            $this->telegram->reply("AI chưa cấu hình (thiếu OPENROUTER_API_KEY).");
            return;
        }

        // Lấy context thực tế
        $pendingSignals = TradingSignal::where('status', 'PENDING')->orderBy('created_at', 'desc')->limit(5)->get();
        $runningSignals = TradingSignal::where('status', 'PENDING')->whereNotNull('filled_at')->get();
        $watchlist      = env('SCAN_SYMBOLS', 'XAGUSDT:15m,VVVUSDT:15m');
        $now            = now()->format('d/m/Y H:i');

        // Tóm tắt lệnh đang mở
        $runningStr = '';
        foreach ($runningSignals as $s) {
            $price  = $this->binance->getPrice($s->symbol) ?? $s->entry_price;
            $pnlPct = $s->entry_price > 0
                ? round((($s->type === 'LONG' ? ($price - $s->entry_price) : ($s->entry_price - $price)) / $s->entry_price) * 100, 2)
                : 0;
            $sign   = $pnlPct >= 0 ? '+' : '';
            $runningStr .= "- #{$s->id} {$s->symbol} {$s->type} entry={$s->entry_price} giá_hiện_tại={$price} P&L={$sign}{$pnlPct}% TP={$s->tp_price} SL={$s->sl_price}\n";
        }
        if (!$runningStr) $runningStr = "Không có lệnh nào đang chạy.";

        $pendingStr = '';
        foreach ($pendingSignals as $s) {
            $pendingStr .= "- #{$s->id} {$s->symbol} {$s->type} entry={$s->entry_price} status=" . ($s->filled_at ? 'RUNNING' : 'PENDING') . "\n";
        }
        if (!$pendingStr) $pendingStr = "Không có lệnh PENDING.";

        // Thống kê gần đây
        $wins   = TradingSignal::where('status', 'WIN')->count();
        $losses = TradingSignal::where('status', 'LOSS')->count();
        $total  = $wins + $losses;
        $wrStr  = $total > 0 ? round($wins / $total * 100) . "% ({$wins}W/{$losses}L)" : "Chưa có dữ liệu";

        $systemPrompt = <<<PROMPT
Bạn là Felix — AI trading assistant của hệ thống TOM AI. Nhiệm vụ chính:
1. Theo dõi và cảnh báo tín hiệu SMC cho {$watchlist}
2. Quản lý lệnh đang mở, báo P&L, cảnh báo SL/TP
3. Trả lời câu hỏi về thị trường và tín hiệu

TÍNH CÁCH: Thân thiện, ngắn gọn, chuyên nghiệp. Nói chuyện như người thật, không như chatbot.
Dùng tiếng Việt. Dùng emoji phù hợp nhưng đừng lạm dụng.
KHÔNG bịa số liệu. Nếu không biết → nói thẳng.

=== TRẠNG THÁI HỆ THỐNG ({$now}) ===
Watchlist đang scan: {$watchlist}
Lịch sử thắng/thua: {$wrStr}

LỆNH ĐANG CHẠY:
{$runningStr}
LỆNH PENDING:
{$pendingStr}

=== KHẢ NĂNG ===
- Phân tích coin: user nhắn "kèo xagusdt scalp" hoặc "phân tích btcusdt"
- Xem lệnh: /list, /status, /signal <id>
- Quản lý: /cancel <id>, /filled <id>
- Bot tự động scan {$watchlist} mỗi 5 phút và sẽ báo ngay khi có setup

Trả lời NGẮN GỌN (tối đa 4-5 câu). Nếu user hỏi về setup cụ thể thì bảo họ nhắn "kèo [coin] [loại]".
PROMPT;

        // Lịch sử hội thoại (rolling 8 messages)
        $historyKey = "tg_ai_history_{$this->chatId}";
        $history    = Cache::get($historyKey, []);

        // Thêm tin nhắn user mới vào history
        $history[] = ['role' => 'user', 'content' => $userMessage];

        // Giữ tối đa 8 messages gần nhất
        if (count($history) > 8) {
            $history = array_slice($history, -8);
        }

        try {
            $client   = new \GuzzleHttp\Client(['timeout' => 15, 'connect_timeout' => 5]);
            $response = $client->post('https://openrouter.ai/api/v1/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type'  => 'application/json',
                    'HTTP-Referer'  => 'https://tomai.app',
                ],
                'json' => [
                    'model'       => 'openai/gpt-4o-mini',
                    'temperature' => 0.7,
                    'max_tokens'  => 300,
                    'messages'    => array_merge(
                        [['role' => 'system', 'content' => $systemPrompt]],
                        $history
                    ),
                ],
            ]);

            $result  = json_decode($response->getBody(), true);
            $reply   = trim($result['choices'][0]['message']['content'] ?? '');

            if (!$reply) {
                $this->telegram->reply("Hmm, tôi không hiểu lắm. Thử nói lại nhé.");
                return;
            }

            // Lưu reply của AI vào history
            $history[] = ['role' => 'assistant', 'content' => $reply];
            Cache::put($historyKey, array_slice($history, -8), now()->addHours(1));

            $this->telegram->reply($reply);

        } catch (\Exception $e) {
            \Log::warning('TelegramBot AI: ' . $e->getMessage());
            $this->telegram->reply("Xin lỗi, AI đang bận. Thử lại sau hoặc dùng /help để xem lệnh.");
        }
    }

    // ─── Natural language parser ─────────────────────────────────────────────────

    private function parseSignalRequest(string $text): ?array
    {
        $lower = mb_strtolower($text);

        // Detect timeframe trực tiếp từ text (ưu tiên nhất)
        $tfKeywords = ['swing', '4h', 'h4', '1d', 'd1', 'intraday', '1h', 'h1', 'day', 'scalp', '15m', 'm15', '5m', 'm5', '1m', 'm1'];
        $hasKeyword = false;
        foreach (array_merge($tfKeywords, ['lệnh', 'lenh', 'kèo', 'keo', 'cho tôi', 'cho toi', 'phân tích', 'phan tich', 'xem']) as $kw) {
            if (str_contains($lower, $kw)) { $hasKeyword = true; break; }
        }

        // Detect loại giao dịch → timeframe (regex ưu tiên hơn keyword check)
        $tradeType = 'scalp'; // default 15m
        if (preg_match('/\b(4h|h4|swing|1d|d1)\b/i', $lower)) {
            $tradeType = 'swing';
        } elseif (preg_match('/\b(1h|h1|intraday|day)\b/i', $lower)) {
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
        $pendingKey     = "tg_pending_{$this->chatId}";
        $scanPendingKey = "scan_pending_{$this->chatId}";

        // Ưu tiên pending từ bot analysis; fallback về scan alert
        $p         = Cache::get($pendingKey) ?? Cache::get($scanPendingKey);
        $isScanSig = !Cache::has($pendingKey) && Cache::has($scanPendingKey);

        if (!$p) {
            $this->telegram->reply("Không có lệnh nào đang chờ xác nhận. Nhắn tôi tên coin và loại lệnh để phân tích mới.");
            return;
        }

        // Validate setup vẫn còn hợp lệ trước khi lưu
        $currentPrice = (float) $this->binance->getPrice($p['symbol']);
        $isLong       = $p['type'] === 'LONG';

        // Kiểm tra SL chưa bị chạm
        $slHit = $isLong ? ($currentPrice <= (float) $p['sl']) : ($currentPrice >= (float) $p['sl']);
        if ($slHit) {
            Cache::forget($pendingKey);
            Cache::forget($scanPendingKey);
            $this->telegram->reply(
                "⚠️ <b>Setup đã vô hiệu!</b>\n\n"
                . "Giá hiện tại <code>{$currentPrice}</code> đã vượt qua SL <code>{$p['sl']}</code>.\n"
                . "Lệnh bị huỷ tự động — không nên vào. Phân tích lại."
            );
            return;
        }

        // Kiểm tra cấu trúc thị trường
        $klines    = $this->binance->getKlines($p['symbol'], $p['timeframe'], 100);
        $structure = $this->priceAction->getStructure($klines);
        $broken    = $isLong
            ? ($structure['choch'] && $structure['trend'] === 'GIẢM GIÁ')
            : ($structure['choch'] && $structure['trend'] === 'TĂNG GIÁ');

        if ($broken) {
            Cache::forget($pendingKey);
            Cache::forget($scanPendingKey);
            $this->telegram->reply(
                "🚨 <b>Cấu trúc đã đảo chiều!</b>\n\n"
                . "Xu hướng mới: <b>{$structure['trend']}</b> — ngược chiều lệnh {$p['type']}.\n"
                . "Setup không còn hợp lệ. Không vào lệnh."
            );
            return;
        }

        // Cảnh báo nếu giá đã di chuyển xa entry (> 1%)
        $distPct = $p['entry'] > 0 ? round(abs($currentPrice - $p['entry']) / $p['entry'] * 100, 2) : 0;
        if ($distPct > 1) {
            $this->telegram->reply(
                "⚠️ Giá đã cách entry <b>{$distPct}%</b> kể từ khi phân tích.\n"
                . "Entry: <code>{$p['entry']}</code> | Giá hiện tại: <code>{$currentPrice}</code>\n"
                . "Vẫn tiếp tục ghi lệnh..."
            );
        }

        Cache::forget($pendingKey);
        Cache::forget($scanPendingKey);

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
