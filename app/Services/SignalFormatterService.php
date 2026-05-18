<?php

namespace App\Services;

/**
 * v4 — Tính toán thông số lệnh + format Telegram message.
 *
 * Không bắn lệnh tự động. Chỉ tính số và trả về chuỗi
 * để người dùng copy-paste vào Exness trong 3 giây.
 */
class SignalFormatterService
{
    // XAUUSD: 1 pip = $0.10 | pip value per lot = $10
    private const INSTRUMENTS = [
        'XAUUSD'  => ['pip_size' => 0.10, 'pip_value' => 10.0, 'decimals' => 2],
        'XAUUSDT' => ['pip_size' => 0.10, 'pip_value' => 10.0, 'decimals' => 2],
        'XAGUSD'  => ['pip_size' => 0.001,'pip_value' => 1.0,  'decimals' => 3],
        'XAGUSDT' => ['pip_size' => 0.001,'pip_value' => 1.0,  'decimals' => 3],
    ];

    // ──────────────────────────────────────────────────────────────
    // LOT SIZING
    // ──────────────────────────────────────────────────────────────

    /**
     * Tính probe lot (0.5% vốn) và main lot (probe × multi).
     *
     * Công thức:
     *   Probe lot = (Vốn × 0.005) / (SL pips × pip_value_per_lot)
     *   Main lot  = Probe lot × multi
     *
     * @param float $capital     Vốn tài khoản USD
     * @param float $slPips      Số pip SL
     * @param int   $multi       Hệ số nhân "Lâng Lot" (mặc định 7)
     * @param string $symbol     Để lấy pip_value
     * @return array{probe: float, main: float, sl_usd_probe: float, sl_usd_main: float}
     */
    public function calculateExnessLots(
        float  $capital,
        float  $slPips,
        int    $multi  = 7,
        string $symbol = 'XAUUSD'
    ): array {
        $inst     = $this->instrument($symbol);
        $pipValue = $inst['pip_value'];

        $probeLot = $capital > 0 && $slPips > 0
            ? round(($capital * 0.005) / ($slPips * $pipValue), 2)
            : 0.01;

        $probeLot = max(0.01, $probeLot);
        $mainLot  = round($probeLot * $multi, 2);

        return [
            'probe'        => $probeLot,
            'main'         => $mainLot,
            'sl_usd_probe' => round($probeLot * $slPips * $pipValue, 2),
            'sl_usd_main'  => round($mainLot  * $slPips * $pipValue, 2),
        ];
    }

    // ──────────────────────────────────────────────────────────────
    // SIGNAL BUILDER
    // ──────────────────────────────────────────────────────────────

    /**
     * Xây dựng tham số cho cả 2 lệnh STOP từ channel data.
     *
     * BUY  STOP: entry = upper + 3 pips buffer | TP = entry + 15 pips | SL = ATR × 1.5
     * SELL STOP: entry = lower - 3 pips buffer | TP = entry - 15 pips | SL = ATR × 1.5
     *
     * @return array{long: array, short: array}
     */
    public function buildSignals(
        string $symbol,
        array  $channel,
        float  $atr,
        float  $capital,
        int    $multi  = 7,
        float  $tpPips = 15.0,
        float  $buferPips = 3.0
    ): array {
        $inst    = $this->instrument($symbol);
        $pip     = $inst['pip_size'];
        $dec     = $inst['decimals'];

        $slPips  = $atr > 0 ? round($atr / $pip * 1.5) : 30; // ATR × 1.5
        $slPips  = max(10, $slPips); // sàn 10 pips

        $tpDist  = $tpPips  * $pip;
        $bufDist = $buferPips * $pip;
        $slDist  = $slPips  * $pip;

        $lots = $this->calculateExnessLots($capital, $slPips, $multi, $symbol);

        $longEntry  = round($channel['upper'] + $bufDist, $dec);
        $longTp     = round($longEntry + $tpDist, $dec);
        $longSl     = round($longEntry - $slDist, $dec);
        $longRr     = $slPips > 0 ? round($tpPips / $slPips, 2) : 0;

        $shortEntry = round($channel['lower'] - $bufDist, $dec);
        $shortTp    = round($shortEntry - $tpDist, $dec);
        $shortSl    = round($shortEntry + $slDist, $dec);
        $shortRr    = $slPips > 0 ? round($tpPips / $slPips, 2) : 0;

        return [
            'long' => [
                'side'     => 'BUY_STOP',
                'entry'    => $longEntry,
                'tp'       => $longTp,
                'sl'       => $longSl,
                'tp_pips'  => $tpPips,
                'sl_pips'  => $slPips,
                'rr'       => $longRr,
                'lots'     => $lots,
            ],
            'short' => [
                'side'     => 'SELL_STOP',
                'entry'    => $shortEntry,
                'tp'       => $shortTp,
                'sl'       => $shortSl,
                'tp_pips'  => $tpPips,
                'sl_pips'  => $slPips,
                'rr'       => $shortRr,
                'lots'     => $lots,
            ],
            'sl_pips' => $slPips,
            'tp_pips' => $tpPips,
            'lots'    => $lots,
        ];
    }

    // ──────────────────────────────────────────────────────────────
    // TELEGRAM MESSAGE
    // ──────────────────────────────────────────────────────────────

    /**
     * Format tin nhắn Telegram — copy-paste vào Exness trong 3 giây.
     *
     * @param string $breakoutDirection  'LONG'|'SHORT'|'BOTH'|'WAIT' (từ AI)
     */
    public function formatTelegramMessage(
        string $symbol,
        string $timeframe,
        array  $channel,
        array  $signals,
        array  $aiResult,
        float  $currentPrice,
        string $breakoutDirection = 'BOTH'
    ): string {
        $upper       = $channel['upper'];
        $lower       = $channel['lower'];
        $compression = round($channel['compression'] * 100);
        $lhCount     = $channel['lh_count'];
        $hlCount     = $channel['hl_count'];

        $score      = $aiResult['score'] ?? 0;
        $analysis   = $aiResult['analysis'] ?? '';
        $riskNote   = $aiResult['risk_note'] ?? '';
        $confidence = $aiResult['confidence'] ?? 'LOW';
        $cached     = ($aiResult['cached'] ?? false) ? ' ♻' : '';

        $scoreBar = str_repeat('█', intdiv($score, 10)) . str_repeat('░', 10 - intdiv($score, 10));
        $confEmoji = match ($confidence) {
            'HIGH'   => '🟢',
            'MEDIUM' => '🟡',
            default  => '🔴',
        };

        $instLabel = str_contains(strtoupper($symbol), 'XAU') ? '🥇 VÀNG' : '🥈 BẠC';
        $time      = now()->format('H:i d/m');

        // ── Block lệnh theo AI direction ──
        $showLong  = in_array($breakoutDirection, ['LONG',  'BOTH']);
        $showShort = in_array($breakoutDirection, ['SHORT', 'BOTH']);

        $long  = $signals['long'];
        $short = $signals['short'];
        $lots  = $signals['lots'];
        $slPips = $signals['sl_pips'];
        $tpPips = $signals['tp_pips'];

        $capitalLine = $lots['probe'] > 0
            ? "\n💰 Probe <b>{$lots['probe']} lot</b> | Main <b>{$lots['main']} lot</b> (×" . count(range(1, intdiv($lots['main'] * 100, $lots['probe'] * 100 ?: 1))) . ")"
            : '';

        // Tính loss nếu probe bị SL
        $probeLossLine = $lots['sl_usd_probe'] > 0
            ? " | ❌ SL probe = -\${$lots['sl_usd_probe']}"
            : '';

        $longBlock  = '';
        $shortBlock = '';

        if ($showLong) {
            $longBlock = "⬆ <b>BUY STOP</b>\n"
                . "   📌 Entry : <code>{$long['entry']}</code>\n"
                . "   🎯 TP    : <code>{$long['tp']}</code>  (+{$tpPips} pips)\n"
                . "   🛡 SL    : <code>{$long['sl']}</code>  (-{$slPips} pips | ATR×1.5)\n"
                . "   📊 R:R   : 1:{$long['rr']}\n";
        }

        if ($showShort) {
            $shortBlock = "⬇ <b>SELL STOP</b>\n"
                . "   📌 Entry : <code>{$short['entry']}</code>\n"
                . "   🎯 TP    : <code>{$short['tp']}</code>  (-{$tpPips} pips)\n"
                . "   🛡 SL    : <code>{$short['sl']}</code>  (+{$slPips} pips | ATR×1.5)\n"
                . "   📊 R:R   : 1:{$short['rr']}\n";
        }

        $ordersSection = implode("\n", array_filter([$longBlock, $shortBlock]));

        $dirLabel = match ($breakoutDirection) {
            'LONG'  => '⬆ CHỈ BUY',
            'SHORT' => '⬇ CHỈ SELL',
            'WAIT'  => '⏳ CHỜ THÊM',
            default => '⬆⬇ HAI CHIỀU',
        };

        $msg = "{$instLabel} <b>BREAKOUT SETUP</b> — {$symbol} {$timeframe}  <i>{$time}</i>\n"
            . "━━━━━━━━━━━━━━━━━━━━\n"
            . "🗜 Kênh nén <b>{$compression}%</b>  ({$lhCount} LH · {$hlCount} HL)\n"
            . "   🏔 Đỉnh cứng : <code>{$upper}</code>\n"
            . "   ⛰ Đáy cứng  : <code>{$lower}</code>\n"
            . "   💰 Giá hiện tại : <code>{$currentPrice}</code>\n"
            . "━━━━━━━━━━━━━━━━━━━━\n"
            . $ordersSection
            . "━━━━━━━━━━━━━━━━━━━━\n"
            . "🤖 AI <b>{$score}/100</b>  {$scoreBar}  {$confEmoji}{$cached}  [{$dirLabel}]\n"
            . "<i>{$analysis}</i>\n"
            . ($riskNote ? "⚠️ <i>{$riskNote}</i>\n" : '')
            . "━━━━━━━━━━━━━━━━━━━━\n"
            . "📋 <b>Hướng dẫn Exness:</b>\n"
            . "   1. Pending Order → Stop Order\n"
            . "   2. Nhập entry/TP/SL ở trên\n"
            . "   3. Khi +5 pips → kéo SL về hoà vốn ngay{$capitalLine}{$probeLossLine}";

        return $msg;
    }

    /**
     * Tin nhắn chúc mừng khi đạt mục tiêu ngày.
     */
    public function formatDailyTargetMessage(
        string $symbol,
        float  $pipsWon,
        float  $targetPips
    ): string {
        return "🏆 <b>ĐẠT MỤC TIÊU NGÀY — {$symbol}</b>\n"
            . "━━━━━━━━━━━━━━━━━━━━\n"
            . "Tổng pips hôm nay: <b>{$pipsWon}</b> / mục tiêu <b>{$targetPips}</b>\n\n"
            . "✅ Felix đã khóa scan — <i>Nghỉ ngơi, đừng tham!</i>\n"
            . "🔄 Quét lại lúc đầu phiên ngày mai.";
    }

    // ──────────────────────────────────────────────────────────────
    // HELPERS
    // ──────────────────────────────────────────────────────────────

    private function instrument(string $symbol): array
    {
        return self::INSTRUMENTS[strtoupper($symbol)]
            ?? self::INSTRUMENTS['XAUUSD'];
    }
}
