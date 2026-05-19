<?php

namespace App\Services;

/**
 * v5.4 — Signal Generator + Formatter cho XAUUSD.
 *
 * ĐƠN VỊ CHUẨN XAUUSD (đồng bộ biểu đồ MT5):
 *   1 Giá = $1.00 di chuyển (vd: 4400.00 → 4401.00)
 *   1 Lot Exness XAUUSD = 100 oz → 1 Giá × 1 Lot = $100 P&L
 *
 * Hai bài đánh:
 *   BÀI 1 — Đánh Phá Vỡ (Triangle):  BUY/SELL STOP, TP/SL động theo ATR + channel width
 *   BÀI 2 — Đánh Quét Biên (Bounce): DISABLED v5.4 (backtest WR 13-25%, không đủ lợi nhuận)
 *
 * 3 lớp bảo vệ trong generateSafeSignal():
 *   L1. Survival filter:  SL<=0 hoặc TP<SL → null
 *   L2. Lot hard stop:    Raw lot < 0.01 → RuntimeException
 *   L3. Order validate:   Entry vs CurrentPrice sai loại → auto-correct hoặc null
 */
class SignalFormatterService
{
    // Buffer: 0.3 giá ($0.30) — tránh fakeout, không quá xa trendline
    private const BUF_GIA = 0.3;

    // TP/SL động — tính theo ATR và channel width
    private const SL_ATR_MULT  = 1.5;  // SL = 1.5 × ATR(14)
    private const TP_WIDTH_PCT = 0.8;  // TP = channel_width × 80%

    // Risk management
    private const RISK_PCT = 0.02;   // 2% vốn mỗi lệnh
    private const MIN_LOT  = 0.01;   // Lot tối thiểu sàn Exness

    // ──────────────────────────────────────────────────────────────
    // ENTRY POINT CHÍNH — gọi từ ScanSignalsCommand
    // ──────────────────────────────────────────────────────────────

    /**
     * Generator tín hiệu an toàn — 3 lớp kiểm tra cứng.
     *
     * @param array $marketData {
     *   symbol:        string,
     *   current_price: float,   Giá bid hiện tại (USD/oz)
     *   channel:       array,   Kết quả detectUnpredictableChannel()
     *   atr:           float,   Kết quả calculateATR(14)
     * }
     * @param float $accountCapital  Vốn tài khoản USD
     * @return array|null            null = không có setup hợp lệ
     * @throws \RuntimeException     Khi vốn không đủ để gánh SL với 2% risk
     */
    public function generateSafeSignal(array $marketData, float $accountCapital): ?array
    {
        $currentPrice = (float) ($marketData['current_price'] ?? 0);
        $channel      = $marketData['channel'];
        $atr          = (float) ($marketData['atr'] ?? 0);
        $symbol       = (string) ($marketData['symbol'] ?? 'XAUUSD');

        // ── L1: Build signals (tích hợp R:R filter cho bounce) ───
        $signals = $this->buildSignals($symbol, $channel, $accountCapital, $atr);
        if ($signals === null) {
            return null; // Bounce: TP < SL
        }

        // ── L2: Lot Hard Stop ─────────────────────────────────────
        // Tính RAW lot trước khi clamp để bắt vốn quá nhỏ
        $slGia  = $signals['sl_gia'];
        $rawLot = ($accountCapital > 0 && $slGia > 0)
            ? ($accountCapital * self::RISK_PCT) / ($slGia * 100.0)
            : 0.0;

        if ($rawLot < self::MIN_LOT) {
            $minCapital = (int) ceil(self::MIN_LOT * $slGia * 100.0 / self::RISK_PCT);
            throw new \RuntimeException(
                "Tài khoản \${$accountCapital} không đủ vốn. "
                . "SL = {$slGia} giá → Lot = " . round($rawLot, 5) . " (< 0.01 min). "
                . "Cần ít nhất \${$minCapital}."
            );
        }

        // ── L3: Validate + auto-correct loại lệnh ────────────────
        $validOrders = [];
        foreach ($signals['orders'] as $order) {
            $validated = $this->validateAndCorrectOrder($order, $currentPrice);
            if ($validated !== null) {
                $validOrders[] = $validated;
            } else {
                \Log::warning("Signal rejected: {$order['side']}@{$order['entry']} vs price={$currentPrice}");
            }
        }

        if (empty($validOrders)) return null;

        return array_merge($signals, ['orders' => $validOrders]);
    }

    // ──────────────────────────────────────────────────────────────
    // LOT SIZING
    // ──────────────────────────────────────────────────────────────

    /**
     * Fixed Fractional lot sizing.
     *
     * Exness XAUUSD: 1 Lot × 1 Giá ($1) × 100 oz = $100 P&L
     * Lot = (Capital × 2%) / (SL_giá × $100), clamp ≥ 0.01
     */
    public function calculateExnessLot(float $capital, float $slGia): float
    {
        if ($capital <= 0 || $slGia <= 0) return self::MIN_LOT;
        return max(self::MIN_LOT, round(($capital * self::RISK_PCT) / ($slGia * 100.0), 2));
    }

    // ──────────────────────────────────────────────────────────────
    // SIGNAL BUILDER
    // ──────────────────────────────────────────────────────────────

    /**
     * Tính tham số lệnh theo bài đánh tương ứng với loại kênh.
     *
     * BÀI 1 (Triangle — direction=null):
     *   SL = 1.5 × ATR(14)   — SL động theo biến động thị trường
     *   TP = channel_width × 80%   — TP bằng 80% chiều rộng kênh tại điểm phá vỡ
     *   Bộ lọc sống còn: SL <= 0 hoặc TP < SL → return null
     *   Loại lệnh: BUY STOP (trên upper) + SELL STOP (dưới lower)
     *
     * BÀI 2 (Bounce — direction=SHORT/LONG): DISABLED v5.4
     *   ascending/descending bị lọc trước ở ScanSignalsCommand — không vào đây
     *
     * @return array{orders, sl_gia, tp_gia, rr, lot, atr}|null
     */
    public function buildSignals(
        string $symbol,
        array  $channel,
        float  $capital,
        float  $atr
    ): ?array {
        $dec        = 2;
        $bufGia     = self::BUF_GIA;
        $channelDir = $channel['direction'] ?? null;

        // Tính SL và TP động
        $slGia = round(self::SL_ATR_MULT * $atr, 2);
        $tpGia = round(($channel['upper'] - $channel['lower']) * self::TP_WIDTH_PCT, 2);

        // Bộ lọc sống còn — BẮT BUỘC
        if ($slGia <= 0 || $tpGia < $slGia) return null;

        $rr  = round($tpGia / $slGia, 2);
        $lot = $this->calculateExnessLot($capital, $slGia);

        if ($channelDir === null) {
            // ── BÀI 1: Đánh Phá Vỡ (Triangle) ─────────────────
            $lE = round($channel['upper'] + $bufGia, $dec);  // BUY STOP trên đỉnh kênh
            $sE = round($channel['lower'] - $bufGia, $dec);  // SELL STOP dưới đáy kênh
            $orders = [
                ['side' => 'BUY_STOP',  'entry' => $lE,
                 'tp'   => round($lE + $tpGia, $dec), 'sl' => round($lE - $slGia, $dec)],
                ['side' => 'SELL_STOP', 'entry' => $sE,
                 'tp'   => round($sE - $tpGia, $dec), 'sl' => round($sE + $slGia, $dec)],
            ];

        } else {
            // ── BÀI 2: Đánh Quét Biên (Bounce) ─────────────────
            if ($channelDir === 'SHORT') {
                $entry  = round($channel['upper'] - $bufGia, $dec);
                $orders = [['side' => 'SELL_LIMIT', 'entry' => $entry,
                             'tp'  => round($entry - $tpGia, $dec),
                             'sl'  => round($entry + $slGia, $dec)]];
            } else {
                $entry  = round($channel['lower'] + $bufGia, $dec);
                $orders = [['side' => 'BUY_LIMIT', 'entry' => $entry,
                             'tp'  => round($entry + $tpGia, $dec),
                             'sl'  => round($entry - $slGia, $dec)]];
            }
        }

        return [
            'orders' => $orders,
            'sl_gia' => $slGia,
            'tp_gia' => $tpGia,
            'rr'     => $rr,
            'lot'    => $lot,
            'atr'    => round($atr, 2),
        ];
    }

    // ──────────────────────────────────────────────────────────────
    // ORDER TYPE VALIDATOR
    // ──────────────────────────────────────────────────────────────

    /**
     * Validate loại lệnh vs giá hiện tại — quy tắc cứng MT5/Exness:
     *
     *   BUY STOP:   Entry PHẢI > CurrentPrice  (chờ breakout lên)
     *   SELL STOP:  Entry PHẢI < CurrentPrice  (chờ breakdown xuống)
     *   BUY LIMIT:  Entry PHẢI < CurrentPrice  (chờ pullback về)
     *   SELL LIMIT: Entry PHẢI > CurrentPrice  (chờ hồi phục lên)
     *
     * Nếu sai → auto-correct STOP ↔ LIMIT (giữ hướng BUY/SELL).
     * Vẫn sai sau correct (logic phá vỡ chiến thuật) → null.
     */
    private function validateAndCorrectOrder(array $order, float $currentPrice): ?array
    {
        // entry PHẢI CAO HƠN currentPrice với các loại lệnh này
        $mustBeAbove = [
            'BUY_STOP'   => true,
            'SELL_LIMIT' => true,
            'BUY_LIMIT'  => false,
            'SELL_STOP'  => false,
        ];

        // Đổi cơ chế chờ khi entry vs price bị ngược (giữ hướng BUY/SELL)
        $counterpart = [
            'BUY_STOP'   => 'BUY_LIMIT',
            'BUY_LIMIT'  => 'BUY_STOP',
            'SELL_STOP'  => 'SELL_LIMIT',
            'SELL_LIMIT' => 'SELL_STOP',
        ];

        $side  = $order['side'];
        $entry = $order['entry'];

        if (!isset($mustBeAbove[$side])) return null;

        $entryIsAbove = $entry > $currentPrice;

        // ✓ Logic đúng — giữ nguyên
        if ($mustBeAbove[$side] === $entryIsAbove) return $order;

        // ✗ Logic sai → auto-correct sang counterpart
        $correctedSide = $counterpart[$side] ?? null;
        if ($correctedSide === null) return null;

        \Log::info("OrderType corrected: {$side}@{$entry} → {$correctedSide} (price={$currentPrice})");

        return array_merge($order, ['side' => $correctedSide, 'auto_corrected' => true]);
    }

    // ──────────────────────────────────────────────────────────────
    // TELEGRAM MESSAGE
    // ──────────────────────────────────────────────────────────────

    /**
     * Format tin nhắn Telegram — copy-paste vào Exness trong 3 giây.
     *
     * Thay thế AI score bằng thông số hình học thực tế:
     * số swing points, compression, ATR, và HTF bias từ H4.
     *
     * @param string|null $htfBias  Xu hướng H4 macro: 'LONG'|'SHORT'|null
     */
    public function formatTelegramMessage(
        string  $symbol,
        string  $timeframe,
        array   $channel,
        array   $signals,
        float   $currentPrice,
        ?string $htfBias = null
    ): string {
        $upper       = $channel['upper'];
        $lower       = $channel['lower'];
        $channelType = $channel['type'] ?? 'triangle';
        $lhCount     = $channel['lh_count'] ?? 0;
        $hlCount     = $channel['hl_count'] ?? 0;
        $hhCount     = $channel['hh_count'] ?? 0;
        $llCount     = $channel['ll_count'] ?? 0;

        $lot   = $signals['lot'];
        $slGia = $signals['sl_gia'];
        $tpGia = $signals['tp_gia'];
        $atr   = $signals['atr'] ?? 0;
        $slUsd = round($lot * $slGia * 100.0, 2);

        $time = now('Asia/Ho_Chi_Minh')->format('H:i d/m');

        // Header theo loại kênh
        [$header, $channelLabel] = match ($channelType) {
            'descending' => [
                "📉 <b>VÀNG BOUNCE SELL</b>",
                "📉 Kênh GIẢM Song Song  ({$lhCount} LH · {$llCount} LL)",
            ],
            'ascending'  => [
                "📈 <b>VÀNG BOUNCE BUY</b>",
                "📈 Kênh TĂNG Song Song  ({$hhCount} HH · {$hlCount} HL)",
            ],
            default      => [
                "🥇 <b>VÀNG BREAKOUT SETUP</b>",
                "🗜 Tam Giác Nén " . round($channel['compression'] * 100) . "%  ({$lhCount} LH · {$hlCount} HL)",
            ],
        };

        // Với triangle: show cả 2 chiều; bounce: 1 chiều
        $ordersSection = '';
        foreach ($signals['orders'] as $order) {
            $note           = ($order['auto_corrected'] ?? false) ? '  <i>↺ tự điều chỉnh</i>' : '';
            $ordersSection .= $this->formatOrderBlock($order, $tpGia, $slGia) . $note . "\n";
        }

        // HTF bias line
        $htfLine = match ($htfBias) {
            'LONG'  => "📊 HTF(H4): ⬆ TĂNG  ✅ đồng thuận",
            'SHORT' => "📊 HTF(H4): ⬇ GIẢM  ✅ đồng thuận",
            default => "📊 HTF(H4): ➡ không rõ xu hướng",
        };

        return "{$header} — {$symbol} {$timeframe}  <i>{$time}</i>\n"
            . "━━━━━━━━━━━━━━━━━━━━\n"
            . "{$channelLabel}\n"
            . "   🏔 Upper : <code>{$upper}</code>   ⛰ Lower : <code>{$lower}</code>\n"
            . "   💰 Giá hiện tại : <code>{$currentPrice}</code>\n"
            . "━━━━━━━━━━━━━━━━━━━━\n"
            . $ordersSection
            . "━━━━━━━━━━━━━━━━━━━━\n"
            . "📐 LH:{$lhCount}  HL:{$hlCount}  HH:{$hhCount}  LL:{$llCount}  |  ATR {$atr} giá\n"
            . "{$htfLine}\n"
            . "💼 Lot: <b>{$lot}</b>  |  ❌ SL rủi ro: -\${$slUsd}";
    }

    private function formatOrderBlock(array $order, float $tpGia, float $slGia): string
    {
        $isSell = str_starts_with($order['side'], 'SELL');
        $label  = str_replace('_', ' ', $order['side']);
        $emoji  = $isSell ? '⬇' : '⬆';
        $tpSign = $isSell ? '-' : '+';
        $slSign = $isSell ? '+' : '-';
        $rr     = round($tpGia / $slGia, 2);

        return "{$emoji} <b>{$label}</b>\n"
            . "   📌 Entry : <code>{$order['entry']}</code>\n"
            . "   🎯 TP    : <code>{$order['tp']}</code>  ({$tpSign}{$tpGia} Giá)\n"
            . "   🛡 SL    : <code>{$order['sl']}</code>  ({$slSign}{$slGia} Giá)\n"
            . "   📊 R:R   : 1:{$rr}";
    }

    /**
     * Tin nhắn chúc mừng khi đạt mục tiêu ngày.
     */
    public function formatDailyTargetMessage(
        string $symbol,
        float  $gainGia,
        float  $targetGia
    ): string {
        return "🏆 <b>ĐẠT MỤC TIÊU NGÀY — {$symbol}</b>\n"
            . "━━━━━━━━━━━━━━━━━━━━\n"
            . "Tổng Giá hôm nay: <b>{$gainGia}</b> / mục tiêu <b>{$targetGia}</b>\n\n"
            . "✅ Felix đã khóa scan — <i>Nghỉ ngơi, đừng tham!</i>\n"
            . "🔄 Quét lại lúc đầu phiên ngày mai.";
    }
}
