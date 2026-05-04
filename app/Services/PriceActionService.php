<?php

namespace App\Services;

class PriceActionService
{
    /**
     * Analyze market data with SMC (Smart Money Concepts).
     */
    public function analyze(array $klines, array $klinesHTF = [], string $method = 'smc', string $symbol = '', string $timeframe = '')
    {
        $default = [
            'method' => $method,
            'structure' => ['trend' => 'không rõ', 'last_price' => 0, 'bos' => false, 'choch' => false],
            'orderBlocks' => [],
            'fvgs' => [],
            'waves' => [],
            'volumeProfile' => ['bins' => [], 'poc' => 0],
            'signal' => null,
            'htf_trend' => 'không rõ',
            'indicators' => []
        ];

        if (empty($klines) || count($klines) < 100) return $default;

        $candles = $this->formatCandles($klines);
        $candlesHTF = !empty($klinesHTF) ? $this->formatCandles($klinesHTF) : [];

        // 1. Indicators
        $ema200 = $this->calculateEMA($candles, 200);
        $atr = $this->calculateATR($candles, 14);
        $adx = $this->calculateADX($candles, 14);

        // 2. SMC Structure
        $structure = $this->detectSMCStructure($candles);
        $htfStructure = !empty($candlesHTF) ? $this->detectSMCStructure($candlesHTF) : ['trend' => 'unknown'];

        $volumeProfile = $this->calculateVolumeProfile($candles);

        // 4. Analysis by Method
        $waves = [];
        $orderBlocks = [];
        $fvgs = [];

        if ($method === 'elliot') {
            $waves = $this->detectElliotWaves($candles);
            $signal = $this->generateElliotSignal($waves, end($candles)['close'], end($atr), end($adx), $structure, $htfStructure);
        } else {
            $fvgs = $this->detectFVG($candles);
            $orderBlocks = $this->findHighQualityOB($candles, $fvgs);
            $htfOBs = !empty($candlesHTF) ? $this->findHighQualityOB($candlesHTF, $this->detectFVG($candlesHTF)) : [];
            foreach ($htfOBs as &$ob) { $ob['label'] = 'HTF ' . $ob['label']; }
            $orderBlocks = array_merge($orderBlocks, array_slice($htfOBs, -2));

            $signal = $this->generateSMCSignal($candles, $structure, $orderBlocks, $fvgs, $htfStructure, $htfOBs ?? [], $volumeProfile['poc'], $adx, $atr);
        }

        // 5. Advanced AI Scoring
        if ($signal) {
            $indicators = ['adx' => end($adx), 'atr' => end($atr), 'ema200' => end($ema200)];
            $signal = $this->enrichWithAIScore($signal, array_slice($candles, -20), $structure, $htfStructure, $method, $symbol, $timeframe, $indicators);
        }

        return [
            'method' => $method,
            'structure' => $structure,
            'htf_trend' => $htfStructure['trend'],
            'orderBlocks' => $orderBlocks,
            'fvgs' => array_slice($fvgs, -5),
            'waves' => $waves,
            'volumeProfile' => $volumeProfile,
            'signal' => $signal,
            'indicators' => [
                'adx' => end($adx),
                'atr' => end($atr),
                'ema200' => end($ema200)
            ]
        ];
    }

    // Dùng bởi MonitorSignalsCommand
    public function getStructure(array $klines): array
    {
        if (count($klines) < 50) return ['trend' => 'không rõ', 'bos' => false, 'choch' => false, 'last_price' => 0];
        return $this->detectSMCStructure($this->formatCandles($klines));
    }

    private function formatCandles(array $klines)
    {
        return array_map(function($k) {
            return [
                'time' => $k[0],
                'open' => (float)$k[1],
                'high' => (float)$k[2],
                'low' => (float)$k[3],
                'close' => (float)$k[4],
                'volume' => (float)$k[5],
            ];
        }, $klines);
    }

    /**
     * Detect SMC Market Structure (BOS, CHoCH, Trend).
     */
    private function detectSMCStructure(array $candles)
    {
        $count = count($candles);
        if ($count < 50) return ['trend' => 'không rõ', 'bos' => false, 'choch' => false];

        $trend = 'ĐI NGANG';
        $bos = false;
        $choch = false;

        // Simplified SMC Structure
        $lastHighs = [];
        $lastLows = [];
        
        for ($i = 40; $i < $count - 2; $i++) {
            if ($candles[$i]['high'] > $candles[$i-1]['high'] && $candles[$i]['high'] > $candles[$i+1]['high']) {
                $lastHighs[] = $candles[$i]['high'];
            }
            if ($candles[$i]['low'] < $candles[$i-1]['low'] && $candles[$i]['low'] < $candles[$i+1]['low']) {
                $lastLows[] = $candles[$i]['low'];
            }
        }

        $lastPrice = $candles[$count - 1]['close'];
        
        if (count($lastHighs) >= 2 && count($lastLows) >= 2) {
            $h1 = $lastHighs[count($lastHighs)-2];
            $h2 = $lastHighs[count($lastHighs)-1];
            $l1 = $lastLows[count($lastLows)-2];
            $l2 = $lastLows[count($lastLows)-1];

            if ($h2 > $h1 && $l2 > $l1) $trend = 'TĂNG GIÁ';
            if ($h2 < $h1 && $l2 < $l1) $trend = 'GIẢM GIÁ';

            // Detect BOS
            if ($trend == 'TĂNG GIÁ' && $lastPrice > $h2) $bos = true;
            if ($trend == 'GIẢM GIÁ' && $lastPrice < $l2) $bos = true;
            
            // Detect CHoCH (Aggressive reversal)
            if ($trend == 'GIẢM GIÁ' && $lastPrice > $h2) $choch = true;
            if ($trend == 'TĂNG GIÁ' && $lastPrice < $l2) $choch = true;
        }

        return [
            'trend' => $trend,
            'last_price' => $lastPrice,
            'bos' => $bos,
            'choch' => $choch
        ];
    }

    /**
     * Detect Fair Value Gaps (FVG).
     */
    private function detectFVG(array $candles)
    {
        $fvgs = [];
        $count = count($candles);
        
        for ($i = 2; $i < $count - 1; $i++) {
            $prev = $candles[$i-1];
            $curr = $candles[$i];
            $next = $candles[$i+1];

            // Bullish FVG
            if ($next['low'] > $prev['high']) {
                $fvgs[] = [
                    'type' => 'BULLISH',
                    'top' => $next['low'],
                    'bottom' => $prev['high'],
                    'price' => ($next['low'] + $prev['high']) / 2,
                    'index' => $i
                ];
            }
            // Bearish FVG
            if ($next['high'] < $prev['low']) {
                $fvgs[] = [
                    'type' => 'BEARISH',
                    'top' => $prev['low'],
                    'bottom' => $next['high'],
                    'price' => ($prev['low'] + $next['high']) / 2,
                    'index' => $i
                ];
            }
        }
        return $fvgs;
    }

    /**
     * Find Order Blocks with displacement and FVG confirmation.
     */
    private function findHighQualityOB(array $candles, array $fvgs)
    {
        $obs = [];
        $count = count($candles);
        
        // Map FVGs by index for quick lookup
        $fvgIndices = array_column($fvgs, 'index');

        for ($i = 5; $i < $count - 3; $i++) {
            $current = $candles[$i];
            $displacementCandle = $candles[$i+1];
            
            $bodySize = abs($displacementCandle['close'] - $displacementCandle['open']);
            $avgBody = 0;
            for($j=$i-5; $j<$i; $j++) $avgBody += abs($candles[$j]['close'] - $candles[$j]['open']);
            $avgBody /= 5;

            // Check for Displacement (1.8x average body)
            if ($bodySize > $avgBody * 1.8) {
                $hasFvgNearby = in_array($i+1, $fvgIndices) || in_array($i+2, $fvgIndices);
                if (!$hasFvgNearby) continue;

                $type = ($displacementCandle['close'] > $displacementCandle['open']) ? 'demand' : 'supply';
                $obs[] = [
                    'type' => $type,
                    'top' => $current['high'],
                    'bottom' => $current['low'],
                    'price' => $current['close'],
                    'label' => ($type == 'demand' ? 'SMC DEMAND' : 'SMC SUPPLY'),
                    'strength' => 'HIGH'
                ];
            }
        }

        return array_slice($obs, -5);
    }

    private function generateSMCSignal($candles, $structure, $zones, $fvgs, $htfStructure, $htfZones, $poc, $adx, $atr)
    {
        $lastPrice = $structure['last_price'];
        $lastAdx = end($adx);
        $lastAtr = end($atr);
        
        if ($lastAdx < 15) return null; // Lọc thị trường quá lặng sóng (sideway không biên độ)

        // Duyệt từ zone gần nhất (cuối mảng) -> xa nhất để ưu tiên zone mới nhất
        $reversedZones = array_reverse($zones);

        foreach ($reversedZones as $zone) {
            $buffer = $lastAtr * 0.5;

            // --- SMC LONG SETUP (Buy Limit) ---
            // Entry PHẢI nằm DƯỚI giá hiện tại (đợi giá rớt về Demand rồi mới mua)
            if ($zone['type'] == 'demand') {
                $entry = ($zone['top'] + $zone['bottom']) / 2; // Midpoint of Demand (Mean Threshold)
                
                // RULE: Entry Buy Limit phải THẤP HƠN giá hiện tại
                // Nếu giá đã rớt dưới cả zone -> zone đã bị xuyên thủng (mitigated), bỏ qua
                if ($entry >= $lastPrice) continue;
                if ($lastPrice < $zone['bottom'] - $buffer) continue; // Giá đã rớt quá xa dưới zone
                
                // Giá phải đang ở gần zone (trong tầm buffer) để tín hiệu có ý nghĩa
                if ($lastPrice > $zone['top'] + $buffer * 3) continue; // Giá đã bay quá xa lên trên zone
                    
                $isCounterTrend = ($htfStructure['trend'] == 'GIẢM GIÁ');
                $confluence = 0;
                if ($structure['choch'] || $structure['bos']) $confluence += 20;
                if ($htfStructure['trend'] == 'TĂNG GIÁ') $confluence += 30;
                
                // Check if entry zone aligns with a bullish FVG
                $inFvg = false;
                foreach(array_slice($fvgs, -5) as $f) {
                    if ($f['type'] == 'BULLISH' && $entry >= $f['bottom'] && $entry <= $f['top']) {
                        $inFvg = true; break;
                    }
                }
                if ($inFvg) $confluence += 15;

                $confidence = 50 + $confluence;
                if ($isCounterTrend) $confidence -= 20;

                if ($confidence < 40) continue;

                // SL an toàn dưới đáy zone cộng thêm buffer
                $sl = $zone['bottom'] - ($lastAtr * 0.2);
                // TP R:R 1:3
                $tp = $entry + ($entry - $sl) * 3.0;

                return [
                    'type' => 'MUA',
                    'entry' => round($entry, 2),
                    'tp' => round($tp, 2), 
                    'sl' => round($sl, 2), 
                    'winrate' => min(95, $confidence),
                    'reason' => "SMC: Đặt lệnh Buy Limit chờ giá hồi về 50% vùng Demand (Mean Threshold). Kiên nhẫn đợi cấu trúc retest.",
                    'is_counter_trend' => $isCounterTrend
                ];
            }

            // --- SMC SHORT SETUP (Sell Limit) ---
            // Entry PHẢI nằm TRÊN giá hiện tại (đợi giá hồi lên Supply rồi mới bán)
            if ($zone['type'] == 'supply') {
                $entry = ($zone['top'] + $zone['bottom']) / 2; // Midpoint of Supply (Mean Threshold)
                
                // RULE: Entry Sell Limit phải CAO HƠN giá hiện tại
                // Nếu giá đã vượt lên trên cả zone -> zone đã bị xuyên thủng (mitigated), bỏ qua
                if ($entry <= $lastPrice) continue;
                if ($lastPrice > $zone['top'] + $buffer) continue; // Giá đã bay quá xa trên zone
                
                // Giá phải đang ở gần zone (trong tầm buffer) để tín hiệu có ý nghĩa
                if ($lastPrice < $zone['bottom'] - $buffer * 3) continue; // Giá đã rớt quá xa dưới zone
                    
                $isCounterTrend = ($htfStructure['trend'] == 'TĂNG GIÁ');
                $confluence = 0;
                if ($structure['choch'] || $structure['bos']) $confluence += 20;
                if ($htfStructure['trend'] == 'GIẢM GIÁ') $confluence += 30;
                
                // Check if entry zone aligns with a bearish FVG
                $inFvg = false;
                foreach(array_slice($fvgs, -5) as $f) {
                    if ($f['type'] == 'BEARISH' && $entry >= $f['bottom'] && $entry <= $f['top']) {
                        $inFvg = true; break;
                    }
                }
                if ($inFvg) $confluence += 15;

                $confidence = 50 + $confluence;
                if ($isCounterTrend) $confidence -= 20;

                if ($confidence < 60) continue;

                // SL an toàn trên đỉnh zone cộng thêm buffer
                $sl = $zone['top'] + ($lastAtr * 0.2);
                // TP R:R 1:3
                $tp = $entry - ($sl - $entry) * 3.0;

                return [
                    'type' => 'BÁN',
                    'entry' => round($entry, 2),
                    'tp' => round($tp, 2), 
                    'sl' => round($sl, 2), 
                    'winrate' => min(95, $confidence),
                    'reason' => "SMC: Đặt lệnh Sell Limit chờ giá hồi về 50% vùng Supply (Mean Threshold). Kiên nhẫn đợi cấu trúc retest.",
                    'is_counter_trend' => $isCounterTrend
                ];
            }
        }

        return null;
    }

    private function calculateADX(array $candles, int $period)
    {
        $count = count($candles);
        $adx = array_fill(0, $count, 0);
        if ($count < $period * 2) return $adx;

        $tr = []; $dmPlus = []; $dmMinus = [];
        for ($i = 1; $i < $count; $i++) {
            $h = $candles[$i]['high']; $l = $candles[$i]['low'];
            $ph = $candles[$i-1]['high']; $pl = $candles[$i-1]['low']; $pc = $candles[$i-1]['close'];

            $tr[] = max($h - $l, abs($h - $pc), abs($l - $pc));
            $dmPlus[] = ($h - $ph > $pl - $l) ? max($h - $ph, 0) : 0;
            $dmMinus[] = ($pl - $l > $h - $ph) ? max($pl - $l, 0) : 0;
        }

        // Simplified Wilder's smoothing
        $smoothTR = array_sum(array_slice($tr, 0, $period));
        $smoothDP = array_sum(array_slice($dmPlus, 0, $period));
        $smoothDM = array_sum(array_slice($dmMinus, 0, $period));

        for ($i = $period; $i < $count; $i++) {
            $diPlus = 100 * ($smoothDP / ($smoothTR ?: 1));
            $diMinus = 100 * ($smoothDM / ($smoothTR ?: 1));
            $dx = 100 * abs($diPlus - $diMinus) / ($diPlus + $diMinus ?: 1);
            $adx[$i] = $dx;

            if (isset($tr[$i])) {
                $smoothTR = $smoothTR - ($smoothTR / $period) + $tr[$i];
                $smoothDP = $smoothDP - ($smoothDP / $period) + $dmPlus[$i];
                $smoothDM = $smoothDM - ($smoothDM / $period) + $dmMinus[$i];
            }
        }

        return $adx;
    }

    private function calculateATR(array $candles, int $period)
    {
        $tr = [0];
        for ($i = 1; $i < count($candles); $i++) {
            $h = $candles[$i]['high']; $l = $candles[$i]['low']; $pc = $candles[$i-1]['close'];
            $tr[] = max($h - $l, abs($h - $pc), abs($l - $pc));
        }
        $atr = [];
        $sum = array_sum(array_slice($tr, 0, $period));
        $atr = array_fill(0, $period, $sum / $period);
        for ($i = $period; $i < count($tr); $i++) {
            $val = (end($atr) * ($period - 1) + $tr[$i]) / $period;
            $atr[] = $val;
        }
        return $atr;
    }

    private function calculateEMA(array $candles, int $period)
    {
        $values = array_column($candles, 'close');
        $multiplier = 2 / ($period + 1);
        $ema = [array_sum(array_slice($values, 0, $period)) / $period];
        for ($i = $period; $i < count($values); $i++) {
            $ema[] = ($values[$i] - end($ema)) * $multiplier + end($ema);
        }
        return array_merge(array_fill(0, $period - 1, 0), $ema);
    }

    private function calculateVolumeProfile(array $candles)
    {
        if (empty($candles)) return ['bins' => [], 'poc' => 0];
        $high = max(array_column($candles, 'high'));
        $low = min(array_column($candles, 'low'));
        $numBins = 24; $binSize = ($high - $low) / $numBins ?: 1;
        $bins = [];
        for($i=0; $i<$numBins; $i++) $bins[$i] = ['price' => $low + ($i * $binSize), 'volume' => 0];
        foreach ($candles as $c) {
            $idx = min($numBins - 1, floor(($c['close'] - $low) / $binSize));
            $bins[max(0, $idx)]['volume'] += $c['volume'];
        }
        usort($bins, function($a, $b) { return $b['volume'] <=> $a['volume']; });
        return ['bins' => $bins, 'poc' => $bins[0]['price'] ?? 0];
    }

    /**
     * Detect Elliot Waves with Rule Validation.
     */
    private function detectElliotWaves(array $candles): array
    {
        $count = count($candles);
        if ($count < 50) return [];

        // Adaptive window: đủ lớn để lọc noise nhưng không cắt quá nhiều dữ liệu gần nhất
        $window = max(5, min(12, (int)($count / 40)));

        $rawPivots = [];
        for ($i = $window; $i < $count - $window; $i++) {
            $isHigh = true; $isLow = true;
            for ($j = $i - $window; $j <= $i + $window; $j++) {
                if ($candles[$j]['high'] > $candles[$i]['high']) $isHigh = false;
                if ($candles[$j]['low']  < $candles[$i]['low'])  $isLow  = false;
            }
            if ($isHigh) $rawPivots[] = ['type' => 'high', 'price' => $candles[$i]['high'], 'time' => $candles[$i]['time']];
            if ($isLow)  $rawPivots[] = ['type' => 'low',  'price' => $candles[$i]['low'],  'time' => $candles[$i]['time']];
        }

        // FIX Bug#1: Enforce alternation — consecutive same-type pivots: keep the more extreme one
        $pivots = [];
        foreach ($rawPivots as $p) {
            if (empty($pivots)) { $pivots[] = $p; continue; }
            $last = end($pivots);
            if ($last['type'] === $p['type']) {
                if ($p['type'] === 'high' && $p['price'] >= $last['price']) {
                    array_pop($pivots); $pivots[] = $p;
                } elseif ($p['type'] === 'low' && $p['price'] <= $last['price']) {
                    array_pop($pivots); $pivots[] = $p;
                }
            } else {
                $pivots[] = $p;
            }
        }

        $waves = [];

        // Thử nhận dạng 5-sóng đẩy + A-B-C từ 9 pivot gần nhất
        if (count($pivots) >= 9) {
            $p = array_slice($pivots, -9);

            // p[0]=origin, p[1]=W1, p[2]=W2, p[3]=W3, p[4]=W4, p[5]=W5, p[6]=A, p[7]=B, p[8]=C
            $bullish = ($p[0]['type'] === 'low');

            // Kiểm tra xen kẽ đúng chuẩn (low-high-low-high-...)
            $validAlt = true;
            for ($i = 0; $i < 9; $i++) {
                $expected = (($bullish && $i % 2 === 0) || (!$bullish && $i % 2 === 1)) ? 'low' : 'high';
                if ($p[$i]['type'] !== $expected) { $validAlt = false; break; }
            }

            if ($validAlt) {
                // FIX Bug#2: Đo đúng độ dài sóng đẩy (1, 3, 5) — không phải sóng điều chỉnh
                $len1 = abs($p[1]['price'] - $p[0]['price']);
                $len3 = abs($p[3]['price'] - $p[2]['price']);
                $len5 = abs($p[5]['price'] - $p[4]['price']);

                // Elliott Rule: Sóng 3 không được là sóng ngắn nhất trong 1, 3, 5
                $wave3NotShortest = ($len3 >= $len1 && $len3 >= $len5);

                // FIX Bug#3: No-overlap rule đúng — đáy Sóng 4 không được xâm phạm đỉnh Sóng 1
                $noOverlap = $bullish
                    ? ($p[4]['price'] > $p[1]['price'])
                    : ($p[4]['price'] < $p[1]['price']);

                if ($wave3NotShortest && $noOverlap) {
                    $labels = ['', '1', '2', '3', '4', '5', 'A', 'B', 'C'];
                    foreach ($p as $idx => $pivot) {
                        if ($labels[$idx] === '') continue;
                        $waves[] = ['label' => $labels[$idx], 'price' => $pivot['price'], 'time' => $pivot['time'], 'type' => $pivot['type']];
                    }
                    return $waves;
                }
            }
        }

        // Fallback: chỉ gán A-B-C từ 3 pivot gần nhất
        if (count($pivots) >= 3) {
            $p      = array_slice($pivots, -3);
            $labels = ['A', 'B', 'C'];
            foreach ($p as $idx => $pivot) {
                $waves[] = ['label' => $labels[$idx], 'price' => $pivot['price'], 'time' => $pivot['time'], 'type' => $pivot['type']];
            }
        }

        return $waves;
    }

    private function generateElliotSignal(array $waves, float $currentPrice, float $atr, float $adx = 0, array $structure = [], array $htfStructure = []): ?array
    {
        if (count($waves) < 3) return null;

        // FIX Bug#7: Lọc thị trường đi ngang — Elliott Wave không có ý nghĩa khi ADX thấp
        if ($adx > 0 && $adx < 20) return null;

        $lastWave  = end($waves);
        $firstWave = $waves[0];

        // FIX Bug#4: isBullishImpulse xác định từ label '1' — sóng 1 tăng thì kết thúc tại đỉnh
        $isBullishImpulse = ($firstWave['label'] === '1' && $firstWave['type'] === 'high');

        // --- SÓNG ĐẨY 3 ---
        $w1 = null; $w2 = null;
        foreach ($waves as $w) {
            if ($w['label'] === '1') $w1 = $w;
            if ($w['label'] === '2') $w2 = $w;
        }

        if ($w1 && $w2) {
            // FIX Bug#5: Kiểm tra proximity — chỉ vào lệnh khi giá vừa phá vỡ, không chase xa
            $breakDist = abs($currentPrice - $w1['price']);

            if ($isBullishImpulse && $currentPrice > $w1['price'] && $breakDist <= $atr * 1.5) {
                $htfBull     = ($htfStructure['trend'] ?? '') === 'TĂNG GIÁ';
                $sl          = $w2['price'] - ($atr * 0.3);
                $tp          = $currentPrice + ($currentPrice - $sl) * 2.5;

                // FIX Bug#6: Winrate động — tính theo điều kiện thực tế
                $winrate = 58;
                if ($adx >= 30) $winrate += 14;
                elseif ($adx >= 25) $winrate += 9;
                elseif ($adx >= 20) $winrate += 4;
                if ($htfBull) $winrate += 12;
                if ($structure['bos'] ?? false) $winrate += 8;

                return [
                    'type'             => 'MUA (SÓNG 3)',
                    'entry'            => round($currentPrice, 2),
                    'tp'               => round($tp, 2),
                    'sl'               => round($sl, 2),
                    'winrate'          => min(85, $winrate),
                    'reason'           => "ELLIOT: Phá vỡ đỉnh Sóng 1 (ADX={$adx}). Bắt đầu Sóng 3 tăng. " . ($htfBull ? 'HTF xác nhận tăng.' : 'Cảnh báo: HTF chưa đồng thuận.'),
                    'is_counter_trend' => !$htfBull,
                ];
            }

            if (!$isBullishImpulse && $currentPrice < $w1['price'] && $breakDist <= $atr * 1.5) {
                $htfBear     = ($htfStructure['trend'] ?? '') === 'GIẢM GIÁ';
                $sl          = $w2['price'] + ($atr * 0.3);
                $tp          = $currentPrice - ($sl - $currentPrice) * 2.5;

                $winrate = 58;
                if ($adx >= 30) $winrate += 14;
                elseif ($adx >= 25) $winrate += 9;
                elseif ($adx >= 20) $winrate += 4;
                if ($htfBear) $winrate += 12;
                if ($structure['bos'] ?? false) $winrate += 8;

                return [
                    'type'             => 'BÁN (SÓNG 3)',
                    'entry'            => round($currentPrice, 2),
                    'tp'               => round($tp, 2),
                    'sl'               => round($sl, 2),
                    'winrate'          => min(85, $winrate),
                    'reason'           => "ELLIOT: Phá vỡ đáy Sóng 1 (ADX={$adx}). Bắt đầu Sóng 3 giảm. " . ($htfBear ? 'HTF xác nhận giảm.' : 'Cảnh báo: HTF chưa đồng thuận.'),
                    'is_counter_trend' => !$htfBear,
                ];
            }
        }

        // --- SAU SÓNG C (kết thúc điều chỉnh) ---
        if ($lastWave['label'] === 'C') {
            $distFromC = abs($currentPrice - $lastWave['price']);
            if ($distFromC > $atr * 2) return null; // Đã rời quá xa sóng C, không còn setup tốt

            if ($lastWave['type'] === 'low') { // Sóng C kết thúc tại đáy → MUA
                $htfBull = ($htfStructure['trend'] ?? '') !== 'GIẢM GIÁ';
                $sl      = $lastWave['price'] - ($atr * 0.3);
                $tp      = $currentPrice + ($currentPrice - $sl) * 2.5;

                $winrate = 52;
                if ($adx >= 20) $winrate += 8;
                if ($htfBull) $winrate += 12;
                if ($structure['choch'] ?? false) $winrate += 10;

                return [
                    'type'             => 'MUA (HỒI SAU C)',
                    'entry'            => round($currentPrice, 2),
                    'tp'               => round($tp, 2),
                    'sl'               => round($sl, 2),
                    'winrate'          => min(80, $winrate),
                    'reason'           => "ELLIOT: Kết thúc sóng C giảm (ADX={$adx}). Kỳ vọng chu kỳ tăng mới.",
                    'is_counter_trend' => !$htfBull,
                ];
            }

            if ($lastWave['type'] === 'high') { // Sóng C kết thúc tại đỉnh → BÁN
                $htfBear = ($htfStructure['trend'] ?? '') !== 'TĂNG GIÁ';
                $sl      = $lastWave['price'] + ($atr * 0.3);
                $tp      = $currentPrice - ($sl - $currentPrice) * 2.5;

                $winrate = 52;
                if ($adx >= 20) $winrate += 8;
                if ($htfBear) $winrate += 12;
                if ($structure['choch'] ?? false) $winrate += 10;

                return [
                    'type'             => 'BÁN (HỒI SAU C)',
                    'entry'            => round($currentPrice, 2),
                    'tp'               => round($tp, 2),
                    'sl'               => round($sl, 2),
                    'winrate'          => min(80, $winrate),
                    'reason'           => "ELLIOT: Kết thúc sóng C tăng (ADX={$adx}). Kỳ vọng tiếp tục xu hướng giảm.",
                    'is_counter_trend' => !$htfBear,
                ];
            }
        }

        return null;
    }

    private function enrichWithAIScore(array $signal, array $recentCandles, $structure, $htfStructure, $method, string $symbol = '', string $timeframe = '', array $indicators = [])
    {
        $apiKey = env('OPENROUTER_API_KEY');
        if (!$apiKey) return $signal;

        // Cache key đủ cụ thể: symbol + timeframe + type + entry + tp + sl + method + 10-minute bucket
        // 10-minute bucket đảm bảo phân tích mới khi thị trường thay đổi, không bị stale 1 giờ
        $timeBucket = floor(time() / 600);
        $signalKey = md5(
            $symbol . $timeframe . $signal['type'] .
            round($signal['entry'], 2) . round($signal['tp'], 2) . round($signal['sl'], 2) .
            $method . $timeBucket
        );
        $cacheKey = "ai_v2_{$signalKey}";

        return \Illuminate\Support\Facades\Cache::remember($cacheKey, 600, function() use ($apiKey, $signal, $recentCandles, $structure, $htfStructure, $method, $symbol, $timeframe, $indicators) {
            try {
                $client = new \GuzzleHttp\Client();

                // Chỉ gửi 20 giá đóng cửa gần nhất — đủ để AI đánh giá momentum, tiết kiệm token
                $closes = array_map(fn($c) => round($c['close'], 4), array_slice($recentCandles, -20));
                $closesStr = implode(', ', $closes);

                $methodName = ($method === 'elliot') ? 'Elliott Wave' : 'Smart Money Concepts (SMC)';

                $slPct  = $signal['entry'] > 0 ? round(abs($signal['entry'] - $signal['sl']) / $signal['entry'] * 100, 2) : 0;
                $tpPct  = $signal['entry'] > 0 ? round(abs($signal['tp'] - $signal['entry']) / $signal['entry'] * 100, 2) : 0;
                $rr     = $slPct > 0 ? round($tpPct / $slPct, 2) : 0;
                $adx    = round($indicators['adx'] ?? 0, 1);
                $atr    = round($indicators['atr'] ?? 0, 4);
                $ema200 = round($indicators['ema200'] ?? 0, 4);
                $aboveEma = $signal['entry'] > $ema200 ? 'trên EMA200 (bullish bias)' : 'dưới EMA200 (bearish bias)';

                $prompt = <<<PROMPT
Cặp: {$symbol} | Khung: {$timeframe} | Phương pháp: {$methodName}

LỆNH CẦN ĐÁNH GIÁ:
- Hướng: {$signal['type']}
- Entry: {$signal['entry']} | TP: {$signal['tp']} (+{$tpPct}%) | SL: {$signal['sl']} (-{$slPct}%)
- R:R = 1:{$rr}

CHỈ BÁO KỸ THUẬT:
- ADX: {$adx} (>25 = xu hướng mạnh, <20 = ranging)
- ATR(14): {$atr} (độ biến động)
- Giá {$aboveEma}
- Xu hướng LTF: {$structure['trend']}
- Xu hướng HTF: {$htfStructure['trend']}
- Lý do hệ thống: {$signal['reason']}

20 GIÁ ĐÓNG CỬA GẦN NHẤT: {$closesStr}

Đánh giá tín hiệu này. Chỉ trả về JSON, không giải thích thêm:
{"score":0-100,"analysis":"nhận xét cụ thể về momentum và vùng giá của {$symbol}","risk_warning":"rủi ro thực tế cần chú ý","recommendation":"quyết định: VÀO LỆNH / CHỜ RETEST / BỎ QUA, lý do ngắn"}
PROMPT;

                $response = $client->post('https://openrouter.ai/api/v1/chat/completions', [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $apiKey,
                        'Content-Type'  => 'application/json',
                        'HTTP-Referer'  => 'http://localhost',
                    ],
                    'json' => [
                        'model'           => 'openai/gpt-4o',
                        'temperature'     => 0.3,
                        'messages'        => [
                            ['role' => 'system', 'content' => 'Bạn là trader chuyên nghiệp phân tích crypto futures. Chỉ trả về JSON hợp lệ, không markdown, không giải thích.'],
                            ['role' => 'user', 'content' => $prompt],
                        ],
                        'response_format' => ['type' => 'json_object'],
                    ],
                ]);

                $result  = json_decode($response->getBody(), true);
                $content = $result['choices'][0]['message']['content'] ?? '{}';
                $aiData  = json_decode($content, true);

                if ($aiData) {
                    $signal['ai_score']          = is_numeric($aiData['score'] ?? null) ? (int) $aiData['score'] : 50;
                    $signal['ai_analysis']        = is_array($aiData['analysis'] ?? null) ? implode(' ', $aiData['analysis']) : ($aiData['analysis'] ?? '');
                    $signal['ai_risk']            = is_array($aiData['risk_warning'] ?? null) ? implode(' ', $aiData['risk_warning']) : ($aiData['risk_warning'] ?? '');
                    $signal['ai_recommendation']  = is_array($aiData['recommendation'] ?? null) ? implode(' ', $aiData['recommendation']) : ($aiData['recommendation'] ?? '');
                }
            } catch (\Exception $e) {
                $signal['ai_error'] = 'OpenRouter Error: ' . $e->getMessage();
            }

            return $signal;
        });
    }
}
