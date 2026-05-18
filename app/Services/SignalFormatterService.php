<?php

namespace App\Services;

/**
 * v5 — Tính toán thông số lệnh + format Telegram message.
 *
 * Backtest 1–18/5/2026 kết quả tối ưu:
 *   TP = 15 pips cứng | SL = 20 pips cứng | R:R = 1:0.75
 *   WR 67.5% | EV +3.63 pip/trade | avg loss -20 pip (kiểm soát được)
 *
 * Không bắn lệnh tự động. Chỉ tính số và trả về chuỗi
 * để người dùng copy-paste vào Exness trong 3 giây.
 */
class SignalFormatterService
{
    // XAUUSD Exness: 0.01 lot × 10 pips = $1.00 → pip_value per lot = $0.10
    private const TP_PIPS  = 15.0;
    private const SL_PIPS  = 20.0;
    private const BUF_PIPS =  3.0;
    private const RR       = 0.75; // TP/SL = 15/20

    // ──────────────────────────────────────────────────────────────
    // LOT SIZING
    // ──────────────────────────────────────────────────────────────

    /**
     * Tính probe lot (0.5% vốn) và main lot (probe × multi).
     *
     * Công thức Exness XAUUSD:
     *   Probe lot = (Vốn × 0.005) / (SL Pips × 0.1)
     *   Main lot  = Probe lot × multi
     *
     * @param float $capital  Vốn tài khoản USD
     * @param int   $multi    Hệ số nhân "Lâng Lot" (mặc định 7)
     * @return array{probe: float, main: float, sl_usd_probe: float, sl_usd_main: float}
     */
    public function calculateExnessLots(float $capital, int $multi = 7): array
    {
        $slPips = self::SL_PIPS;

        // Exness XAUUSD: 1 lot × 1 pip ($0.10 move) = $10 → pip_value = 10.0/lot
        // Capital $100 → probe = (100×0.005)/(20×10) = 0.0025 → clamped to 0.01
        $probeLot = $capital > 0
            ? round(($capital * 0.005) / ($slPips * 10.0), 2)
            : 0.01;

        $probeLot = max(0.01, $probeLot);
        $mainLot  = round($probeLot * $multi, 2);

        return [
            'probe'        => $probeLot,
            'main'         => $mainLot,
            'sl_usd_probe' => round($probeLot * $slPips * 10.0, 2),
            'sl_usd_main'  => round($mainLot  * $slPips * 10.0, 2),
        ];
    }

    // ──────────────────────────────────────────────────────────────
    // SIGNAL BUILDER
    // ──────────────────────────────────────────────────────────────

    /**
     * Xây dựng tham số cho cả 2 lệnh STOP từ channel data.
     *
     * BUY  STOP: entry = upper + 3 pips | TP = entry + 15 pips | SL = entry - 20 pips
     * SELL STOP: entry = lower - 3 pips | TP = entry - 15 pips | SL = entry + 20 pips
     *
     * @return array{long: array, short: array, sl_pips: float, tp_pips: float, lots: array}
     */
    public function buildSignals(
        string $symbol,
        array  $channel,
        float  $capital,
        int    $multi = 7
    ): array {
        // XAUUSD: pip_size = 0.10, decimals = 2
        $pip = 0.10;
        $dec = 2;

        $tpPips  = self::TP_PIPS;
        $slPips  = self::SL_PIPS;
        $bufPips = self::BUF_PIPS;
        $rr      = self::RR;

        $tpDist  = $tpPips  * $pip;
        $slDist  = $slPips  * $pip;
        $bufDist = $bufPips * $pip;

        $lots = $this->calculateExnessLots($capital, $multi);

        // BUY STOP
        $longEntry = round($channel['upper'] + $bufDist, $dec);
        $longTp    = round($longEntry + $tpDist, $dec);
        $longSl    = round($longEntry - $slDist, $dec);

        // SELL STOP
        $shortEntry = round($channel['lower'] - $bufDist, $dec);
        $shortTp    = round($shortEntry - $tpDist, $dec);
        $shortSl    = round($shortEntry + $slDist, $dec);

        return [
            'long' => [
                'side'  => 'BUY_STOP',
                'entry' => $longEntry,
                'tp'    => $longTp,
                'sl'    => $longSl,
                'rr'    => $rr,
                'lots'  => $lots,
            ],
            'short' => [
                'side'  => 'SELL_STOP',
                'entry' => $shortEntry,
                'tp'    => $shortTp,
                'sl'    => $shortSl,
                'rr'    => $rr,
                'lots'  => $lots,
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

        $score      = $aiResult['score']      ?? 0;
        $analysis   = $aiResult['analysis']   ?? '';
        $riskNote   = $aiResult['risk_note']  ?? '';
        $confidence = $aiResult['confidence'] ?? 'LOW';
        $cached     = ($aiResult['cached'] ?? false) ? ' ♻' : '';

        $scoreBar  = str_repeat('█', intdiv($score, 10)) . str_repeat('░', 10 - intdiv($score, 10));
        $confEmoji = match ($confidence) {
            'HIGH'   => '🟢',
            'MEDIUM' => '🟡',
            default  => '🔴',
        };

        $time  = now()->format('H:i d/m');
        $long  = $signals['long'];
        $short = $signals['short'];
        $lots  = $signals['lots'];

        $showLong  = in_array($breakoutDirection, ['LONG',  'BOTH']);
        $showShort = in_array($breakoutDirection, ['SHORT', 'BOTH']);

        $longBlock  = '';
        $shortBlock = '';

        if ($showLong) {
            $longBlock = "⬆ <b>BUY STOP</b>\n"
                . "   📌 Entry : <code>{$long['entry']}</code>\n"
                . "   🎯 TP    : <code>{$long['tp']}</code>  (+15 pips / 1.5 giá Vàng)\n"
                . "   🛡 SL    : <code>{$long['sl']}</code>  (-20 pips cứng / 2.0 giá Vàng)\n"
                . "   📊 R:R   : 1:0.75\n";
        }

        if ($showShort) {
            $shortBlock = "⬇ <b>SELL STOP</b>\n"
                . "   📌 Entry : <code>{$short['entry']}</code>\n"
                . "   🎯 TP    : <code>{$short['tp']}</code>  (-15 pips / 1.5 giá Vàng)\n"
                . "   🛡 SL    : <code>{$short['sl']}</code>  (+20 pips cứng / 2.0 giá Vàng)\n"
                . "   📊 R:R   : 1:0.75\n";
        }

        $ordersSection = implode("\n", array_filter([$longBlock, $shortBlock]));

        $dirLabel = match ($breakoutDirection) {
            'LONG'  => '⬆ CHỈ BUY',
            'SHORT' => '⬇ CHỈ SELL',
            'WAIT'  => '⏳ CHỜ THÊM',
            default => '⬆⬇ HAI CHIỀU',
        };

        $capitalLine = $lots['probe'] > 0
            ? "\n💰 Probe <b>{$lots['probe']} lot</b>  |  Main <b>{$lots['main']} lot</b>"
            : '';

        $probeLossLine = $lots['sl_usd_probe'] > 0
            ? "  |  ❌ SL probe = -\${$lots['sl_usd_probe']}"
            : '';

        $msg = "🥇 <b>VÀNG BREAKOUT SETUP</b> — {$symbol} {$timeframe}  <i>{$time}</i>\n"
            . "━━━━━━━━━━━━━━━━━━━━\n"
            . "🗜 Kênh nén <b>{$compression}%</b>  ({$lhCount} LH · {$hlCount} HL)\n"
            . "   🏔 Đỉnh cứng     : <code>{$upper}</code>\n"
            . "   ⛰ Đáy cứng     : <code>{$lower}</code>\n"
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
            . "   2. Nhập Entry / TP / SL ở trên\n"
            . "   3. Khi +5 pips (0.5 giá) → kéo SL về hoà vốn ngay để bảo vệ Main Lot"
            . $capitalLine
            . $probeLossLine;

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
}
