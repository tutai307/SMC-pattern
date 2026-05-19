<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * v4 — Gold/Silver Price Action Engine.
 *
 * Chỉ làm 3 việc:
 *   1. detectUnpredictableChannel() — phát hiện tam giác nén M15
 *   2. calculateATR()               — ATR thô để tính SL
 *   3. scoreWithAI()                — gọi GPT-4o chấm điểm breakout
 */
class PriceActionService
{
    // ──────────────────────────────────────────────────────────────
    // PUBLIC API
    // ──────────────────────────────────────────────────────────────

    /**
     * Phát hiện kênh giá phân vân / tam giác nén (Compression Triangle).
     *
     * Điều kiện xác nhận:
     *   - Swing Highs liên tiếp tạo Lower Highs (LH ≥ 1 chuỗi)
     *   - Swing Lows  liên tiếp tạo Higher Lows (HL ≥ 1 chuỗi)
     *   - Cả hai cùng tồn tại trong cửa sổ lookback
     *
     * @return array{
     *   is_channel: bool,
     *   upper: float,        Đỉnh cứng — entry BUY STOP = upper + 3 pips
     *   lower: float,        Đáy cứng  — entry SELL STOP = lower - 3 pips
     *   compression: float,  0-1, càng gần 1 = nén càng chặt
     *   lh_count: int,
     *   hl_count: int,
     *   type: string
     * }
     */
    public function detectUnpredictableChannel(array $klines, int $lookback = 40): array
    {
        $empty = [
            'is_channel'  => false,
            'upper'       => 0.0,
            'lower'       => 0.0,
            'compression' => 0.0,
            'lh_count'    => 0,
            'hl_count'    => 0,
            'type'        => 'none',
        ];

        if (count($klines) < $lookback + 6) return $empty;

        $candles = $this->formatCandles(array_slice($klines, -($lookback + 6)));
        $swings  = $this->detectSwingPoints($candles, wing: 2);
        $highs   = array_slice($swings['highs'], -8);
        $lows    = array_slice($swings['lows'],  -8);

        if (count($highs) < 2 || count($lows) < 2) return $empty;

        // ── Chuỗi LH (Lower Highs) liên tiếp từ cuối trở về ──
        $lhCount = 0;
        for ($i = count($highs) - 1; $i >= 1; $i--) {
            if ($highs[$i]['price'] < $highs[$i - 1]['price']) $lhCount++;
            else break;
        }

        // ── Chuỗi HL (Higher Lows) liên tiếp từ cuối trở về ──
        $hlCount = 0;
        for ($i = count($lows) - 1; $i >= 1; $i--) {
            if ($lows[$i]['price'] > $lows[$i - 1]['price']) $hlCount++;
            else break;
        }

        // ── Chuỗi LL (Lower Lows) liên tiếp từ cuối trở về ──
        $llCount = 0;
        for ($i = count($lows) - 1; $i >= 1; $i--) {
            if ($lows[$i]['price'] < $lows[$i - 1]['price']) $llCount++;
            else break;
        }

        // ── Chuỗi HH (Higher Highs) liên tiếp từ cuối trở về ──
        $hhCount = 0;
        for ($i = count($highs) - 1; $i >= 1; $i--) {
            if ($highs[$i]['price'] > $highs[$i - 1]['price']) $hhCount++;
            else break;
        }

        $currentIdx = count($candles) - 1;

        // ── Kênh giảm: LH + LL (descending parallel channel) ──
        if ($lhCount >= 1 && $llCount >= 1 && $hlCount < 1) {
            // OLS regression trên toàn chuỗi LH và LL — mỗi swing đều đóng góp vào slope,
            // tránh bị lệch bởi một râu nến đơn lẻ khi chỉ dùng 2 điểm đầu/cuối.
            // lhChain: $lhCount+1 điểm liên tiếp cuối mảng highs tạo thành chuỗi LH
            $lhChain = array_slice($highs, count($highs) - 1 - $lhCount, $lhCount + 1);
            $llChain = array_slice($lows,  count($lows)  - 1 - $llCount, $llCount + 1);
            $projU   = $this->linearRegression($lhChain, $currentIdx);
            $projL   = $this->linearRegression($llChain, $currentIdx);

            if ($projU <= $projL) return array_merge($empty, ['lh_count' => $lhCount, 'hl_count' => $hlCount]);
            $w = $projU - $projL;
            $mid = ($projU + $projL) / 2;
            if ($mid > 0 && ($w / $mid) < 0.0015) return array_merge($empty, ['lh_count' => $lhCount, 'hl_count' => $hlCount]);
            return ['is_channel' => true, 'upper' => $projU, 'lower' => $projL,
                    'compression' => 0.5, 'lh_count' => $lhCount, 'hl_count' => $hlCount,
                    'll_count' => $llCount, 'direction' => 'SHORT', 'type' => 'descending'];
        }

        // ── Kênh tăng: HH + HL (ascending parallel channel) ──
        if ($hhCount >= 1 && $hlCount >= 1 && $lhCount < 1) {
            $hhChain = array_slice($highs, count($highs) - 1 - $hhCount, $hhCount + 1);
            $hlChain = array_slice($lows,  count($lows)  - 1 - $hlCount, $hlCount + 1);
            $projU   = $this->linearRegression($hhChain, $currentIdx);
            $projL   = $this->linearRegression($hlChain, $currentIdx);

            if ($projU <= $projL) return array_merge($empty, ['lh_count' => $lhCount, 'hl_count' => $hlCount]);
            $w = $projU - $projL;
            $mid = ($projU + $projL) / 2;
            if ($mid > 0 && ($w / $mid) < 0.0015) return array_merge($empty, ['lh_count' => $lhCount, 'hl_count' => $hlCount]);
            return ['is_channel' => true, 'upper' => $projU, 'lower' => $projL,
                    'compression' => 0.5, 'lh_count' => $lhCount, 'hl_count' => $hlCount,
                    'hh_count' => $hhCount, 'direction' => 'LONG', 'type' => 'ascending'];
        }

        // Trả về counts thực để caller có thể log lý do bị loại
        if ($lhCount < 1 || $hlCount < 1) {
            return array_merge($empty, ['lh_count' => $lhCount, 'hl_count' => $hlCount]);
        }

        $upper = (float) end($highs)['price'];
        $lower = (float) end($lows)['price'];

        if ($upper <= $lower) return $empty;

        $channelWidth = $upper - $lower;
        $midPrice     = ($upper + $lower) / 2;

        // Kênh phải đủ rộng tối thiểu 0.15% (≈ $3.5 trên gold @ $2350)
        if ($midPrice > 0 && ($channelWidth / $midPrice) < 0.0015) return $empty;

        // Compression: so kênh hiện tại vs kênh đầu chuỗi swing
        $origWidth   = abs($highs[0]['price'] - $lows[0]['price']);
        $compression = $origWidth > 0 ? round(1 - ($channelWidth / $origWidth), 3) : 0.0;

        return [
            'is_channel'  => true,
            'upper'       => round($upper, 2),
            'lower'       => round($lower, 2),
            'compression' => max(0.0, $compression),
            'lh_count'    => $lhCount,
            'hl_count'    => $hlCount,
            'direction'   => null,
            'type'        => 'triangle',
        ];
    }

    /**
     * ATR Wilder smoothing — dùng cho tính SL.
     * Trả về giá trị ATR cuối cùng (float, không phải array).
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

    /**
     * Chấm điểm breakout qua GPT-4o (OpenRouter).
     * Cache 30 phút per channel fingerprint — không gọi AI lặp cho cùng 1 setup.
     *
     * @return array{score: int, analysis: string, breakout_direction: string,
     *               confidence: string, risk_note: string, cached: bool}
     */
    public function scoreWithAI(
        string $symbol,
        string $timeframe,
        array  $channel,
        float  $atr,
        float  $currentPrice,
        array  $klines
    ): array {
        $fallback = [
            'score'               => 50,
            'analysis'            => 'AI không khả dụng',
            'breakout_direction'  => 'BOTH',
            'confidence'          => 'LOW',
            'risk_note'           => '',
            'cached'              => false,
        ];

        $apiKey = env('OPENROUTER_API_KEY');
        if (!$apiKey) return $fallback;

        $cacheKey = 'ai_channel_' . md5(
            $symbol . $timeframe .
            round($channel['upper'], 1) .
            round($channel['lower'], 1) .
            round($channel['compression'], 2)
        );

        $cached = Cache::get($cacheKey);
        if ($cached) {
            return array_merge($cached, ['cached' => true]);
        }

        try {
            $candles  = $this->formatCandles(array_slice($klines, -40));
            $last5    = array_slice($candles, -5);
            $bullCount = count(array_filter($last5, fn($c) => $c['close'] > $c['open']));
            $bearCount = 5 - $bullCount;

            $lastCandle  = end($candles);
            $bodySize    = $atr > 0 ? round(abs($lastCandle['close'] - $lastCandle['open']) / $atr, 2) : 0;
            $bodyQuality = $bodySize >= 1.5 ? 'mạnh' : ($bodySize >= 0.7 ? 'bình thường' : 'yếu/do dự');

            $distToUpper = $channel['upper'] > 0
                ? round(($channel['upper'] - $currentPrice) / $currentPrice * 100, 3)
                : 0;
            $distToLower = $channel['lower'] > 0
                ? round(($currentPrice - $channel['lower']) / $currentPrice * 100, 3)
                : 0;

            $prompt = <<<PROMPT
SYMBOL: {$symbol} | TIMEFRAME: {$timeframe}
CURRENT PRICE: {$currentPrice}

=== CHANNEL COMPRESSION ===
Đỉnh cứng (Upper): {$channel['upper']} — cách giá {$distToUpper}%
Đáy cứng (Lower):  {$channel['lower']} — cách giá {$distToLower}%
Nén: {$channel['compression']} (0=không nén, 1=nén hoàn toàn)
LH chain: {$channel['lh_count']} | HL chain: {$channel['hl_count']}

=== ATR & MOMENTUM ===
ATR(14) M15: {$atr}
5 nến gần nhất: {$bullCount} tăng / {$bearCount} giảm
Nến cuối body vs ATR: {$bodySize}x ({$bodyQuality})

Đây là kênh nén (Compression Triangle) trên M15 Gold/Silver.
Hãy đánh giá xác suất breakout và hướng breakout ưu thế.

Yêu cầu: CITE giá thực tế. KHÔNG dùng câu chung chung.
breakout_direction: "LONG" (breakout lên) | "SHORT" (breakout xuống) | "BOTH" (cả hai có thể) | "WAIT" (chưa đủ điều kiện)

JSON output:
{
  "score": 0-100,
  "analysis": "2-3 câu cite giá cụ thể: nhận xét compression, momentum, vị trí giá trong kênh",
  "breakout_direction": "LONG|SHORT|BOTH|WAIT",
  "confidence": "HIGH|MEDIUM|LOW",
  "risk_note": "1 rủi ro cụ thể với giá"
}
PROMPT;

            $client   = new \GuzzleHttp\Client(['timeout' => 12, 'connect_timeout' => 4]);
            $response = $client->post('https://openrouter.ai/api/v1/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type'  => 'application/json',
                    'HTTP-Referer'  => 'https://tomai.app',
                ],
                'json' => [
                    'model'           => 'openai/gpt-4o-mini',
                    'temperature'     => 0.15,
                    'messages'        => [
                        [
                            'role'    => 'system',
                            'content' => 'Bạn là senior gold/silver trader chuyên Price Action và Compression Breakout. Phân tích LUÔN dùng số liệu cụ thể. Chỉ trả về JSON hợp lệ.',
                        ],
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    'response_format' => ['type' => 'json_object'],
                ],
            ]);

            $body    = json_decode($response->getBody(), true);
            $content = $body['choices'][0]['message']['content'] ?? '{}';
            $data    = json_decode($content, true) ?? [];

            $result = [
                'score'              => is_numeric($data['score'] ?? null) ? min(100, max(0, (int) $data['score'])) : 50,
                'analysis'           => $this->flatten($data['analysis']           ?? ''),
                'breakout_direction' => strtoupper($data['breakout_direction']     ?? 'BOTH'),
                'confidence'         => strtoupper($data['confidence']             ?? 'LOW'),
                'risk_note'          => $this->flatten($data['risk_note']          ?? ''),
                'cached'             => false,
            ];

            Cache::put($cacheKey, $result, now()->addMinutes(30));
            return $result;

        } catch (\Exception $e) {
            \Log::warning('PriceActionService AI score: ' . $e->getMessage());
            return $fallback;
        }
    }

    // ──────────────────────────────────────────────────────────────
    // PRIVATE HELPERS
    // ──────────────────────────────────────────────────────────────

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
     * Phát hiện Swing Highs và Swing Lows.
     * wing: số nến mỗi bên phải thấp/cao hơn để xác nhận 1 swing point.
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
     * Hồi quy tuyến tính OLS (Ordinary Least Squares) trên tập swing points.
     *
     * Phương trình đường thẳng: y = m·x + b
     *   m = [n·Σ(xᵢ·yᵢ) - Σxᵢ·Σyᵢ] / [n·Σ(xᵢ²) - (Σxᵢ)²]
     *   b = (Σyᵢ - m·Σxᵢ) / n
     *
     * Mỗi swing point đóng góp đều vào slope → ổn định hơn so với vector 2 điểm đầu/cuối
     * khi tập dữ liệu có nhiễu (râu nến, spike giá đơn lẻ).
     *
     * @param  array $points  [['idx' => int, 'price' => float], ...]  đã sắp xếp theo thời gian
     * @param  int   $atIdx   Bar index cần chiếu giá trị (thường = bar hiện tại)
     * @return float          Giá trị đường xu hướng tại $atIdx (đơn vị: giá vàng USD/oz)
     */
    private function linearRegression(array $points, int $atIdx): float
    {
        $n = count($points);

        if ($n === 1) {
            return round($points[0]['price'], 2);
        }

        $sumX = 0.0; $sumY = 0.0; $sumXY = 0.0; $sumX2 = 0.0;
        foreach ($points as $p) {
            $x     = (float) $p['idx'];
            $y     = (float) $p['price'];
            $sumX  += $x;
            $sumY  += $y;
            $sumXY += $x * $y;
            $sumX2 += $x * $x;
        }

        // Mẫu số = 0 khi tất cả points cùng bar index (không thể xảy ra trong thực tế)
        $denom = $n * $sumX2 - $sumX * $sumX;
        if (abs($denom) < 1e-10) {
            return round($sumY / $n, 2);
        }

        $slope     = ($n * $sumXY - $sumX * $sumY) / $denom;
        $intercept = ($sumY - $slope * $sumX) / $n;

        return round($slope * $atIdx + $intercept, 2);
    }

    private function flatten(mixed $value): string
    {
        if (is_array($value)) return implode(' ', array_map('strval', $value));
        return (string) $value;
    }
}
