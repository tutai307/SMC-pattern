<?php

namespace App\Services;

/**
 * v5.1 — Tính toán thông số lệnh + format Telegram message.
 *
 * ĐƠN VỊ CHUẨN XAUUSD (đồng bộ biểu đồ MT5):
 *   1 Giá = $1.00 di chuyển (vd: 2400 → 2401)
 *   1 Pip  = 0.1 Giá = $0.10
 *   1 Lot Exness XAUUSD = 100 oz → mỗi 1 Giá × 1 Lot = $100 rủi ro
 *   Tất cả biến khoảng cách trong code đều tính bằng đơn vị "Giá" (không dùng Pip)
 *
 * Chiến thuật:
 *   Triangle breakout:    BUY STOP  (upper + 0.3 giá) | SELL STOP  (lower - 0.3 giá)
 *   Descending (bounce):  SELL LIMIT (upper - 0.3 giá) — chặn đầu tại biên trên kênh giảm
 *   Ascending  (bounce):  BUY LIMIT  (lower + 0.3 giá) — chặn đầu tại biên dưới kênh tăng
 *
 * R:R động:
 *   SL = 1.5 × ATR(14) theo giá     — bám theo market noise thực tế M15
 *   TP = channel_width × 0.8 theo giá — chiến thuật "cướp tàu", ăn 80% biên độ kênh
 *   Filter: R:R < 1:1 → trả về null (kênh quá hẹp so với volatility, không bõ rủi ro)
 *
 * Quản lý vốn Fixed Fractional 2%:
 *   Lot = (Capital × 2%) / (SL_giá × $100/lot)
 *   Clamp: Lot_min = 0.01
 */
class SignalFormatterService
{
    // Buffer: 0.3 giá ($0.30) — đủ để tránh fakeout, không quá xa so với trendline
    private const BUF_GIA = 0.3;

    // ──────────────────────────────────────────────────────────────
    // LOT SIZING — Fixed Fractional 2%
    // ──────────────────────────────────────────────────────────────

    /**
     * Tính Lot Size theo Fixed Fractional — rủi ro $riskPct% vốn mỗi lệnh.
     *
     * Exness XAUUSD chuẩn quốc tế:
     *   1 Lot × 1 Giá ($1 price move) × 100 oz/lot = $100 P&L
     *   → Lot = (Capital × %Risk) / (SL_giá × $100)
     *
     * @param float $slGia    SL tính bằng Giá (đơn vị USD/oz), vd: 4.5 giá
     * @param float $riskPct  Tỷ lệ rủi ro (mặc định 2%)
     */
    public function calculateExnessLot(float $capital, float $slGia, float $riskPct = 0.02): float
    {
        if ($capital <= 0 || $slGia <= 0) return 0.01;
        // $100/lot/giá là pip_value chuẩn Exness XAUUSD (1 lot = 100 oz, 1 giá = $1)
        return max(0.01, round(($capital * $riskPct) / ($slGia * 100.0), 2));
    }

    // ──────────────────────────────────────────────────────────────
    // SIGNAL BUILDER
    // ──────────────────────────────────────────────────────────────

    /**
     * Xây dựng tham số lệnh từ channel data + ATR.
     *
     * Triangle:          BUY STOP  (upper + 0.3 giá) + SELL STOP  (lower - 0.3 giá)
     * Descending (SHORT): SELL LIMIT (upper - 0.3 giá) — Bounce bán tại đường trendline giảm
     * Ascending  (LONG):  BUY LIMIT  (lower + 0.3 giá) — Bounce mua tại đường trendline tăng
     *
     * SL = 1.5 × ATR(14) [giá]  |  TP = channel_width × 0.8 [giá]
     * Trả về null nếu TP < SL (R:R < 1:1) → kênh không đủ biên độ.
     *
     * @return array{orders: array, sl_gia: float, tp_gia: float, rr: float, lot: float}|null
     */
    public function buildSignals(
        string $symbol,
        array  $channel,
        float  $capital,
        float  $atr
    ): ?array {
        $dec    = 2;
        $bufGia = self::BUF_GIA;  // 0.3 giá buffer

        // ── Dynamic SL / TP (đơn vị: Giá USD/oz) ────────────────
        // SL: 1.5 × ATR(14) — đủ xa market noise của khung M15
        $slGia = round(1.5 * $atr, 2);
        // TP: 80% chiều rộng kênh — không ăn trọn sóng, tránh reversal cuối kênh
        $tpGia = round(($channel['upper'] - $channel['lower']) * 0.8, 2);

        // Filter R:R: kênh quá hẹp so với ATR → setup không bõ rủi ro
        if ($slGia <= 0 || $tpGia < $slGia) return null;

        $rr  = round($tpGia / $slGia, 2);
        $lot = $this->calculateExnessLot($capital, $slGia);

        $channelDir = $channel['direction'] ?? null;

        if ($channelDir === 'SHORT') {
            // Bounce bán: SELL LIMIT ngay sát biên trên trendline giảm
            // Giá phải chạm upper_now thì lệnh mới được fill → đặt trừ 0.3 giá để vào sớm
            $entry  = round($channel['upper'] - $bufGia, $dec);
            $orders = [['side' => 'SELL_LIMIT', 'entry' => $entry,
                         'tp'  => round($entry - $tpGia, $dec),
                         'sl'  => round($entry + $slGia, $dec)]];

        } elseif ($channelDir === 'LONG') {
            // Bounce mua: BUY LIMIT ngay sát biên dưới trendline tăng
            $entry  = round($channel['lower'] + $bufGia, $dec);
            $orders = [['side' => 'BUY_LIMIT',  'entry' => $entry,
                         'tp'  => round($entry + $tpGia, $dec),
                         'sl'  => round($entry - $slGia, $dec)]];

        } else {
            // Triangle breakout: 2 lệnh STOP chờ giá phá vỡ biên
            $lE = round($channel['upper'] + $bufGia, $dec);
            $sE = round($channel['lower'] - $bufGia, $dec);
            $orders = [
                ['side' => 'BUY_STOP',  'entry' => $lE, 'tp' => round($lE + $tpGia, $dec), 'sl' => round($lE - $slGia, $dec)],
                ['side' => 'SELL_STOP', 'entry' => $sE, 'tp' => round($sE - $tpGia, $dec), 'sl' => round($sE + $slGia, $dec)],
            ];
        }

        return [
            'orders' => $orders,
            'sl_gia' => $slGia,
            'tp_gia' => $tpGia,
            'rr'     => $rr,
            'lot'    => $lot,
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

        $time  = now('Asia/Ho_Chi_Minh')->format('H:i d/m');
        $lot   = $signals['lot'];
        $slGia = $signals['sl_gia'];
        $tpGia = $signals['tp_gia'];
        $slUsd = round($lot * $slGia * 100.0, 2);  // P&L = lot × giá × $100/lot/giá

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

        // Với Triangle: lọc theo AI direction để chỉ show lệnh phù hợp
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
            $ordersSection .= $this->formatOrderBlock($order, $tpGia, $slGia) . "\n";
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

    /**
     * Format 1 order block trong tin nhắn Telegram.
     *
     * @param float $tpGia  Khoảng cách TP tính bằng Giá (USD/oz)
     * @param float $slGia  Khoảng cách SL tính bằng Giá (USD/oz)
     */
    private function formatOrderBlock(array $order, float $tpGia, float $slGia): string
    {
        $isSell = str_starts_with($order['side'], 'SELL');
        $emoji  = $isSell ? '⬇' : '⬆';
        $label  = str_replace('_', ' ', $order['side']);  // "SELL LIMIT", "BUY STOP", ...
        $tpSign = $isSell ? '-' : '+';
        $slSign = $isSell ? '+' : '-';
        $rr     = round($tpGia / $slGia, 2);

        return "{$emoji} <b>{$label}</b>\n"
            . "   📌 Entry : <code>{$order['entry']}</code>\n"
            . "   🎯 TP    : <code>{$order['tp']}</code>  ({$tpSign}{$tpGia} giá)\n"
            . "   🛡 SL    : <code>{$order['sl']}</code>  ({$slSign}{$slGia} giá)\n"
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
            . "Tổng giá hôm nay: <b>{$pipsWon}</b> / mục tiêu <b>{$targetPips}</b>\n\n"
            . "✅ Felix đã khóa scan — <i>Nghỉ ngơi, đừng tham!</i>\n"
            . "🔄 Quét lại lúc đầu phiên ngày mai.";
    }
}
