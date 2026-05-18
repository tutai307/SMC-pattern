<?php

namespace App\Services;

/**
 * v5 — Tính toán thông số lệnh + format Telegram message.
 *
 * Chiến thuật:
 *   Triangle breakout:    BUY STOP  (upper + buf) | SELL STOP  (lower - buf)
 *   Descending (bounce):  SELL LIMIT (upper - buf)
 *   Ascending  (bounce):  BUY LIMIT  (lower + buf)
 *
 * R:R động:
 *   SL = 1.5 × ATR(14)          — dựa theo market noise thực
 *   TP = channel_width × 0.8    — nhắm 80% biên độ kênh
 *   Lọc: R:R < 1:1 → null (skip)
 *
 * Sizing: Fixed Fractional 2% vốn
 *   Lot = (Capital × 2%) / (SL_pips × $100), clamp ≥ 0.01
 */
class SignalFormatterService
{
    private const BUF_PIPS = 3.0;  // buffer breakout / bounce (pips = $)

    // ──────────────────────────────────────────────────────────────
    // LOT SIZING — Fixed Fractional
    // ──────────────────────────────────────────────────────────────

    /**
     * Tính lot theo Fixed Fractional: rủi ro %Risk mỗi lệnh.
     *
     * Exness XAUUSD: 1 lot × 1 pip ($1 price) = $100 P&L
     * Lot = (Capital × %Risk) / (SL_pips × $100), clamp ≥ 0.01
     */
    public function calculateExnessLot(float $capital, float $slPips, float $riskPct = 0.02): float
    {
        if ($capital <= 0 || $slPips <= 0) return 0.01;
        return max(0.01, round(($capital * $riskPct) / ($slPips * 100.0), 2));
    }

    // ──────────────────────────────────────────────────────────────
    // SIGNAL BUILDER
    // ──────────────────────────────────────────────────────────────

    /**
     * Xây dựng tham số lệnh từ channel + ATR.
     *
     * Triangle:          BUY STOP  + SELL STOP  (breakout cả 2 chiều)
     * Descending (SHORT): SELL LIMIT (bounce bán tại upper trendline)
     * Ascending  (LONG):  BUY LIMIT  (bounce mua tại lower trendline)
     *
     * Trả về null nếu R:R < 1:1 (TP nhỏ hơn SL) — setup không đáng vào.
     *
     * @return array{orders: array, sl_pips: float, tp_pips: float, rr: float, lot: float}|null
     */
    public function buildSignals(
        string $symbol,
        array  $channel,
        float  $capital,
        float  $atr
    ): ?array {
        $pip     = 1.00;   // XAUUSD: 1 pip = $1.00 price movement
        $dec     = 2;
        $bufDist = self::BUF_PIPS * $pip;

        // ── Dynamic SL / TP ──
        $slDist = round(1.5 * $atr, 2);                                   // 1.5 × ATR(14)
        $tpDist = round(($channel['upper'] - $channel['lower']) * 0.8, 2); // 80% channel width

        // Minimum R:R 1:1
        if ($slDist <= 0 || $tpDist < $slDist) return null;

        $slPips = round($slDist / $pip, 2);
        $tpPips = round($tpDist / $pip, 2);
        $rr     = round($tpDist / $slDist, 2);
        $lot    = $this->calculateExnessLot($capital, $slPips);

        $channelDir = $channel['direction'] ?? null;

        if ($channelDir === 'SHORT') {
            $entry  = round($channel['upper'] - $bufDist, $dec);
            $orders = [['side' => 'SELL_LIMIT', 'entry' => $entry,
                         'tp'  => round($entry - $tpDist, $dec),
                         'sl'  => round($entry + $slDist, $dec)]];

        } elseif ($channelDir === 'LONG') {
            $entry  = round($channel['lower'] + $bufDist, $dec);
            $orders = [['side' => 'BUY_LIMIT',  'entry' => $entry,
                         'tp'  => round($entry + $tpDist, $dec),
                         'sl'  => round($entry - $slDist, $dec)]];

        } else {
            $lE = round($channel['upper'] + $bufDist, $dec);
            $sE = round($channel['lower'] - $bufDist, $dec);
            $orders = [
                ['side' => 'BUY_STOP',  'entry' => $lE, 'tp' => round($lE + $tpDist, $dec), 'sl' => round($lE - $slDist, $dec)],
                ['side' => 'SELL_STOP', 'entry' => $sE, 'tp' => round($sE - $tpDist, $dec), 'sl' => round($sE + $slDist, $dec)],
            ];
        }

        return [
            'orders'  => $orders,
            'sl_pips' => $slPips,
            'tp_pips' => $tpPips,
            'rr'      => $rr,
            'lot'     => $lot,
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
        $channelType = $channel['type'] ?? 'triangle';

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

        $time   = now('Asia/Ho_Chi_Minh')->format('H:i d/m');
        $lot    = $signals['lot'];
        $slPips = $signals['sl_pips'];
        $tpPips = $signals['tp_pips'];
        $slUsd  = round($lot * $slPips * 100.0, 2);

        // Header + channel description by type
        if ($channelType === 'descending') {
            $header       = "📉 <b>VÀNG BOUNCE SELL</b>";
            $channelLabel = "📉 Kênh GIẢM Song Song";
        } elseif ($channelType === 'ascending') {
            $header       = "📈 <b>VÀNG BOUNCE BUY</b>";
            $channelLabel = "📈 Kênh TĂNG Song Song";
        } else {
            $compression  = round($channel['compression'] * 100);
            $lhCount      = $channel['lh_count'];
            $hlCount      = $channel['hl_count'];
            $header       = "🥇 <b>VÀNG BREAKOUT SETUP</b>";
            $channelLabel = "🗜 Kênh NÉN <b>{$compression}%</b>  ({$lhCount} LH · {$hlCount} HL)";
        }

        // For triangles, respect AI direction to show 1 or 2 orders
        $orders = $signals['orders'];
        if (($channel['direction'] ?? null) === null) {
            if ($breakoutDirection === 'LONG') {
                $orders = array_values(array_filter($orders, fn($o) => str_starts_with($o['side'], 'BUY')));
            } elseif ($breakoutDirection === 'SHORT') {
                $orders = array_values(array_filter($orders, fn($o) => str_starts_with($o['side'], 'SELL')));
            }
        }

        $ordersSection = '';
        foreach ($orders as $order) {
            $ordersSection .= $this->formatOrderBlock($order, $tpPips, $slPips) . "\n";
        }

        $dirLabel = match ($breakoutDirection) {
            'LONG'  => '⬆ CHỈ BUY',
            'SHORT' => '⬇ CHỈ SELL',
            'WAIT'  => '⏳ CHỜ THÊM',
            default => '⬆⬇ HAI CHIỀU',
        };

        return "{$header} — {$symbol} {$timeframe}  <i>{$time}</i>\n"
            . "━━━━━━━━━━━━━━━━━━━━\n"
            . "{$channelLabel}\n"
            . "   🏔 Upper : <code>{$upper}</code>   ⛰ Lower : <code>{$lower}</code>\n"
            . "   💰 Giá hiện tại : <code>{$currentPrice}</code>\n"
            . "━━━━━━━━━━━━━━━━━━━━\n"
            . $ordersSection
            . "━━━━━━━━━━━━━━━━━━━━\n"
            . "🤖 AI <b>{$score}/100</b>  {$scoreBar}  {$confEmoji}{$cached}  [{$dirLabel}]\n"
            . "<i>{$analysis}</i>\n"
            . ($riskNote ? "⚠️ <i>{$riskNote}</i>\n" : '')
            . "\n💼 Lot: <b>{$lot}</b>  |  ❌ SL rủi ro: -\${$slUsd}";
    }

    private function formatOrderBlock(array $order, float $tpPips, float $slPips): string
    {
        $isSell = str_starts_with($order['side'], 'SELL');
        $emoji  = $isSell ? '⬇' : '⬆';
        $label  = str_replace('_', ' ', $order['side']);
        $tpSign = $isSell ? '-' : '+';
        $slSign = $isSell ? '+' : '-';
        $rr     = round($tpPips / $slPips, 2);

        return "{$emoji} <b>{$label}</b>\n"
            . "   📌 Entry : <code>{$order['entry']}</code>\n"
            . "   🎯 TP    : <code>{$order['tp']}</code>  ({$tpSign}{$tpPips} pips)\n"
            . "   🛡 SL    : <code>{$order['sl']}</code>  ({$slSign}{$slPips} pips)\n"
            . "   📊 R:R   : 1:{$rr}";
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
