<?php

namespace App\Services;

/**
 * v5.3 — Gold Price Action Engine — Toán học thuần túy, không AI.
 *
 * Phương pháp: Hình học kênh giá ông Quyết (Top-Down Analysis)
 *   W1/D1 → H4 (macro bias) → M15 (micro entry)
 *
 * 4 mô hình kênh giá (ưu tiên từ trên xuống):
 *   1. Expanding  (HH + LL): Biên mở rộng hai đầu → NGỒI CHƠI, null
 *   2. Descending (LH + LL, không HL): Xu hướng giảm → SELL LIMIT
 *   3. Ascending  (HH + HL, không LH): Xu hướng tăng → BUY LIMIT
 *   4. Triangle   (LH + HL, nén ≥ 50%): Bùng nổ → BUY+SELL STOP
 */
class PriceActionService
{
    // Nén tối thiểu để Tam Giác được coi là hội tụ đủ mạnh (Kênh cháy loại 2 hợp lệ)
    private const MIN_TRIANGLE_COMPRESSION = 0.50;  // 50%

    // Độ rộng kênh tối thiểu so với giá giữa (tránh kênh quá hẹp)
    private const MIN_CHANNEL_WIDTH_RATIO = 0.0015; // 0.15%

    // ──────────────────────────────────────────────────────────────
    // PUBLIC: PHÁT HIỆN KÊNH GIÁ
    // ──────────────────────────────────────────────────────────────

    /**
     * Phân tích cấu trúc swing và phân loại kênh giá theo 4 mô hình.
     *
     * Lookback: 100 nến M15 ≈ 25 giờ (đủ bắt kênh trong ngày + qua đêm).
     *
     * @return array{
     *   is_channel: bool,
     *   upper: float,      Đường kháng cự trên (chiếu đến bar hiện tại)
     *   lower: float,      Đường hỗ trợ dưới
     *   compression: float, 0-1 (chỉ có nghĩa với triangle)
     *   type: string,      'descending'|'ascending'|'triangle'|'expanding'|'none'
     *   direction: string|null, 'SHORT'|'LONG'|null (null = đánh hai chiều)
     *   lh_count: int,  hl_count: int,  hh_count: int,  ll_count: int
     * }
     */
    public function detectUnpredictableChannel(array $klines, int $lookback = 100): array
    {
        $empty = [
            'is_channel'  => false,
            'upper'       => 0.0,
            'lower'       => 0.0,
            'compression' => 0.0,
            'lh_count'    => 0,
            'hl_count'    => 0,
            'hh_count'    => 0,
            'll_count'    => 0,
            'type'        => 'none',
            'direction'   => null,
        ];

        if (count($klines) < $lookback + 6) return $empty;

        $candles    = $this->formatCandles(array_slice($klines, -($lookback + 6)));
        $swings     = $this->detectSwingPoints($candles, wing: 2);
        $highs      = array_slice($swings['highs'], -8);
        $lows       = array_slice($swings['lows'],  -8);
        $currentIdx = count($candles) - 1;

        if (count($highs) < 2 || count($lows) < 2) return $empty;

        // ── Đếm các chuỗi swing liên tiếp từ cuối về ──────────────

        // LH: Lower High — Đỉnh thấp dần (dấu hiệu áp lực bán)
        $lhCount = 0;
        for ($i = count($highs) - 1; $i >= 1; $i--) {
            if ($highs[$i]['price'] < $highs[$i - 1]['price']) $lhCount++;
            else break;
        }

        // HL: Higher Low — Đáy cao dần (dấu hiệu áp lực mua)
        $hlCount = 0;
        for ($i = count($lows) - 1; $i >= 1; $i--) {
            if ($lows[$i]['price'] > $lows[$i - 1]['price']) $hlCount++;
            else break;
        }

        // LL: Lower Low — Đáy thấp dần (xác nhận xu hướng giảm)
        $llCount = 0;
        for ($i = count($lows) - 1; $i >= 1; $i--) {
            if ($lows[$i]['price'] < $lows[$i - 1]['price']) $llCount++;
            else break;
        }

        // HH: Higher High — Đỉnh cao dần (xác nhận xu hướng tăng)
        $hhCount = 0;
        for ($i = count($highs) - 1; $i >= 1; $i--) {
            if ($highs[$i]['price'] > $highs[$i - 1]['price']) $hhCount++;
            else break;
        }

        $counts = [
            'lh_count' => $lhCount, 'hl_count' => $hlCount,
            'hh_count' => $hhCount, 'll_count' => $llCount,
        ];

        // ══════════════════════════════════════════════════════════
        // PHÂN LOẠI 4 MÔ HÌNH — theo thứ tự ưu tiên
        // ══════════════════════════════════════════════════════════

        // ── Ưu tiên 1: Kênh Cháy Tài Khoản Loại 1 ── TUYỆT ĐỐI KHÔNG TRADE
        // HH (đỉnh cao dần) đồng thời với LL (đáy thấp dần) → biên giãn 2 đầu
        // Giá đang trong giai đoạn bùng nổ không kiểm soát, cực kỳ nguy hiểm
        if ($hhCount >= 1 && $llCount >= 1) {
            return array_merge($empty, $counts, ['type' => 'expanding']);
        }

        // ── Ưu tiên 2: Kênh Giảm Song Song ── CHỈ SELL LIMIT
        // LH (đỉnh thấp dần) + LL (đáy thấp dần), KHÔNG có HL
        // Xu hướng GIẢM thuần — đường kháng cự và hỗ trợ cùng nghiêng xuống
        if ($lhCount >= 1 && $llCount >= 1 && $hlCount < 1) {
            // Kẻ trendline bằng OLS trên TOÀN chuỗi LH và LL
            $lhChain = array_slice($highs, count($highs) - 1 - $lhCount, $lhCount + 1);
            $llChain = array_slice($lows,  count($lows)  - 1 - $llCount, $llCount + 1);
            $projU   = $this->linearRegression($lhChain, $currentIdx);
            $projL   = $this->linearRegression($llChain, $currentIdx);

            if ($projU <= $projL) return array_merge($empty, $counts);
            if (!$this->isChannelWideEnough($projU, $projL)) return array_merge($empty, $counts);

            return array_merge($counts, [
                'is_channel'  => true,
                'upper'       => $projU,
                'lower'       => $projL,
                'compression' => 0.5, // giá trị placeholder, không dùng cho kênh có hướng
                'type'        => 'descending',
                'direction'   => 'SHORT',
            ]);
        }

        // ── Ưu tiên 3: Kênh Tăng Song Song ── CHỈ BUY LIMIT
        // HH (đỉnh cao dần) + HL (đáy cao dần), KHÔNG có LH
        // Xu hướng TĂNG thuần — cả kháng cự và hỗ trợ đều nghiêng lên
        if ($hhCount >= 1 && $hlCount >= 1 && $lhCount < 1) {
            $hhChain = array_slice($highs, count($highs) - 1 - $hhCount, $hhCount + 1);
            $hlChain = array_slice($lows,  count($lows)  - 1 - $hlCount, $hlCount + 1);
            $projU   = $this->linearRegression($hhChain, $currentIdx);
            $projL   = $this->linearRegression($hlChain, $currentIdx);

            if ($projU <= $projL) return array_merge($empty, $counts);
            if (!$this->isChannelWideEnough($projU, $projL)) return array_merge($empty, $counts);

            return array_merge($counts, [
                'is_channel'  => true,
                'upper'       => $projU,
                'lower'       => $projL,
                'compression' => 0.5,
                'type'        => 'ascending',
                'direction'   => 'LONG',
            ]);
        }

        // ── Ưu tiên 4: Tam Giác Nén ── BUY STOP + SELL STOP (Kênh cháy loại 2 hợp lệ)
        // LH (đỉnh thấp dần) + HL (đáy cao dần) đồng thời → biên đang CO LẠI
        // Áp lực tích lũy sắp bùng nổ. Bộ lọc: compression ≥ 50%
        if ($lhCount >= 1 && $hlCount >= 1) {
            $lhChain = array_slice($highs, count($highs) - 1 - $lhCount, $lhCount + 1);
            $hlChain = array_slice($lows,  count($lows)  - 1 - $hlCount, $hlCount + 1);
            $projU   = $this->linearRegression($lhChain, $currentIdx);
            $projL   = $this->linearRegression($hlChain, $currentIdx);

            if ($projU <= $projL) return array_merge($empty, $counts);
            if (!$this->isChannelWideEnough($projU, $projL)) return array_merge($empty, $counts);

            // Compression: chiều rộng kênh ĐÃ co lại bao nhiêu % so với điểm đầu chuỗi
            $origWidth   = abs(
                $highs[count($highs) - 1 - $lhCount]['price'] -
                $lows[count($lows)   - 1 - $hlCount]['price']
            );
            $currWidth   = $projU - $projL;
            $compression = $origWidth > 0 ? max(0.0, round(1 - ($currWidth / $origWidth), 3)) : 0.0;

            if ($compression < self::MIN_TRIANGLE_COMPRESSION) {
                // Nén chưa đủ chặt → nguy cơ tín hiệu giả cao, bỏ qua
                return array_merge($empty, $counts, [
                    'type'        => 'triangle_weak',
                    'compression' => $compression,
                ]);
            }

            return array_merge($counts, [
                'is_channel'  => true,
                'upper'       => $projU,
                'lower'       => $projL,
                'compression' => $compression,
                'type'        => 'triangle',
                'direction'   => null, // Hai chiều — STOP cả BUY lẫn SELL
            ]);
        }

        // Không khớp mô hình nào → bỏ qua phiên này
        return array_merge($empty, $counts);
    }

    /**
     * Xác định xu hướng macro từ H4 để làm bộ lọc định hướng M15.
     *
     * Nguyên lý Top-Down: M15 entry PHẢI cùng chiều H4 macro.
     * H4 ascending → chỉ chấp nhận M15 BUY LIMIT.
     * H4 descending → chỉ chấp nhận M15 SELL LIMIT.
     * H4 triangle / không rõ → không lọc, chấp nhận M15 tín hiệu bất kỳ.
     *
     * @param  array $h4Klines  Klines khung H4 (tối thiểu 20 nến)
     * @return string|null      'LONG' | 'SHORT' | null (xu hướng không rõ hoặc không đủ data)
     */
    public function getHTFBias(array $h4Klines): ?string
    {
        if (count($h4Klines) < 20) return null;

        // 50 nến H4 ≈ 200h ≈ 8 ngày — đủ thấy xu hướng tuần
        $channel = $this->detectUnpredictableChannel($h4Klines, lookback: 50);

        if (!$channel['is_channel']) return null;

        // Triangle trên H4 = không rõ macro → không lọc hướng M15
        return $channel['direction'];
    }

    /**
     * ATR Wilder smoothing — dùng cho SL động của bounce setup.
     * Trả về giá trị ATR cuối cùng (float, đơn vị: giá USD/oz).
     */
    public function calculateATR(array $klines, int $period = 14): float
    {
        if (count($klines) < $period + 2) return 0.0;

        $candles = $this->formatCandles($klines);
        $tr      = [0.0];
        for ($i = 1; $i < count($candles); $i++) {
            $h  = $candles[$i]['high'];
            $l  = $candles[$i]['low'];
            $pc = $candles[$i - 1]['close'];
            $tr[] = max($h - $l, abs($h - $pc), abs($l - $pc));
        }

        $atr = array_sum(array_slice($tr, 1, $period)) / $period;
        for ($i = $period + 1; $i < count($tr); $i++) {
            $atr = ($atr * ($period - 1) + $tr[$i]) / $period;
        }
        return round($atr, 4);
    }

    // ──────────────────────────────────────────────────────────────
    // PRIVATE HELPERS
    // ──────────────────────────────────────────────────────────────

    /** Kiểm tra kênh đủ rộng tối thiểu 0.15% giá giữa */
    private function isChannelWideEnough(float $upper, float $lower): bool
    {
        $mid = ($upper + $lower) / 2;
        return $mid > 0 && (($upper - $lower) / $mid) >= self::MIN_CHANNEL_WIDTH_RATIO;
    }

    private function formatCandles(array $klines): array
    {
        return array_map(fn($k) => [
            'time'   => (int)   $k[0],
            'open'   => (float) $k[1],
            'high'   => (float) $k[2],
            'low'    => (float) $k[3],
            'close'  => (float) $k[4],
            'volume' => (float) $k[5],
        ], $klines);
    }

    /**
     * Phát hiện Swing Highs và Swing Lows bằng thuật toán wing=2.
     * Một điểm là swing high nếu cao hơn tất cả 2 nến ở mỗi bên.
     */
    private function detectSwingPoints(array $candles, int $wing = 2): array
    {
        $highs = [];
        $lows  = [];
        $n     = count($candles);

        for ($i = $wing; $i < $n - $wing; $i++) {
            $h = $candles[$i]['high'];
            $l = $candles[$i]['low'];
            $isHigh = $isLow = true;

            for ($j = $i - $wing; $j <= $i + $wing; $j++) {
                if ($j === $i) continue;
                if ($candles[$j]['high'] >= $h) $isHigh = false;
                if ($candles[$j]['low']  <= $l) $isLow  = false;
            }
            if ($isHigh) $highs[] = ['idx' => $i, 'price' => $h, 'time' => $candles[$i]['time']];
            if ($isLow)  $lows[]  = ['idx' => $i, 'price' => $l, 'time' => $candles[$i]['time']];
        }
        return ['highs' => $highs, 'lows' => $lows];
    }

    /**
     * Hồi quy tuyến tính OLS trên tập swing points.
     *
     *   m = [n·Σ(xᵢ·yᵢ) - Σxᵢ·Σyᵢ] / [n·Σ(xᵢ²) - (Σxᵢ)²]
     *   b = (Σy - m·Σx) / n
     *   → projected = m·atIdx + b
     *
     * Mỗi swing point đóng góp đều vào slope → ổn định hơn vector 2 điểm.
     */
    private function linearRegression(array $points, int $atIdx): float
    {
        $n = count($points);
        if ($n === 1) return round($points[0]['price'], 2);

        $sumX = 0.0; $sumY = 0.0; $sumXY = 0.0; $sumX2 = 0.0;
        foreach ($points as $p) {
            $x     = (float) $p['idx'];
            $y     = (float) $p['price'];
            $sumX  += $x;
            $sumY  += $y;
            $sumXY += $x * $y;
            $sumX2 += $x * $x;
        }

        $denom = $n * $sumX2 - $sumX * $sumX;
        if (abs($denom) < 1e-10) return round($sumY / $n, 2);

        $slope     = ($n * $sumXY - $sumX * $sumY) / $denom;
        $intercept = ($sumY - $slope * $sumX) / $n;

        return round($slope * $atIdx + $intercept, 2);
    }
}
