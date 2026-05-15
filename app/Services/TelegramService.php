<?php

namespace App\Services;

use App\Models\TradingSignal;
use GuzzleHttp\Client;

class TelegramService
{
    private string $token;
    private string $chatId;   // primary (dùng cho reply() 1:1 với bot)
    private array  $chatIds;  // tất cả IDs (dùng cho send() broadcast)
    private string $baseUrl;

    public function __construct()
    {
        $this->token   = (string) config('services.telegram.token', '');
        $raw           = (string) config('services.telegram.chat_id', '');
        $this->chatId  = trim(explode(',', $raw)[0]);
        $this->chatIds = array_filter(array_map('trim', explode(',', $raw)));
        $this->baseUrl = "https://api.telegram.org/bot{$this->token}";
    }

    public function isConfigured(): bool
    {
        return !empty($this->token) && !empty($this->chatId);
    }

    // --- Gửi thông báo chủ động ---

    public function sendNewSignal(TradingSignal $signal, float $currentPrice, array $analysisSignal = [], ?array $posSize = null): void
    {
        $dir    = $signal->type === 'LONG' ? '📈 LONG' : '📉 SHORT';
        $slPct  = $signal->entry_price > 0 ? round(abs($signal->entry_price - $signal->sl_price) / $signal->entry_price * 100, 2) : 0;
        $tpPct  = $signal->entry_price > 0 ? round(abs($signal->tp_price - $signal->entry_price) / $signal->entry_price * 100, 2) : 0;
        $rr     = $slPct > 0 ? round($tpPct / $slPct, 1) : 0;

        $isSniper = str_contains($signal->reason ?? '', '🎯 SNIPER');
        $pattern  = $analysisSignal['pattern'] ?? ($isSniper ? 'OB + CHoCH' : 'SMC');
        $header   = $isSniper
            ? "⚡ <b>SNIPER SIGNAL</b> ⚡"
            : "🔔 <b>TÍN HIỆU MỚI — ĐANG THEO DÕI</b>";

        // Position sizing block — dùng calculatePositionSize nếu có, fallback về cách cũ
        $positionInfo = '';
        if ($posSize && $posSize['volume'] > 0) {
            $baseAsset    = str_replace('USDT', '', $signal->symbol);
            $positionInfo = "\n━━━━━━━━━━━━━━━\n"
                          . "💰 Vốn: <b>\${$signal->capital}</b> | Risk: <b>2% = \${$posSize['risk_usd']}</b>\n"
                          . "📦 Khối lượng: <code>{$posSize['volume']} {$baseAsset}</code>\n"
                          . "🔧 Đòn bẩy: <b>{$posSize['leverage']}x</b> | Ký quỹ: <code>\${$posSize['margin_usd']}</code>\n"
                          . "💀 Lỗ tối đa tại SL: <code>\${$posSize['actual_risk_usd']}</code>";
        } elseif ($signal->capital > 0 && $slPct > 0) {
            // Fallback calculation
            $riskAmt  = round($signal->capital * 0.02, 2);
            $slFrac   = $slPct / 100;
            $leverage = max(1, min(20, (int) ceil(($riskAmt / $slFrac) / $signal->capital)));
            $volume   = round($riskAmt / $slFrac, 6);
            $margin   = round($volume / $leverage / ((float)$signal->entry_price ?: 1), 4);
            $positionInfo = "\n━━━━━━━━━━━━━━━\n"
                          . "💰 Vốn: <b>\${$signal->capital}</b> | Risk: <b>2% = \${$riskAmt}</b>\n"
                          . "📦 Khối lượng: <code>{$volume}</code> | 🔧 Đòn bẩy: <b>{$leverage}x</b>";
        }

        $text = "{$header}\n\n"
              . "📊 <b>{$signal->symbol}</b> | {$signal->timeframe} | {$dir}\n"
              . "📐 Pattern: <code>{$pattern}</code>\n"
              . "━━━━━━━━━━━━━━━\n"
              . "📌 Entry: <code>{$signal->entry_price}</code>\n"
              . "🎯 TP:    <code>{$signal->tp_price}</code> (+{$tpPct}%)\n"
              . "🛑 SL:    <code>{$signal->sl_price}</code> (-{$slPct}%)\n"
              . "📐 R:R = 1:{$rr} | ⭐ Confluence: {$signal->winrate}%"
              . $positionInfo . "\n"
              . "━━━━━━━━━━━━━━━\n"
              . "🔍 <i>{$signal->reason}</i>\n\n"
              . "🆔 ID: <b>#{$signal->id}</b>\n"
              . "🤖 Bot tự động theo dõi — báo khi entry khớp, TP/SL chạm.";

        $this->send($text);
    }

    public function sendSignalDetail(TradingSignal $signal, float $currentPrice): void
    {
        $dir    = $signal->type === 'LONG' ? '📈 LONG' : '📉 SHORT';
        $isLong = $signal->type === 'LONG';
        $slPct  = $signal->entry_price > 0 ? round(abs($signal->entry_price - $signal->sl_price) / $signal->entry_price * 100, 2) : 0;
        $tpPct  = $signal->entry_price > 0 ? round(abs($signal->tp_price - $signal->entry_price) / $signal->entry_price * 100, 2) : 0;
        $rr     = $slPct > 0 ? round($tpPct / $slPct, 1) : 0;

        $pnlPct = $isLong
            ? round(($currentPrice - $signal->entry_price) / $signal->entry_price * 100, 2)
            : round(($signal->entry_price - $currentPrice) / $signal->entry_price * 100, 2);

        $tpDist = round(abs($currentPrice - $signal->tp_price) / $signal->tp_price * 100, 2);
        $slDist = round(abs($currentPrice - $signal->sl_price) / $signal->sl_price * 100, 2);

        $pnlLine = ($pnlPct >= 0 ? '🟢 +' : '🔴 ') . $pnlPct . '%';

        $statusMap = [
            'PENDING'   => $signal->filled_at ? '🟢 Đã khớp — Đang theo dõi' : '⏳ Chờ khớp lệnh',
            'WIN'       => '✅ Thắng',
            'LOSS'      => '🔴 Thua',
            'CANCELLED' => '🚫 Đã huỷ',
        ];
        $statusText = $statusMap[$signal->status] ?? $signal->status;

        $positionInfo = '';
        if ($signal->capital > 0 && $slPct > 0) {
            $riskAmt  = round($signal->capital * 0.02, 2);
            $slFrac   = $slPct / 100;
            $leverage = max(1, min(20, floor(1 / ($slFrac * 2))));
            $volume   = round($riskAmt / $slFrac, 2);
            $margin   = round($volume / $leverage, 2);
            $positionInfo = "\n━━━━━━━━━━━━━━━\n"
                          . "💰 Vốn: <b>\${$signal->capital}</b> | Đòn bẩy: <b>{$leverage}x</b>\n"
                          . "📊 Ký quỹ: <code>\${$margin}</code> | KL: <code>\${$volume}</code>\n"
                          . "💀 Lỗ tối đa: <code>\${$riskAmt}</code> (2% vốn)";
        }

        $text = "📋 <b>Chi tiết lệnh #{$signal->id}</b>\n\n"
              . "📊 <b>{$signal->symbol}</b> | {$signal->timeframe} | {$dir}\n"
              . "🔖 Trạng thái: {$statusText}\n"
              . "━━━━━━━━━━━━━━━\n"
              . "📌 Entry: <code>{$signal->entry_price}</code>\n"
              . "🎯 TP: <code>{$signal->tp_price}</code> (+{$tpPct}%) — còn {$tpDist}%\n"
              . "🛑 SL: <code>{$signal->sl_price}</code> (-{$slPct}%) — còn {$slDist}%\n"
              . "📐 R:R = 1:{$rr} | Winrate: {$signal->winrate}%\n"
              . "━━━━━━━━━━━━━━━\n"
              . "💰 Giá hiện tại: <code>{$currentPrice}</code>\n"
              . "📈 P&L hiện tại: {$pnlLine}"
              . $positionInfo . "\n"
              . "━━━━━━━━━━━━━━━\n"
              . "🔍 <i>{$signal->reason}</i>";

        $this->send($text);
    }

    public function sendEntryFilled(TradingSignal $signal, float $currentPrice): void
    {
        $dir    = $signal->type === 'LONG' ? '📈 LONG' : '📉 SHORT';
        $slPct  = $signal->entry_price > 0 ? round(abs($signal->entry_price - $signal->sl_price) / $signal->entry_price * 100, 2) : 0;
        $tpPct  = $signal->entry_price > 0 ? round(abs($signal->tp_price - $signal->entry_price) / $signal->entry_price * 100, 2) : 0;

        $text = "🟢 <b>LỆNH ĐÃ KHỚP — BẮT ĐẦU THEO DÕI</b>\n\n"
              . "📊 <b>{$signal->symbol}</b> | {$signal->timeframe} | {$dir}\n"
              . "━━━━━━━━━━━━━━━\n"
              . "📌 Entry: <code>{$signal->entry_price}</code>\n"
              . "🎯 TP: <code>{$signal->tp_price}</code> (+{$tpPct}%)\n"
              . "🛑 SL: <code>{$signal->sl_price}</code> (-{$slPct}%)\n"
              . "💰 Giá hiện tại: <code>{$currentPrice}</code>\n"
              . "━━━━━━━━━━━━━━━\n"
              . "🔔 Bot đang theo dõi tự động. Gõ /signal {$signal->id} để xem chi tiết.";

        $this->send($text);
    }

    public function sendUnfilledExpiry(TradingSignal $signal, float $currentPrice, int $hours): void
    {
        $dir = $signal->type === 'LONG' ? '📈 LONG' : '📉 SHORT';
        $priceDiff = $signal->entry_price > 0
            ? round(abs($currentPrice - $signal->entry_price) / $signal->entry_price * 100, 2)
            : 0;

        $text = "⏰ <b>LỆNH CHƯA KHỚP QUÁ {$hours}H — ĐỀ XUẤT HUỶ</b>\n\n"
              . "📊 <b>{$signal->symbol}</b> | {$signal->timeframe} | {$dir}\n"
              . "━━━━━━━━━━━━━━━\n"
              . "📌 Entry: <code>{$signal->entry_price}</code>\n"
              . "🎯 TP: <code>{$signal->tp_price}</code>\n"
              . "🛑 SL: <code>{$signal->sl_price}</code>\n"
              . "💰 Giá hiện tại: <code>{$currentPrice}</code> ({$priceDiff}% cách entry)\n"
              . "━━━━━━━━━━━━━━━\n"
              . "🆔 Lệnh #{$signal->id} tạo lúc <b>{$signal->created_at->format('H:i d/m')}</b>\n\n"
              . "⚡ <b>Đã quá {$hours}h chưa khớp — đã tự động huỷ lệnh.</b>\n"
              . "Dùng /signal {$signal->id} để xem chi tiết.";

        $this->send($text);
    }

    public function sendPreEntryStructureBreak(TradingSignal $signal, float $currentPrice, string $newTrend): void
    {
        $dir = $signal->type === 'LONG' ? '📈 LONG' : '📉 SHORT';

        $text = "🚨 <b>SETUP VÔ HIỆU — CHƯA KHỚP</b>\n\n"
              . "📊 <b>{$signal->symbol}</b> | {$signal->timeframe} | {$dir}\n"
              . "━━━━━━━━━━━━━━━\n"
              . "📌 Entry: <code>{$signal->entry_price}</code>\n"
              . "💰 Giá hiện tại: <code>{$currentPrice}</code>\n"
              . "━━━━━━━━━━━━━━━\n"
              . "🔍 Xu hướng mới: <b>{$newTrend}</b> — ngược chiều\n\n"
              . "⚡ <b>Cấu trúc phá vỡ trước khi vào lệnh — đã huỷ tự động.</b>\n"
              . "Dùng /cancel {$signal->id} nếu cần xác nhận thủ công.";

        $this->send($text);
    }

    public function sendStructureBreak(TradingSignal $signal, float $currentPrice, string $newTrend): void
    {
        $dir    = $signal->type === 'LONG' ? '📈 LONG' : '📉 SHORT';
        $slDist = $signal->sl_price > 0 ? round(abs($currentPrice - $signal->sl_price) / $signal->sl_price * 100, 2) : 0;

        $text = "🚨 <b>CẤU TRÚC PHÁ VỠ — SETUP HẾT TÁC DỤNG</b>\n\n"
              . "📊 <b>{$signal->symbol}</b> | {$signal->timeframe} | {$dir}\n"
              . "━━━━━━━━━━━━━━━\n"
              . "📌 Entry: <code>{$signal->entry_price}</code>\n"
              . "🎯 TP: <code>{$signal->tp_price}</code>\n"
              . "🛑 SL: <code>{$signal->sl_price}</code>\n"
              . "💰 Giá hiện tại: <code>{$currentPrice}</code>\n"
              . "━━━━━━━━━━━━━━━\n"
              . "🔍 Xu hướng mới: <b>{$newTrend}</b> — ngược chiều lệnh\n"
              . "📏 Còn cách SL: {$slDist}%\n\n"
              . "⚡ <b>Khuyến nghị:</b> Cân nhắc đóng lệnh sớm, setup không còn hợp lệ.";

        $this->send($text);
    }

    public function sendNearTp(TradingSignal $signal, float $currentPrice): void
    {
        $dir    = $signal->type === 'LONG' ? '📈 LONG' : '📉 SHORT';
        $tpDist = $signal->tp_price > 0 ? round(abs($currentPrice - $signal->tp_price) / $signal->tp_price * 100, 2) : 0;

        $text = "🎯 <b>SẮP CHẠM TP — CÂN NHẮC HÀNH ĐỘNG</b>\n\n"
              . "📊 <b>{$signal->symbol}</b> | {$signal->timeframe} | {$dir}\n"
              . "━━━━━━━━━━━━━━━\n"
              . "💰 Giá hiện tại: <code>{$currentPrice}</code>\n"
              . "🎯 TP: <code>{$signal->tp_price}</code> — còn <b>{$tpDist}%</b>\n"
              . "📌 Entry: <code>{$signal->entry_price}</code>\n"
              . "━━━━━━━━━━━━━━━\n"
              . "💡 Gợi ý: Dời SL lên breakeven hoặc chốt một phần lệnh.";

        $this->send($text);
    }

    public function sendNearSl(TradingSignal $signal, float $currentPrice): void
    {
        $dir    = $signal->type === 'LONG' ? '📈 LONG' : '📉 SHORT';
        $slDist = $signal->sl_price > 0 ? round(abs($currentPrice - $signal->sl_price) / $signal->sl_price * 100, 2) : 0;

        $text = "⚠️ <b>TIẾN GẦN CẮT LỖ</b>\n\n"
              . "📊 <b>{$signal->symbol}</b> | {$signal->timeframe} | {$dir}\n"
              . "━━━━━━━━━━━━━━━\n"
              . "💰 Giá hiện tại: <code>{$currentPrice}</code>\n"
              . "🛑 SL: <code>{$signal->sl_price}</code>\n"
              . "📏 Khoảng cách còn lại: <b>{$slDist}%</b>\n"
              . "━━━━━━━━━━━━━━━\n"
              . "💡 Theo dõi chặt. Sẵn sàng đóng lệnh thủ công nếu cần.";

        $this->send($text);
    }

    public function sendSlHit(TradingSignal $signal, float $currentPrice): void
    {
        $dir = $signal->type === 'LONG' ? '📈 LONG' : '📉 SHORT';

        $text = "🔴 <b>CẮT LỖ — SL ĐÃ BỊ CHẠM</b>\n\n"
              . "📊 <b>{$signal->symbol}</b> | {$signal->timeframe} | {$dir}\n"
              . "━━━━━━━━━━━━━━━\n"
              . "📌 Entry: <code>{$signal->entry_price}</code>\n"
              . "🛑 SL hit: <code>{$signal->sl_price}</code>\n"
              . "💰 Giá hiện tại: <code>{$currentPrice}</code>\n"
              . "━━━━━━━━━━━━━━━\n"
              . "📝 {$signal->reason}";

        $this->send($text);
    }

    public function sendTpHit(TradingSignal $signal, float $currentPrice): void
    {
        $dir  = $signal->type === 'LONG' ? '📈 LONG' : '📉 SHORT';
        $gain = $signal->entry_price > 0 ? round(abs($signal->tp_price - $signal->entry_price) / $signal->entry_price * 100, 2) : 0;

        $text = "✅ <b>CHỐT LỜI — TP ĐÃ CHẠM</b>\n\n"
              . "📊 <b>{$signal->symbol}</b> | {$signal->timeframe} | {$dir}\n"
              . "━━━━━━━━━━━━━━━\n"
              . "📌 Entry: <code>{$signal->entry_price}</code>\n"
              . "🎯 TP hit: <code>{$signal->tp_price}</code> (+{$gain}%)\n"
              . "💰 Giá hiện tại: <code>{$currentPrice}</code>\n"
              . "━━━━━━━━━━━━━━━\n"
              . "🏆 Lệnh thắng! Cập nhật nhật ký giao dịch.";

        $this->send($text);
    }

    public function sendTestMessage(): void
    {
        $this->send(
            "✅ <b>Felix Bot đang hoạt động</b>\n\n"
            . "Gõ /help để xem danh sách lệnh.\n\n"
            . "Bot sẽ tự động ping khi:\n"
            . "🔔 Có tín hiệu mới được đề xuất\n"
            . "🎯 Giá tiến gần TP\n"
            . "⚠️ Giá tiến gần SL\n"
            . "🚨 Cấu trúc phá vỡ\n"
            . "✅ TP hoặc 🔴 SL bị chạm"
        );
    }

    // --- Bot polling (dùng bởi TelegramBotCommand) ---

    public function getUpdates(int $offset = 0): array
    {
        try {
            $res = (new Client())->get("{$this->baseUrl}/getUpdates", [
                'query'   => ['offset' => $offset, 'timeout' => 30, 'limit' => 10],
                'timeout' => 35,
            ]);
            $data = json_decode($res->getBody(), true);
            return $data['ok'] ? ($data['result'] ?? []) : [];
        } catch (\Exception $e) {
            \Log::warning('Telegram getUpdates failed: ' . $e->getMessage());
            return [];
        }
    }

    public function reply(string $text, ?string $targetChatId = null): void
    {
        $this->sendTo($targetChatId ?? $this->chatId, $text);
    }

    public function sendRaw(string $text): void
    {
        $this->send($text);
    }

    private function sendTo(string $chatId, string $text): void
    {
        $chunks = $this->splitMessage($text, 4000);
        foreach ($chunks as $chunk) {
            try {
                (new Client())->post("{$this->baseUrl}/sendMessage", [
                    'json' => [
                        'chat_id'    => $chatId,
                        'text'       => $chunk,
                        'parse_mode' => 'HTML',
                    ],
                    'timeout' => 10,
                ]);
            } catch (\Exception $e) {
                \Log::error("Telegram sendTo [{$chatId}] failed: " . $e->getMessage());
            }
        }
    }

    // Backtest stats Jan-Apr 2026 — 15m, 1:2.5 RR, AI-risk ($8 nếu AI≥85, $2 nếu <85)
    private array $backtestStats = [
        'SOLUSDT'  => ['wr' => 41.5, 'ev' => 0.64, 'signals' => 41, 'pnl' => '+54%'],
        'XAGUSDT'  => ['wr' => 39.4, 'ev' => 0.38, 'signals' => 33, 'pnl' => '+73%'],
        'LINKUSDT' => ['wr' => 34.8, 'ev' => 0.39, 'signals' => 46, 'pnl' => '+36%'],
        'XAUUSDT'  => ['wr' => 37.0, 'ev' => 0.48, 'signals' => 27, 'pnl' => '+26%'],
        'BTCUSDT'  => ['wr' => 34.4, 'ev' => 0.38, 'signals' => 32, 'pnl' => '+13%'],
        'ETHUSDT'  => ['wr' => 32.1, 'ev' => 0.28, 'signals' => 29, 'pnl' => '+16%'],
    ];

    public function sendScanAlert(string $symbol, string $timeframe, array $signal, float $currentPrice, string $method = 'smc', int $riskPct = 2, float $capital = 0): void
    {
        $type     = $signal['type'] ?? 'N/A';
        $entry    = $signal['entry'] ?? 0;
        $tp       = $signal['tp']    ?? 0;
        $sl       = $signal['sl']    ?? 0;
        $conf     = $signal['winrate'] ?? 0;
        $reason   = $signal['reason'] ?? '';
        $pattern  = $signal['pattern'] ?? 'SMC';
        $isSniper = str_contains($reason, '🎯 SNIPER');

        $slPct = $entry > 0 ? round(abs($entry - $sl) / $entry * 100, 2) : 0;
        $tpPct = $entry > 0 ? round(abs($tp - $entry) / $entry * 100, 2) : 0;
        $rr    = $slPct > 0 ? round($tpPct / $slPct, 1) : 0;

        $dir      = str_contains(strtolower($type), 'mua') ? '📈 LONG' : '📉 SHORT';
        $header   = $isSniper ? '⚡ <b>SNIPER SETUP DETECTED</b> ⚡' : '🔍 <b>SETUP MỚI — AUTO SCAN</b>';

        $priceDiff = $entry > 0 ? round(abs($currentPrice - $entry) / $entry * 100, 2) : 0;
        $proximity = $currentPrice <= $entry
            ? "Giá đang <b>tại/dưới entry</b> ({$priceDiff}% cách entry)"
            : "Giá cách entry <b>{$priceDiff}%</b> — chờ retest";

        // AI verdict block
        $aiScore   = (int) ($signal['ai_score']          ?? 0);
        $aiAnalysis = $signal['ai_analysis']              ?? '';
        $aiRisk     = $signal['ai_risk']                  ?? '';
        $aiRec      = $signal['ai_recommendation']        ?? '';
        $aiTiming   = $signal['ai_entry_timing']          ?? '';

        $aiEmoji     = $aiScore >= 85 ? '⚡' : ($aiScore >= 75 ? '🟢' : ($aiScore >= 60 ? '🟡' : '🔴'));
        $aiRiskLabel = $riskPct >= 5
            ? "⚡ <b>HIGH CONFIDENCE</b> — AI≥85 → Risk <b>{$riskPct}%</b>"
            : "📊 <b>NORMAL</b> — AI&lt;85 → Risk <b>{$riskPct}%</b>";
        $aiBlock  = "━━━━━━━━━━━━━━━\n"
                  . "🤖 <b>AI Score: {$aiEmoji} {$aiScore}/100</b> | {$aiRiskLabel}\n";
        if ($aiAnalysis) $aiBlock .= "🔍 {$aiAnalysis}\n";
        if ($aiRisk)     $aiBlock .= "⚠️ {$aiRisk}\n";
        if ($aiRec)      $aiBlock .= "💡 <b>{$aiRec}</b>\n";
        if ($aiTiming)   $aiBlock .= "⏱ {$aiTiming}\n";

        // Backtest stats block
        $stats    = $this->backtestStats[$symbol] ?? null;
        $statsTf  = $timeframe === '15m' ? '15m' : ($timeframe === '1h' ? '1h' : $timeframe);
        $evSign   = ($stats['ev'] ?? 0) >= 0 ? '+' : '';
        $wrEmoji  = ($stats['wr'] ?? 0) >= 40 ? '🟢' : (($stats['wr'] ?? 0) >= 33 ? '🟡' : '🔴');
        $statsBlock = $stats
            ? "━━━━━━━━━━━━━━━\n"
              . "📊 <b>Backtest {$statsTf} (Jan-Apr 2026, 1:2.5 RR + AI-risk)</b>\n"
              . "{$wrEmoji} WR: <b>{$stats['wr']}%</b>  |  EV: <b>{$evSign}{$stats['ev']}R</b>/lệnh\n"
              . "📈 P&L 4 tháng: <b>{$stats['pnl']}</b>  |  {$stats['signals']} signals\n"
            : '';

        $appUrl = rtrim(env('APP_URL', 'http://localhost'), '/');
        $link   = "{$appUrl}/?symbol={$symbol}&timeframe={$timeframe}";

        $this->send(
            $header . "\n"
            . "📊 Method: <b>📐 SMC Smart Money</b>\n"
            . "━━━━━━━━━━━━━━━\n"
            . "💎 <b>{$symbol}</b> · {$timeframe} · {$dir}\n"
            . "🏷 Pattern: <code>{$pattern}</code>\n"
            . "━━━━━━━━━━━━━━━\n"
            . "📌 Entry : <code>{$entry}</code>\n"
            . "🎯 TP    : <code>{$tp}</code> (+{$tpPct}%) ← 1:2.5 R:R\n"
            . "🛑 SL    : <code>{$sl}</code> (-{$slPct}%)\n"
            . "📐 R:R   : 1:{$rr} | ⭐ Confluence: {$conf}%\n"
            . "💵 Risk {$riskPct}%: WIN <b>+" . round($riskPct * $rr, 1) . "%</b> vốn | LOSS <b>-{$riskPct}%</b> vốn\n"
            . ($capital > 0 && $slPct > 0 ? (function() use ($capital, $riskPct, $slPct) {
                $riskAmt  = round($capital * $riskPct / 100, 2);
                $notional = round($riskAmt / ($slPct / 100), 2);
                $leverage = max(1, min(20, (int) ceil($notional / $capital)));
                $margin   = round($notional / $leverage, 2);
                return "📦 Vol: <b>\${$notional} USDT</b> | x{$leverage} | Margin: <b>\${$margin}</b> | Risk: <b>\${$riskAmt}</b>\n";
            })() : '')
            . "━━━━━━━━━━━━━━━\n"
            . "💰 Giá hiện tại: <code>{$currentPrice}</code>\n"
            . "📍 {$proximity}\n"
            . $statsBlock
            . $aiBlock
            . "━━━━━━━━━━━━━━━\n"
            . "🔍 <i>{$reason}</i>\n\n"
            . "🖥 <a href=\"{$link}\">Xem chart →</a>\n\n"
            . "✅ Gõ <b>ok</b> → kiểm tra tâm lý pre-flight &amp; vào lệnh\n"
            . "❌ Gõ <b>không</b> để bỏ qua"
        );
    }

    public function sendZoneApproachAlert(
        string $symbol, string $timeframe,
        bool $isDemand, float $high, float $low,
        float $price, float $distPct, string $htfTrend
    ): void {
        $dir      = $isDemand ? 'DEMAND 🟢' : 'SUPPLY 🔴';
        $type     = $isDemand ? '🟢 LONG' : '🔴 SHORT';
        $htfLabel = $htfTrend === 'TĂNG GIÁ' ? '📈 TĂNG' : ($htfTrend === 'GIẢM GIÁ' ? '📉 GIẢM' : '↔ NGANG');
        $distLabel = number_format($distPct * 100, 2);
        $sym      = str_replace('USDT', '/USDT', $symbol);

        // Tính entry/SL/TP để đặt pending ngay
        $entry  = $isDemand ? $high : $low;
        $buffer = $entry * 0.001;
        $sl     = $isDemand ? round($low - $buffer, 4) : round($high + $buffer, 4);
        $slDist = abs($entry - $sl);
        $tp     = $isDemand
            ? round($entry + $slDist * 2.5, 4)
            : round($entry - $slDist * 2.5, 4);
        $tpPct  = number_format(abs($tp - $entry) / $entry * 100, 1);
        $slPct  = number_format(abs($sl - $entry) / $entry * 100, 1);

        $text = "⚠️ <b>{$sym} {$timeframe} — TIẾP CẬN OB</b>\n"
              . "📍 {$dir}: <code>" . number_format($low, 2) . " – " . number_format($high, 2) . "</code>\n"
              . "💰 Giá hiện tại: <b>" . number_format($price, 2) . "</b> (cách <b>{$distLabel}%</b>)\n"
              . "📊 HTF: {$htfLabel}\n\n"
              . "{$type} | Đặt pending:\n"
              . "📌 Entry: <code>" . number_format($entry, 2) . "</code>\n"
              . "🎯 TP: <code>" . number_format($tp, 2) . "</code> (+{$tpPct}%)\n"
              . "🛡 SL: <code>" . number_format($sl, 2) . "</code> (-{$slPct}%) | R:R 1:2.5";

        $this->send($text);
    }

    public function sendZoneHitAlert(
        string $symbol, string $timeframe,
        bool $isDemand, float $entry, float $tp, float $sl,
        int $score, int $riskPct, float $capital = 0
    ): void {
        $type      = $isDemand ? '🟢 LONG' : '🔴 SHORT';
        $scoreIcon = $score >= 85 ? '⚡ HIGH' : '📊 NORMAL';
        $tpPct     = number_format(abs($tp - $entry) / $entry * 100, 1);
        $slPct     = number_format(abs($sl - $entry) / $entry * 100, 1);
        $sym       = str_replace('USDT', '/USDT', $symbol);

        $lotLine = '';
        if ($capital > 0 && abs($sl - $entry) > 0) {
            $slFrac   = abs($sl - $entry) / $entry;
            $riskAmt  = round($capital * $riskPct / 100, 2);
            $notional = $slFrac > 0 ? round($riskAmt / $slFrac, 2) : 0;
            $leverage = $capital > 0 && $notional > 0 ? max(1, min(20, (int) ceil($notional / $capital))) : 1;
            $margin   = $leverage > 0 ? round($notional / $leverage, 2) : 0;
            if ($notional > 0) {
                $lotLine = "📦 Vol: <b>\${$notional}</b> | x{$leverage} | Margin: <b>\${$margin}</b> | Risk: <b>\${$riskAmt}</b>\n";
            }
        }

        $text = "🎯 <b>{$sym} {$timeframe} — CHẠM OB</b>\n"
              . "📊 Score: <b>{$score}/100</b> {$scoreIcon}\n\n"
              . "{$type} | <b>Entry: " . number_format($entry, 2) . "</b>\n"
              . "🎯 TP: <code>" . number_format($tp, 2) . "</code> (+{$tpPct}%)\n"
              . "🛡 SL: <code>" . number_format($sl, 2) . "</code> (-{$slPct}%) | R:R 1:2.5\n"
              . "💰 Risk: <b>{$riskPct}%</b>\n"
              . $lotLine
              . "\n✅ Gõ <b>ok</b> để vào lệnh | ❌ Bỏ qua để huỷ";

        $this->send($text);
    }

    // --- Internal ---

    private function send(string $text): void
    {
        if (!$this->isConfigured()) return;

        // Telegram max 4096 chars — split nếu dài hơn
        $chunks = $this->splitMessage($text, 4000);
        foreach ($this->chatIds as $chatId) {
            foreach ($chunks as $chunk) {
                try {
                    (new Client())->post("{$this->baseUrl}/sendMessage", [
                        'json' => [
                            'chat_id'    => $chatId,
                            'text'       => $chunk,
                            'parse_mode' => 'HTML',
                        ],
                        'timeout' => 10,
                    ]);
                } catch (\Exception $e) {
                    \Log::error("Telegram send failed [{$chatId}]: " . $e->getMessage());
                }
            }
        }
    }

    private function splitMessage(string $text, int $maxLen): array
    {
        if (mb_strlen($text) <= $maxLen) return [$text];

        $chunks = [];
        while (mb_strlen($text) > $maxLen) {
            // Split tại newline gần nhất trước maxLen
            $pos = mb_strrpos(mb_substr($text, 0, $maxLen), "\n") ?: $maxLen;
            $chunks[] = mb_substr($text, 0, $pos);
            $text = mb_substr($text, $pos + 1);
        }
        if ($text !== '') $chunks[] = $text;
        return $chunks;
    }

    public function sendCryptoSignalAlert(
        string $symbol,
        string $timeframe,
        array  $signal,
        float  $currentPrice,
        float  $capital = 0,
        float  $fundingRate = 0,
        float  $lsRatio = 0.5
    ): void {
        $type      = $signal['type'] === 'LONG' ? '🟢 LONG' : '🔴 SHORT';
        $entry     = $signal['entry'] ?? $currentPrice;
        $tp        = $signal['tp'] ?? 0;
        $sl        = $signal['sl'] ?? 0;
        $tpPct     = $entry > 0 ? round(abs($tp - $entry) / $entry * 100, 2) : 0;
        $slPct     = $entry > 0 ? round(abs($sl - $entry) / $entry * 100, 2) : 0;
        $rr        = $slPct > 0 ? round($tpPct / $slPct, 1) : 0;

        $fundingStr   = ($fundingRate >= 0 ? '+' : '') . round($fundingRate * 100, 4) . '%';
        $lsPct        = round($lsRatio * 100, 1);
        $fundingEmoji = $fundingRate > 0.0003 ? '🔴' : ($fundingRate < -0.0001 ? '🟢' : '⚪');
        $lsEmoji      = $lsRatio > 0.55 ? '🟢' : ($lsRatio < 0.45 ? '🔴' : '⚪');

        $msg  = "📡 <b>{$symbol} {$timeframe} — CRYPTO SIGNAL</b>\n";
        $msg .= "━━━━━━━━━━━━━━━\n";
        $msg .= "{$type} | Entry: <b>{$entry}</b>\n";
        $msg .= "🎯 TP: <b>{$tp}</b> (+{$tpPct}%)\n";
        $msg .= "🛡 SL: <b>{$sl}</b> (-{$slPct}%) | R:R 1:{$rr}\n";
        $msg .= "━━━━━━━━━━━━━━━\n";
        $msg .= "{$fundingEmoji} Funding: <b>{$fundingStr}</b>\n";
        $msg .= "{$lsEmoji} Top Traders Long: <b>{$lsPct}%</b>\n";

        if ($capital > 0) {
            $risk   = $capital * 0.02;
            $slDist = abs($entry - $sl);
            $vol    = $slDist > 0 ? round($risk / $slDist, 2) : 0;
            $margin = round($entry * $vol / 20, 2);
            $msg .= "━━━━━━━━━━━━━━━\n";
            $msg .= "💰 Risk 2%: <b>\${$risk}</b> | Vol: <b>{$vol}</b> | Margin x20: <b>\${$margin}</b>\n";
        }

        $reason = $signal['reason'] ?? '';
        if ($reason) $msg .= "📝 <i>{$reason}</i>\n";

        $this->sendRaw($msg);
    }
}
