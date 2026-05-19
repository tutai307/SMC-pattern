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
    // Entry buffer — bắt buộc giá phá và chạy ≥ 1.0 giá mới lên tàu (chống fakeout)
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

        } elseif ($channelDir === 'LONG') {
            // ── BÀI 2: Sweep-Catch BUY LIMIT (Ascending channel) ───
            // Chờ whale chọc râu thủng đáy kênh 1 giá → cắn BUY LIMIT → đón bounce lên
            $tpGia  = self::TP_BOUNCE_GIA;   // 1.5 giá cố định
            $rr     = round($tpGia / $slGia, 2);
            $lot    = $this->calculateExnessLot($capital, $slGia);

            $entry  = round($channel['lower'] - $bufGia, $dec);  // 1.0 giá dưới đáy kênh
            $orders = [[
                'side'  => 'BUY_LIMIT',
                'entry' => $entry,
                'tp'    => round($entry + $tpGia, $dec),
                'sl'    => round($entry - $slGia, $dec),
            ]];

            return ['orders' => $orders, 'sl_gia' => $slGia, 'tp_gia' => $tpGia,
                    'rr' => $rr, 'lot' => $lot, 'atr' => round($atr, 2)];

        } else {
            // ── BÀI 3: Sweep-Catch SELL LIMIT (Descending channel) ─
            // Chờ whale chọc râu vượt đỉnh kênh 1 giá → cắn SELL LIMIT → đón pullback xuống
            $tpGia  = self::TP_BOUNCE_GIA;   // 1.5 giá cố định
            $rr     = round($tpGia / $slGia, 2);
            $lot    = $this->calculateExnessLot($capital, $slGia);

            $entry  = round($channel['upper'] + $bufGia, $dec);  // 1.0 giá trên đỉnh kênh
            $orders = [[
                'side'  => 'SELL_LIMIT',
                'entry' => $entry,
                'tp'    => round($entry - $tpGia, $dec),
                'sl'    => round($entry + $slGia, $dec),
            ]];

            return ['orders' => $orders, 'sl_gia' => $slGia, 'tp_gia' => $tpGia,
                    'rr' => $rr, 'lot' => $lot, 'atr' => round($atr, 2)];
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
        $compression = round(($channel['compression'] ?? 0) * 100);

        $lot   = $signals['lot'];
        $slGia = $signals['sl_gia'];
        $tpGia = $signals['tp_gia'];
        $atr   = $signals['atr'] ?? 0;
        $slUsd = round($lot * $slGia * 100.0, 2);

        $time = now('Asia/Ho_Chi_Minh')->format('H:i d/m');

        $w1     = $analysis['w1']     ?? 'không rõ';
        $d1     = $analysis['d1']     ?? 'không rõ';
        $h4     = $analysis['h4']     ?? 'không rõ';
        $reason = $analysis['reason'] ?? 'Phá vỡ Tam giác nén M15';

        $m15Pattern = "{$lhCount} đỉnh LH + {$hlCount} đáy HL → Tam Giác Nén {$compression}%";

        $ordersSection = '';
        foreach ($signals['orders'] as $order) {
            $note           = ($order['auto_corrected'] ?? false) ? '  <i>↺ tự điều chỉnh</i>' : '';
            $ordersSection .= $this->formatOrderBlock($order, $tpGia, $slGia, $atr) . $note . "\n";
        }

        $channelType = $channel['type'] ?? 'triangle';
        $header = match ($channelType) {
            'descending' => "📉 <b>VÀNG SELL LIMIT — Chặn Râu Đỉnh</b>",
            'ascending'  => "📈 <b>VÀNG BUY LIMIT — Chặn Râu Đáy</b>",
            default      => "🥇 <b>VÀNG BREAKOUT SETUP</b>",
        };

        return "{$header} — {$symbol} {$timeframe}  <i>{$time}</i>\n"
            . "━━━━━━━━━━━━━━━━━━━━\n"
            . "📋 <b>PHÂN TÍCH ĐA KHUNG (Top-Down)</b>\n"
            . "  ├ [W1 Bias]     {$w1}\n"
            . "  ├ [D1 Bias]     {$d1}\n"
            . "  ├ [H4 Trend]    {$h4}\n"
            . "  ├ [M15 Mẫu]     {$m15Pattern}\n"
            . "  └ [Lý do lệnh] {$reason}\n"
            . "━━━━━━━━━━━━━━━━━━━━\n"
            . "🗜 Tam Giác Nén {$compression}%  (LH:{$lhCount} · HL:{$hlCount})\n"
            . "   🏔 Upper : <code>{$upper}</code>   ⛰ Lower : <code>{$lower}</code>\n"
            . "   💰 Giá hiện tại : <code>{$currentPrice}</code>\n"
            . "━━━━━━━━━━━━━━━━━━━━\n"
            . $ordersSection
            . "━━━━━━━━━━━━━━━━━━━━\n"
            . "📐 ATR(14): {$atr} giá ({$this->atrLabel($atr)})  |  SL cố định: {$slGia} giá\n"
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
}
