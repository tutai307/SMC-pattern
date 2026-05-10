<?php

namespace App\Services;

use App\Models\TradingSignal;
use GuzzleHttp\Client;

class TelegramService
{
    private string $token;
    private string $chatId;
    private string $baseUrl;

    public function __construct()
    {
        $this->token   = (string) config('services.telegram.token', '');
        $this->chatId  = (string) config('services.telegram.chat_id', '');
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

    public function reply(string $text): void
    {
        $this->send($text);
    }

    public function sendRaw(string $text): void
    {
        $this->send($text);
    }

    // Backtest stats Jan-May 2026 — 15m, 1:3 RR, $2/trade (walk-forward, no look-ahead)
    private array $backtestStats = [
        'SOLUSDT'  => ['wr' => 41.5, 'ev' => 0.64, 'signals' => 41, 'pnl' => '+54%'],
        'XAGUSDT'  => ['wr' => 38.2, 'ev' => 0.53, 'signals' => 34, 'pnl' => '+36%'],
        'LINKUSDT' => ['wr' => 34.8, 'ev' => 0.39, 'signals' => 46, 'pnl' => '+36%'],
        'XAUUSDT'  => ['wr' => 37.0, 'ev' => 0.48, 'signals' => 27, 'pnl' => '+26%'],
        'BTCUSDT'  => ['wr' => 32.4, 'ev' => 0.30, 'signals' => 35, 'pnl' => '+20%'],
        'ETHUSDT'  => ['wr' => 32.1, 'ev' => 0.28, 'signals' => 29, 'pnl' => '+16%'],
    ];

    public function sendScanAlert(string $symbol, string $timeframe, array $signal, float $currentPrice, string $method = 'smc', int $riskPct = 2): void
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
        $isElliot = $method === 'elliot';
        $header   = $isElliot
            ? '🌊 <b>SÓNG ELLIOTT — AUTO SCAN</b>'
            : ($isSniper ? '⚡ <b>SNIPER SETUP DETECTED</b> ⚡' : '🔍 <b>SETUP MỚI — AUTO SCAN</b>');

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
              . "📊 <b>Backtest {$statsTf} (Jan-May 2026, 1:3 RR)</b>\n"
              . "{$wrEmoji} WR: <b>{$stats['wr']}%</b>  |  EV: <b>{$evSign}{$stats['ev']}R</b>/lệnh\n"
              . "📈 P&L 4 tháng: <b>{$stats['pnl']}</b>  |  {$stats['signals']} signals\n"
            : '';

        $appUrl = rtrim(env('APP_URL', 'http://localhost'), '/');
        $link   = "{$appUrl}/?symbol={$symbol}&timeframe={$timeframe}";

        $this->send(
            $header . "\n"
            . "📊 Method: <b>" . ($isElliot ? '🌊 Elliott Wave' : '📐 SMC Smart Money') . "</b>\n"
            . "━━━━━━━━━━━━━━━\n"
            . "💎 <b>{$symbol}</b> · {$timeframe} · {$dir}\n"
            . "🏷 Pattern: <code>{$pattern}</code>\n"
            . "━━━━━━━━━━━━━━━\n"
            . "📌 Entry : <code>{$entry}</code>\n"
            . "🎯 TP    : <code>{$tp}</code> (+{$tpPct}%) ← 1:3 R:R\n"
            . "🛑 SL    : <code>{$sl}</code> (-{$slPct}%)\n"
            . "📐 R:R   : 1:{$rr} | ⭐ Confluence: {$conf}%\n"
            . "💵 Risk {$riskPct}%: WIN <b>+" . round($riskPct * $rr, 1) . "%</b> vốn | LOSS <b>-{$riskPct}%</b> vốn\n"
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

    // --- Internal ---

    private function send(string $text): void
    {
        if (!$this->isConfigured()) return;

        try {
            (new Client())->post("{$this->baseUrl}/sendMessage", [
                'json' => [
                    'chat_id'    => $this->chatId,
                    'text'       => $text,
                    'parse_mode' => 'HTML',
                ],
                'timeout' => 10,
            ]);
        } catch (\Exception $e) {
            \Log::error('Telegram send failed: ' . $e->getMessage());
        }
    }
}
