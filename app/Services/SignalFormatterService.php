<?php

namespace App\Services;

/**
 * v5.2 — Signal Generator + Formatter cho XAUUSD (Vàng).
 *
 * ĐƠN VỊ CHUẨN XAUUSD (đồng bộ biểu đồ MT5):
 *   1 Giá  = $1.00 di chuyển (vd: 4400.00 → 4401.00)
 *   1 Pip  = 0.1 Giá = $0.10  (không dùng trong code, chỉ để tham chiếu)
 *   1 Lot Exness XAUUSD = 100 oz → 1 Giá × 1 Lot = $100 P&L
 *   Tất cả biến khoảng cách đều tính bằng "Giá" (USD/oz) — không dùng Pip
 *
 * Chiến thuật sinh lệnh:
 *   Triangle breakout: BUY STOP  (upper + 0.3 giá) | SELL STOP  (lower − 0.3 giá)
 *   Descending bounce: SELL LIMIT (upper − 0.3 giá) — chặn đầu khi giá chạm biên trên
 *   Ascending bounce:  BUY LIMIT  (lower + 0.3 giá) — chặn đầu khi giá chạm biên dưới
 *
 * 3 lớp bảo vệ cứng trong generateSafeSignal():
 *   1. R:R filter:     TP < SL → null
 *   2. Lot hard stop:  Raw lot < 0.01 → RuntimeException (vốn không đủ)
 *   3. Order validate: Entry vs CurrentPrice sai loại lệnh → auto-correct hoặc null
 */
class SignalFormatterService
{
    // Buffer: 0.3 giá ($0.30) — đủ tránh fakeout, không quá xa trendline
    private const BUF_GIA  = 0.3;
    // Tỷ lệ rủi ro mỗi lệnh: 2% vốn
    private const RISK_PCT = 0.02;
    // Tỷ lệ TP trên biên độ kênh
    private const TP_RATIO = 0.8;
    // Hệ số SL theo ATR
    private const SL_ATR_MULT = 1.5;
    // Lot tối thiểu sàn Exness
    private const MIN_LOT = 0.01;

    // ──────────────────────────────────────────────────────────────
    // PUBLIC: ENTRY POINT DUY NHẤT NÊN DÙNG TỪ COMMAND
    // ──────────────────────────────────────────────────────────────

    /**
     * Generator tín hiệu an toàn — 3 lớp kiểm tra cứng.
     *
     * Sử dụng hàm này thay vì buildSignals() để đảm bảo:
     *   - Lệnh không vi phạm quy tắc Entry vs CurrentPrice
     *   - Tài khoản đủ vốn để gánh SL (không ép vào 0.01 lot khi vốn quá nhỏ)
     *
     * @param array $marketData {
     *   symbol:        string,
     *   current_price: float,   Giá bid hiện tại (USD/oz)
     *   channel:       array,   Kết quả detectUnpredictableChannel()
     *   atr:           float,   Kết quả calculateATR(14)
     * }
     * @param float $accountCapital  Vốn tài khoản USD
     * @return array|null            null = không có setup hợp lệ (không phát alert)
     * @throws \RuntimeException     Khi vốn không đủ để gánh SL tối thiểu 0.01 lot
     */
    public function generateSafeSignal(array $marketData, float $accountCapital): ?array
    {
        $currentPrice = (float) ($marketData['current_price'] ?? 0);
        $channel      = $marketData['channel'];
        $atr          = (float) ($marketData['atr'] ?? 0);
        $symbol       = (string) ($marketData['symbol'] ?? 'XAUUSD');

        // ── Layer 1: Build signals (tích hợp R:R filter) ─────────
        $signals = $this->buildSignals($symbol, $channel, $accountCapital, $atr);
        if ($signals === null) {
            return null; // TP < SL: kênh quá hẹp so với ATR
        }

        // ── Layer 2: Lot Hard Stop ────────────────────────────────
        // Tính RAW lot (chưa clamp) để detect vốn quá nhỏ
        // Không dùng $signals['lot'] vì đó đã bị max(0.01,...) trong buildSignals
        $slGia  = $signals['sl_gia'];
        $rawLot = ($accountCapital > 0 && $slGia > 0)
            ? ($accountCapital * self::RISK_PCT) / ($slGia * 100.0)
            : 0.0;

        if ($rawLot < self::MIN_LOT) {
            // Vốn tối thiểu: ngược lại công thức lot, rủi ro 2%, lot = 0.01
            $minCapital = (int) ceil(self::MIN_LOT * $slGia * 100.0 / self::RISK_PCT);
            throw new \RuntimeException(
                "Tài khoản \${$accountCapital} không đủ vốn cho Setup này. "
                . "SL = {$slGia} giá → Lot tính được = " . round($rawLot, 5) . " (< 0.01 tối thiểu). "
                . "Cần ít nhất \${$minCapital} vốn để vào 0.01 lot với rủi ro " . (self::RISK_PCT * 100) . "%."
            );
        }

        // ── Layer 3: Validate + auto-correct loại lệnh ───────────
        $validOrders = [];
        foreach ($signals['orders'] as $order) {
            $validated = $this->validateAndCorrectOrder($order, $currentPrice);
            if ($validated !== null) {
                $validOrders[] = $validated;
            } else {
                \Log::warning(
                    "Signal rejected: {$order['side']}@{$order['entry']} "
                    . "— entry vs currentPrice={$currentPrice} mâu thuẫn, không auto-correct được."
                );
            }
        }

        if (empty($validOrders)) {
            return null; // Mọi lệnh đều sai logic entry vs price
        }

        return array_merge($signals, ['orders' => $validOrders]);
    }

    // ──────────────────────────────────────────────────────────────
    // LOT SIZING — Fixed Fractional 2%
    // ──────────────────────────────────────────────────────────────

    /**
     * Tính Lot Size theo Fixed Fractional với clamp về 0.01.
     *
     * Dùng trong buildSignals() cho mục đích hiển thị.
     * Để check vốn có đủ không → dùng raw lot trong generateSafeSignal().
     *
     * Exness XAUUSD: 1 Lot × 1 Giá ($1) × 100 oz = $100 P&L
     * → Lot = (Capital × %Risk) / (SL_giá × $100)
     */
    public function calculateExnessLot(float $capital, float $slGia, float $riskPct = 0.02): float
    {
        if ($capital <= 0 || $slGia <= 0) return self::MIN_LOT;
        return max(self::MIN_LOT, round(($capital * $riskPct) / ($slGia * 100.0), 2));
    }

    // ──────────────────────────────────────────────────────────────
    // SIGNAL BUILDER (internal — gọi qua generateSafeSignal())
    // ──────────────────────────────────────────────────────────────

    /**
     * Xây dựng tham số lệnh từ channel + ATR.
     * Không validate entry vs currentPrice — đó là việc của generateSafeSignal().
     *
     * @return array{orders, sl_gia, tp_gia, rr, lot}|null  null nếu R:R < 1:1
     */
    public function buildSignals(
        string $symbol,
        array  $channel,
        float  $capital,
        float  $atr
    ): ?array {
        $dec    = 2;
        $bufGia = self::BUF_GIA;  // 0.3 giá buffer

        // SL: 1.5 × ATR(14) — theo market noise thực tế
        $slGia = round(self::SL_ATR_MULT * $atr, 2);
        // TP: 80% chiều rộng kênh — không ăn trọn sóng
        $tpGia = round(($channel['upper'] - $channel['lower']) * self::TP_RATIO, 2);

        // R:R filter: kênh quá hẹp so với volatility
        if ($slGia <= 0 || $tpGia < $slGia) return null;

        $rr  = round($tpGia / $slGia, 2);
        $lot = $this->calculateExnessLot($capital, $slGia);

        $channelDir = $channel['direction'] ?? null;

        if ($channelDir === 'SHORT') {
            $entry  = round($channel['upper'] - $bufGia, $dec);
            $orders = [['side' => 'SELL_LIMIT', 'entry' => $entry,
                         'tp'  => round($entry - $tpGia, $dec),
                         'sl'  => round($entry + $slGia, $dec)]];

        } elseif ($channelDir === 'LONG') {
            $entry  = round($channel['lower'] + $bufGia, $dec);
            $orders = [['side' => 'BUY_LIMIT',  'entry' => $entry,
                         'tp'  => round($entry + $tpGia, $dec),
                         'sl'  => round($entry - $slGia, $dec)]];

        } else {
            // Triangle: 2 lệnh STOP chờ breakout
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
    // PRIVATE: ORDER TYPE VALIDATOR
    // ──────────────────────────────────────────────────────────────

    /**
     * Validate loại lệnh vs giá hiện tại — quy tắc cứng sàn Exness/MT5:
     *
     *   BUY STOP:   Entry > CurrentPrice  ✓  (đặt chờ breakout lên)
     *   SELL STOP:  Entry < CurrentPrice  ✓  (đặt chờ breakdown xuống)
     *   BUY LIMIT:  Entry < CurrentPrice  ✓  (đặt chờ giá kéo về)
     *   SELL LIMIT: Entry > CurrentPrice  ✓  (đặt chờ giá hồi phục)
     *
     * Nếu vi phạm → auto-correct sang loại lệnh đối lập (cùng hướng BUY/SELL).
     * Ví dụ: BUY STOP với entry < price → đổi thành BUY LIMIT (logic vẫn đúng: mua).
     *
     * Trả về null nếu side không xác định (lỗi logic nghiêm trọng).
     */
    private function validateAndCorrectOrder(array $order, float $currentPrice): ?array
    {
        // entry phải CAO HƠN currentPrice để loại lệnh này hợp lệ
        $entryMustBeAbove = [
            'BUY_STOP'   => true,   // chờ price tăng lên chạm entry → mua
            'SELL_LIMIT' => true,   // chờ price tăng lên chạm entry → bán
            'BUY_LIMIT'  => false,  // chờ price giảm xuống chạm entry → mua
            'SELL_STOP'  => false,  // chờ price giảm xuống chạm entry → bán
        ];

        // Khi entry vs price bị ngược: đổi cơ chế STOP ↔ LIMIT, giữ hướng BUY/SELL
        $counterpart = [
            'BUY_STOP'   => 'BUY_LIMIT',
            'BUY_LIMIT'  => 'BUY_STOP',
            'SELL_STOP'  => 'SELL_LIMIT',
            'SELL_LIMIT' => 'SELL_STOP',
        ];

        $side  = $order['side'];
        $entry = $order['entry'];

        if (!isset($entryMustBeAbove[$side])) return null;

        $mustBeAbove  = $entryMustBeAbove[$side];
        $entryIsAbove = $entry > $currentPrice;

        if ($mustBeAbove === $entryIsAbove) {
            return $order; // ✓ Logic đúng — giữ nguyên
        }

        // ✗ Logic sai → auto-correct sang counterpart
        $correctedSide = $counterpart[$side] ?? null;
        if ($correctedSide === null) return null;

        \Log::info("OrderType auto-corrected: {$side}@{$entry} → {$correctedSide} (currentPrice={$currentPrice})");

        return array_merge($order, ['side' => $correctedSide, 'auto_corrected' => true]);
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

        // Triangle: lọc lệnh theo AI direction
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
            $correctedNote  = ($order['auto_corrected'] ?? false) ? ' <i>(tự điều chỉnh)</i>' : '';
            $ordersSection .= $this->formatOrderBlock($order, $tpGia, $slGia) . $correctedNote . "\n";
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
     * Format 1 order block — hiển thị khoảng cách bằng đơn vị Giá (không dùng Pips).
     */
    private function formatOrderBlock(array $order, float $tpGia, float $slGia): string
    {
        $isSell = str_starts_with($order['side'], 'SELL');
        $emoji  = $isSell ? '⬇' : '⬆';
        $label  = str_replace('_', ' ', $order['side']);
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
