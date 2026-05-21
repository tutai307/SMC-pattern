<?php

namespace App\Services;

/**
 * v5.6 — Signal Generator + Formatter cho XAUUSD.
 *
 * ĐƠN VỊ CHUẨN XAUUSD (đồng bộ biểu đồ MT5):
 *   1 Giá = $1.00 di chuyển (vd: 4400.00 → 4401.00)
 *   1 Lot Exness XAUUSD = 100 oz → 1 Giá × 1 Lot = $100 P&L
 *
 * Chỉ 1 bài đánh:
 *   BÀI 1 — Đánh Phá Vỡ (Triangle):  BUY/SELL STOP, TP động theo ATR, SL cố định 2.0 giá
 *   BÀI 2 — Ascending/Descending:    DISABLED v5.6 (backtest WR 39-43%, lỗ ròng)
 *
 * 3 lớp bảo vệ trong generateSafeSignal():
 *   L1. Survival filter:  SL<=0 hoặc TP<SL → null
 *   L2. Lot hard stop:    Raw lot < 0.01 → RuntimeException
 *   L3. Order validate:   Entry vs CurrentPrice sai loại → auto-correct hoặc null
 */
class SignalFormatterService
{
    // Entry buffer — râu nến 1.0 giá để tránh fakeout (v5.6)
    private const BUF_GIA  = 1.0;

    // SL cố định — 2.0 giá từ điểm entry (bảo vệ tài khoản Cent)
    private const SL_GIA        = 2.0;

    // TP bounce (sweep-catch LIMIT) — đớp nhanh rút gọn theo thầy Quyết
    private const TP_BOUNCE_GIA = 1.5;

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
        $bufGia     = self::BUF_GIA;         // 1.0 giá — anti-fakeout / sweep depth
        $slGia      = self::SL_GIA;          // 2.0 giá cố định
        $channelDir = $channel['direction'] ?? null;

        if ($channelDir === null) {
            // ── BÀI 1: Đánh Phá Vỡ (Triangle) ─────────────────────
            // BUY/SELL STOP — ép thị trường phải phá breakout thật
            $tpGia  = $this->calcTpGia($atr);  // ATR-tiered: 1/2/3 giá
            $rr     = round($tpGia / $slGia, 2);
            $lot    = $this->calculateExnessLot($capital, $slGia);

            $lE = round($channel['upper'] + $bufGia, $dec);
            $sE = round($channel['lower'] - $bufGia, $dec);
            $orders = [
                ['side' => 'BUY_STOP',  'entry' => $lE,
                 'tp'   => round($lE + $tpGia, $dec), 'sl' => round($lE - $slGia, $dec)],
                ['side' => 'SELL_STOP', 'entry' => $sE,
                 'tp'   => round($sE - $tpGia, $dec), 'sl' => round($sE + $slGia, $dec)],
            ];

            return ['orders' => $orders, 'sl_gia' => $slGia, 'tp_gia' => $tpGia,
                    'rr' => $rr, 'lot' => $lot, 'atr' => round($atr, 2)];

        } else {
            // Ascending/Descending DISABLED v5.6 — không phát tín hiệu bounce
            return null;
        }
    }

    private function calcTpGia(float $atr): float
    {
        if ($atr < 1.5)  return 1.0; // Lực YẾU — đớp nhanh rút gọn
        if ($atr <= 3.0) return 2.0; // Lực TB  — chuẩn bài
        return 3.0;                   // Lực MẠNH/BÃO TIN
    }

    private function atrLabel(float $atr): string
    {
        if ($atr < 1.5)  return "Lực YẾU";
        if ($atr <= 3.0) return "Lực TB";
        return "Lực MẠNH";
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
     * Không auto-correct: sai entry position → reject (null).
     * Triangle STOP sai vị trí = giá đã phá vỡ rồi → tín hiệu lỗi thời, bỏ qua.
     * Bounce LIMIT sai vị trí = trendline đã bị phá → không còn setup hợp lệ.
     */
    private function validateAndCorrectOrder(array $order, float $currentPrice): ?array
    {
        $mustBeAbove = [
            'BUY_STOP'   => true,   // entry trên giá → chờ breakout lên
            'SELL_LIMIT' => true,   // entry trên giá → chờ hồi phục lên chặn râu
            'BUY_LIMIT'  => false,  // entry dưới giá → chờ quét râu xuống
            'SELL_STOP'  => false,  // entry dưới giá → chờ breakdown xuống
        ];

        $side  = $order['side'];
        $entry = $order['entry'];

        if (!isset($mustBeAbove[$side])) return null;

        $entryIsAbove = $entry > $currentPrice;

        if ($mustBeAbove[$side] !== $entryIsAbove) {
            \Log::warning("Signal rejected (stale): {$side}@{$entry} vs price={$currentPrice}");
            return null;
        }

        return $order;
    }

    // ──────────────────────────────────────────────────────────────
    // TELEGRAM MESSAGE
    // ──────────────────────────────────────────────────────────────

    /**
     * Format tin nhắn Telegram — 5 bước phân tích Top-Down đầy đủ.
     *
     * @param array $analysis {
     *   w1:     string  — W1 bias label
     *   d1:     string  — D1 bias label
     *   h4:     string  — H4 channel label
     *   reason: string  — lý do vào lệnh
     * }
     */
    public function formatTelegramMessage(
        string  $symbol,
        string  $timeframe,
        array   $channel,
        array   $signals,
        float   $currentPrice,
        array   $analysis = []
    ): string {
        $upper       = $channel['upper'];
        $lower       = $channel['lower'];
        $lhCount     = $channel['lh_count'] ?? 0;
        $hlCount     = $channel['hl_count'] ?? 0;
        $hhCount     = $channel['hh_count'] ?? 0;
        $llCount     = $channel['ll_count'] ?? 0;
        $compression = round(($channel['compression'] ?? 0) * 100);
        $channelType = $channel['type'] ?? 'triangle';

        $lot   = $signals['lot'];
        $slGia = $signals['sl_gia'];
        $tpGia = $signals['tp_gia'];
        $atr   = $signals['atr'] ?? 0;
        $slUsd = round($lot * $slGia * 100.0, 2);

        $time = now('Asia/Ho_Chi_Minh')->format('H:i d/m');

        $w1     = $analysis['w1']     ?? 'không rõ';
        $d1     = $analysis['d1']     ?? 'không rõ';
        $h4     = $analysis['h4']     ?? 'không rõ';

        // Label và reason tách biệt theo từng bài đánh
        [$header, $m15Pattern, $channelBlock, $reason] = match ($channelType) {
            'descending' => [
                "📉 <b>VÀNG SELL LIMIT — Chặn Râu Đỉnh</b>",
                "{$lhCount} đỉnh LH + {$llCount} đáy LL → Kênh Giảm Song Song",
                "📉 Kênh Giảm Song Song  (LH:{$lhCount} · LL:{$llCount})\n"
                    . "   🏔 Upper (đỉnh kênh): <code>{$upper}</code>\n"
                    . "   ⛰ Lower (đáy kênh) : <code>{$lower}</code>",
                $analysis['reason'] ?? 'Whale quét râu đỉnh kênh giảm, đón pullback xuống',
            ],
            'ascending' => [
                "📈 <b>VÀNG BUY LIMIT — Chặn Râu Đáy</b>",
                "{$hhCount} đỉnh HH + {$hlCount} đáy HL → Kênh Tăng Song Song",
                "📈 Kênh Tăng Song Song  (HH:{$hhCount} · HL:{$hlCount})\n"
                    . "   🏔 Upper (đỉnh kênh): <code>{$upper}</code>\n"
                    . "   ⛰ Lower (đáy kênh) : <code>{$lower}</code>",
                $analysis['reason'] ?? 'Whale quét râu đáy kênh tăng, đón bounce lên',
            ],
            default => [
                "🥇 <b>VÀNG BREAKOUT SETUP — Tam Giác Nén</b>",
                "{$lhCount} đỉnh LH + {$hlCount} đáy HL → Tam Giác Nén {$compression}%",
                "🗜 Tam Giác Nén {$compression}%  (LH:{$lhCount} · HL:{$hlCount})\n"
                    . "   🏔 Upper : <code>{$upper}</code>   ⛰ Lower : <code>{$lower}</code>",
                $analysis['reason'] ?? 'Phá vỡ Tam giác nén M15',
            ],
        };

        $ordersSection = '';
        foreach ($signals['orders'] as $order) {
            $ordersSection .= $this->formatOrderBlock($order, $tpGia, $slGia, $atr) . "\n";
        }

        return "{$header} — {$symbol} {$timeframe}  <i>{$time}</i>\n"
            . "━━━━━━━━━━━━━━━━━━━━\n"
            . "📋 <b>PHÂN TÍCH ĐA KHUNG (Top-Down)</b>\n"
            . "  ├ [W1 Bias]     {$w1}\n"
            . "  ├ [D1 Bias]     {$d1}\n"
            . "  ├ [H4 Trend]    {$h4}\n"
            . "  ├ [M15 Mẫu]     {$m15Pattern}\n"
            . "  └ [Lý do lệnh] {$reason}\n"
            . "━━━━━━━━━━━━━━━━━━━━\n"
            . "{$channelBlock}\n"
            . "   💰 Giá hiện tại : <code>{$currentPrice}</code>\n"
            . "━━━━━━━━━━━━━━━━━━━━\n"
            . $ordersSection
            . "━━━━━━━━━━━━━━━━━━━━\n"
            . "📐 ATR(14)M15: {$atr} giá ({$this->atrLabel($atr)})  |  SL cố định: {$slGia} giá\n"
            . "💼 Lot: <b>{$lot}</b>  |  ❌ SL rủi ro: -\${$slUsd}";
    }

    private function formatOrderBlock(array $order, float $tpGia, float $slGia, float $atr = 0): string
    {
        $isSell   = str_starts_with($order['side'], 'SELL');
        $label    = str_replace('_', ' ', $order['side']);
        $emoji    = $isSell ? '⬇' : '⬆';
        $tpSign   = $isSell ? '-' : '+';
        $slSign   = $isSell ? '+' : '-';
        $rr       = round($tpGia / $slGia, 2);
        $atrLbl   = $atr > 0 ? '  <i>' . $this->atrLabel($atr) . "</i>" : '';

        return "{$emoji} <b>{$label}</b>\n"
            . "   📌 Entry : <code>{$order['entry']}</code>\n"
            . "   🎯 TP    : <code>{$order['tp']}</code>  ({$tpSign}{$tpGia} giá{$atrLbl})\n"
            . "   🛡 SL    : <code>{$order['sl']}</code>  ({$slSign}{$slGia} giá cố định)\n"
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

    /**
     * Format Telegram cho MacroSwing v8.1 (FelixLocalTrader logic).
     *
     * @param string      $symbol
     * @param string      $timeframe
     * @param float       $currentPrice
     * @param float       $sh1          SwingHigh gần nhất
     * @param float       $sh2          SwingHigh cũ hơn (0 = không có)
     * @param float       $sl1          SwingLow gần nhất
     * @param float       $sl2          SwingLow cũ hơn (0 = không có)
     * @param float|null  $entryBuy     null = không có BUY setup
     * @param float|null  $slBuy
     * @param float|null  $entrySell    null = không có SELL setup
     * @param float|null  $slSell
     * @param string      $trendReason  ví dụ "UPTREND→BUY", "DOWN+WEDGE→BUY"
     * @param float       $lot
     * @param float       $fixedSL
     * @param float       $beTrigger    1.5
     * @param float       $lockProfit   0.3
     * @param float       $trailMult    0.30
     */
    public function formatMacroSwingMessage(
        string  $symbol,
        string  $timeframe,
        float   $currentPrice,
        float   $sh1,
        float   $sh2,
        float   $sl1,
        float   $sl2,
        ?float  $entryBuy,
        ?float  $slBuy,
        ?float  $tpBuy,
        ?float  $entrySell,
        ?float  $slSell,
        ?float  $tpSell,
        string  $trendReason,
        float   $lot,
        float   $fixedSL,
        float   $rr         = 3.0
    ): string {
        $time    = now('Asia/Ho_Chi_Minh')->format('H:i d/m');
        $riskUsd = round($fixedSL * $lot * 100, 2);
        $winUsd  = round($fixedSL * $rr * $lot * 100, 2);
        $tpPts   = round($fixedSL * $rr, 1);

        $trendEmoji = match(true) {
            str_contains($trendReason, 'UPTREND')   => '📈',
            str_contains($trendReason, 'DOWNTREND') => '📉',
            str_contains($trendReason, 'WEDGE')     => '🔺',
            default                                  => '➡',
        };

        $sh2Str = $sh2 > 0 ? " <i>/ {$sh2}</i>" : '';
        $sl2Str = $sl2 > 0 ? " <i>/ {$sl2}</i>" : '';

        $msg  = "🥇 <b>FELIX MACRO SWING — {$symbol} {$timeframe}</b>  <i>{$time}</i>\n";
        $msg .= "━━━━━━━━━━━━━━━━━━━━\n";
        $msg .= "  🏔 SwingHigh: <code>{$sh1}</code>{$sh2Str}\n";
        $msg .= "  ⛰ SwingLow : <code>{$sl1}</code>{$sl2Str}\n";
        $msg .= "  {$trendEmoji} <b>{$trendReason}</b>  |  Giá: <code>{$currentPrice}</code>\n";
        $msg .= "━━━━━━━━━━━━━━━━━━━━\n";

        if ($entryBuy !== null && $slBuy !== null && $tpBuy !== null) {
            $msg .= "⬆ <b>BUY STOP</b>\n";
            $msg .= "  📌 Entry : <code>{$entryBuy}</code>\n";
            $msg .= "  🎯 TP    : <code>{$tpBuy}</code>  (+{$tpPts} giá / R:R 1:{$rr})\n";
            $msg .= "  🛡 SL    : <code>{$slBuy}</code>  (-{$fixedSL} giá)\n";
            $msg .= "  💼 Lot: <b>{$lot}</b>  |  ✅ +\${$winUsd}  ❌ -\${$riskUsd}\n";
        }

        if ($entrySell !== null && $slSell !== null && $tpSell !== null) {
            if ($entryBuy !== null) $msg .= "\n";
            $msg .= "⬇ <b>SELL STOP</b>\n";
            $msg .= "  📌 Entry : <code>{$entrySell}</code>\n";
            $msg .= "  🎯 TP    : <code>{$tpSell}</code>  (-{$tpPts} giá / R:R 1:{$rr})\n";
            $msg .= "  🛡 SL    : <code>{$slSell}</code>  (+{$fixedSL} giá)\n";
            $msg .= "  💼 Lot: <b>{$lot}</b>  |  ✅ +\${$winUsd}  ❌ -\${$riskUsd}\n";
        }

        $msg .= "━━━━━━━━━━━━━━━━━━━━\n";
        $msg .= "⏳ Pending — hết hạn sau 4 giờ nếu không fill";

        return $msg;
    }
}
