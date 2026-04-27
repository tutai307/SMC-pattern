<?php

namespace App\Services;

class PriceActionService
{
    /**
     * Analyze market data with SMC (Smart Money Concepts).
     */
    public function analyze(array $klines, array $klinesHTF = [], string $method = 'smc')
    {
        $default = [
            'method' => $method,
            'structure' => ['trend' => 'không rõ', 'last_price' => 0, 'bos' => false, 'choch' => false],
            'orderBlocks' => [],
            'fvgs' => [],
            'waves' => [],
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
            $signal = $this->generateElliotSignal($waves, end($candles)['close'], end($atr));
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
            $signal = $this->enrichWithAIScore($signal, array_slice($candles, -60), $structure, $htfStructure, $method);
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

        foreach ($zones as $zone) {
            $buffer = $lastAtr * 0.5; // Tăng biên độ để dễ bắt được vùng giá hơn

            // --- SMC LONG SETUP ---
            if ($zone['type'] == 'demand') {
                if ($lastPrice >= $zone['bottom'] - $buffer && $lastPrice <= $zone['top'] + $buffer) {
                    
                    $isCounterTrend = ($htfStructure['trend'] == 'GIẢM GIÁ');
                    $confluence = 0;
                    if ($structure['choch'] || $structure['bos']) $confluence += 20;
                    if ($htfStructure['trend'] == 'TĂNG GIÁ') $confluence += 30;
                    
                    // Check if price is in a bullish FVG
                    $inFvg = false;
                    foreach(array_slice($fvgs, -5) as $f) {
                        if ($f['type'] == 'BULLISH' && $lastPrice >= $f['bottom'] && $lastPrice <= $f['top']) {
                            $inFvg = true; break;
                        }
                    }
                    if ($inFvg) $confluence += 15;

                    $confidence = 50 + $confluence;
                    if ($isCounterTrend) $confidence -= 20;

                    if ($confidence < 40) continue; // Nới lỏng để AI (Claude) tự lọc lại

                    $entry = ($zone['top'] + $zone['bottom']) / 2; // Midpoint of Demand for better price
                    
                    // Đảm bảo entry phải thấp hơn giá hiện tại cho lệnh MUA
                    if ($entry >= $lastPrice) {
                        $entry = $zone['bottom'] + ($lastAtr * 0.1); 
                    }

                    $sl = $zone['bottom'] - ($lastAtr * 0.2);
                    $tp = $entry + ($entry - $sl) * 3.0;

                    return [
                        'type' => 'MUA',
                        'entry' => round($entry, 2),
                        'tp' => round($tp, 2), 
                        'sl' => round($sl, 2), 
                        'winrate' => min(95, $confidence),
                        'reason' => "SMC: Đặt lệnh chờ tại 50% vùng Demand (Mean Threshold) để tối ưu điểm vào và R:R.",
                        'is_counter_trend' => $isCounterTrend
                    ];
                }
            }

            // --- SMC SHORT SETUP ---
            if ($zone['type'] == 'supply') {
                if ($lastPrice <= $zone['top'] + $buffer && $lastPrice >= $zone['bottom'] - $buffer) {
                    
                    $isCounterTrend = ($htfStructure['trend'] == 'TĂNG GIÁ');
                    $confluence = 0;
                    if ($structure['choch'] || $structure['bos']) $confluence += 20;
                    if ($htfStructure['trend'] == 'GIẢM GIÁ') $confluence += 30;
                    
                    $inFvg = false;
                    foreach(array_slice($fvgs, -5) as $f) {
                        if ($f['type'] == 'BEARISH' && $lastPrice >= $f['bottom'] && $lastPrice <= $f['top']) {
                            $inFvg = true; break;
                        }
                    }
                    if ($inFvg) $confluence += 15;

                    $confidence = 50 + $confluence;
                    if ($isCounterTrend) $confidence -= 20;

                    if ($confidence < 60) continue;

                    $entry = ($zone['top'] + $zone['bottom']) / 2; // Midpoint of Supply
                    
                    // Đảm bảo entry phải cao hơn giá hiện tại cho lệnh BÁN
                    if ($entry <= $lastPrice) {
                        $entry = $zone['top'] - ($lastAtr * 0.1);
                    }

                    $sl = $zone['top'] + ($lastAtr * 0.2);
                    $tp = $entry - ($sl - $entry) * 3.0;

                    return [
                        'type' => 'BÁN',
                        'entry' => round($entry, 2),
                        'tp' => round($tp, 2), 
                        'sl' => round($sl, 2), 
                        'winrate' => min(95, $confidence),
                        'reason' => "SMC: Đặt lệnh chờ tại 50% vùng Supply (Mean Threshold) để đón đầu nhịp đảo chiều với giá tốt nhất.",
                        'is_counter_trend' => $isCounterTrend
                    ];
                }
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
    private function detectElliotWaves(array $candles)
    {
        $count = count($candles);
        if ($count < 50) return [];

        $pivots = [];
        $window = 10; // Cửa sổ lớn hơn để tìm đỉnh/đáy thực sự có ý nghĩa
        for ($i = $window; $i < $count - $window; $i++) {
            $isHigh = true; $isLow = true;
            for ($j = $i - $window; $j <= $i + $window; $j++) {
                if ($candles[$j]['high'] > $candles[$i]['high']) $isHigh = false;
                if ($candles[$j]['low'] < $candles[$i]['low']) $isLow = false;
            }
            if ($isHigh) $pivots[] = ['type' => 'high', 'price' => $candles[$i]['high'], 'time' => $candles[$i]['time']];
            if ($isLow) $pivots[] = ['type' => 'low', 'price' => $candles[$i]['low'], 'time' => $candles[$i]['time']];
        }

        if (count($pivots) < 8) {
            // Nếu quá ít pivot, chỉ đánh nhãn A-B-C cho 3 cái gần nhất
            $p = array_slice($pivots, -3);
            $labels = ['A', 'B', 'C'];
            foreach ($p as $idx => $pivot) {
                $waves[] = ['label' => $labels[$idx], 'price' => $pivot['price'], 'time' => $pivot['time'], 'type' => $pivot['type']];
            }
            return $waves;
        }

        // Lấy 9 pivot gần nhất để có 1 điểm bắt đầu + 8 điểm sóng (1-5, A-B-C)
        if (count($pivots) < 9) {
            $p = array_slice($pivots, -4); // 1 start + 3 sóng A-B-C
            $labels = ['', 'A', 'B', 'C'];
            foreach ($p as $idx => $pivot) {
                if ($labels[$idx] == '') continue;
                $waves[] = ['label' => $labels[$idx], 'price' => $pivot['price'], 'time' => $pivot['time'], 'type' => $pivot['type']];
            }
            return $waves;
        }

        $p = array_slice($pivots, -9);
        $w1 = abs($p[2]['price'] - $p[1]['price']);
        $w3 = abs($p[4]['price'] - $p[3]['price']);
        $w5 = abs($p[6]['price'] - $p[5]['price']);
        
        $isValidImpulse = ($w3 > $w1 || $w3 > $w5);
        $noOverlap = ($p[0]['type'] == 'low') ? ($p[5]['price'] > $p[2]['price']) : ($p[5]['price'] < $p[2]['price']);

        if ($isValidImpulse && $noOverlap) {
            $labels = ['', '1', '2', '3', '4', '5', 'A', 'B', 'C'];
            foreach ($p as $idx => $pivot) {
                if ($labels[$idx] == '') continue;
                $waves[] = ['label' => $labels[$idx], 'price' => $pivot['price'], 'time' => $pivot['time'], 'type' => $pivot['type']];
            }
        } else {
            $p = array_slice($pivots, -4);
            $labels = ['', 'A', 'B', 'C'];
            foreach ($p as $idx => $pivot) {
                if ($labels[$idx] == '') continue;
                $waves[] = ['label' => $labels[$idx], 'price' => $pivot['price'], 'time' => $pivot['time'], 'type' => $pivot['type']];
            }
        }

        return $waves;
    }

    private function generateElliotSignal(array $waves, float $currentPrice, float $atr)
    {
        if (count($waves) < 3) return null;

        $lastWave = end($waves);
        $firstWave = $waves[0];
        $isBullishImpulse = ($firstWave['type'] == 'high'); // Nếu điểm kết thúc sóng 1 là Đỉnh -> Chu kỳ tăng

        // --- SÓNG ĐẨY 3 (Cơ hội lớn nhất) ---
        if (count($waves) >= 3) {
            $w1 = null; $w2 = null;
            foreach($waves as $w) {
                if ($w['label'] == '1') $w1 = $w;
                if ($w['label'] == '2') $w2 = $w;
            }

            if ($w1 && $w2) {
                if ($isBullishImpulse && $currentPrice > $w1['price']) {
                    $sl = $w2['price'] - ($atr * 0.2);
                    $tp = $currentPrice + ($currentPrice - $sl) * 3.0;
                    return [
                        'type' => 'MUA (SÓNG 3)',
                        'entry' => round($currentPrice, 2),
                        'tp' => round($tp, 2),
                        'sl' => round($sl, 2),
                        'winrate' => 88,
                        'reason' => "ELLIOT: Phá vỡ đỉnh Sóng 1. Xác nhận Sóng 3 tăng trưởng mạnh."
                    ];
                } elseif (!$isBullishImpulse && $currentPrice < $w1['price']) {
                    $sl = $w2['price'] + ($atr * 0.2);
                    $tp = $currentPrice - ($sl - $currentPrice) * 3.0;
                    return [
                        'type' => 'BÁN (SÓNG 3)',
                        'entry' => round($currentPrice, 2),
                        'tp' => round($tp, 2),
                        'sl' => round($sl, 2),
                        'winrate' => 88,
                        'reason' => "ELLIOT: Phá vỡ đáy Sóng 1. Xác nhận Sóng 3 giảm giá mạnh."
                    ];
                }
            }
        }

        // --- CHU KỲ SAU SÓNG C ---
        if ($lastWave['label'] == 'C') {
            if ($lastWave['type'] == 'low') { // Sau sóng C giảm là MUA
                $distFromC = $currentPrice - $lastWave['price'];
                if ($distFromC > 0 && $distFromC < $atr * 2) {
                    $sl = $lastWave['price'] - ($atr * 0.2);
                    $tp = $currentPrice + ($currentPrice - $sl) * 3.5;
                    return [
                        'type' => 'MUA (HỒI SAU C)',
                        'entry' => round($currentPrice, 2),
                        'tp' => round($tp, 2),
                        'sl' => round($sl, 2),
                        'winrate' => 80,
                        'reason' => "ELLIOT: Kết thúc sóng điều chỉnh C. Kỳ vọng bắt đầu chu kỳ tăng mới."
                    ];
                }
            } else { // Sau sóng C tăng là BÁN
                $distFromC = $lastWave['price'] - $currentPrice;
                if ($distFromC > 0 && $distFromC < $atr * 2) {
                    $sl = $lastWave['price'] + ($atr * 0.2);
                    $tp = $currentPrice - ($sl - $currentPrice) * 3.5;
                    return [
                        'type' => 'BÁN (HỒI SAU C)',
                        'entry' => round($currentPrice, 2),
                        'tp' => round($tp, 2),
                        'sl' => round($sl, 2),
                        'winrate' => 80,
                        'reason' => "ELLIOT: Kết thúc sóng hồi C. Kỳ vọng tiếp diễn xu hướng giảm."
                    ];
                }
            }
        }
        
        return null;
    }

    private function enrichWithAIScore(array $signal, array $recentCandles, $structure, $htfStructure, $method)
    {
        $apiKey = env('OPENROUTER_API_KEY');
        if (!$apiKey) return $signal;

        // Tạo cache key dựa trên các yếu tố cốt lõi của tín hiệu
        $signalKey = md5($signal['type'] . round($signal['entry'], 4) . $method . ($structure['trend'] ?? ''));
        $cacheKey = "ai_analysis_{$signalKey}";

        return \Illuminate\Support\Facades\Cache::remember($cacheKey, 3600, function() use ($apiKey, $signal, $recentCandles, $structure, $htfStructure, $method) {
            try {
                $client = new \GuzzleHttp\Client();
                $dataString = "";
                foreach ($recentCandles as $c) {
                    $dataString .= "O:{$c['open']} H:{$c['high']} L:{$c['low']} C:{$c['close']} V:{$c['volume']}\n";
                }

                $methodName = ($method === 'elliot') ? "Sóng Elliot" : "Smart Money Concepts (SMC)";
                
                $prompt = "Bạn là một Quản lý Quỹ Đầu tư (Hedge Fund Manager) chuyên nghiệp với 20 năm kinh nghiệm trong phương pháp {$methodName}.\n" .
                          "DỮ LIỆU THỊ TRƯỜNG (60 nến gần nhất):\n" . $dataString . 
                          "\nTÍN HIỆU KỸ THUẬT CẦN PHÊ DUYỆT:\n" .
                          "- Loại lệnh: {$signal['type']}\n" .
                          "- Điểm vào: {$signal['entry']}\n" .
                          "- Chốt lời (TP): {$signal['tp']}\n" .
                          "- Cắt lỗ (SL): {$signal['sl']}\n" .
                          "- Cấu trúc LTF: {$structure['trend']}\n" .
                          "- Xu hướng HTF: {$htfStructure['trend']}\n" .
                          "- Lý do hệ thống: {$signal['reason']}\n" .
                          "\nNHIỆM VỤ CỦA BẠN:\n" .
                          "1. Phân tích nến (Candlestick Patterns): Tìm các dấu hiệu Rejection, Exhaustion hoặc Momentum.\n" .
                          "2. Đánh giá vùng giá (Zone Validation): Điểm vào lệnh có nằm trong vùng thanh khoản (Liquidity) tốt không?\n" .
                          "3. Quản trị rủi ro: Tỉ lệ R:R này có thực sự khả thi trong bối cảnh hiện tại?\n" .
                          "4. Đưa ra điểm số từ 0-100 (Chỉ vào lệnh nếu score > 80).\n" .
                          "\nYÊU CẦU TRẢ VỀ JSON CHI TIẾT:\n" .
                          "{\n" .
                          "  \"score\": 85,\n" .
                          "  \"analysis\": \"Phân tích chi tiết về nến và xu hướng...\",\n" .
                          "  \"risk_warning\": \"Cảnh báo rủi ro cụ thể...\",\n" .
                          "  \"recommendation\": \"Nên vào lệnh hay đợi retest thêm?\"\n" .
                          "}";

                $response = $client->post("https://openrouter.ai/api/v1/chat/completions", [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $apiKey,
                        'Content-Type' => 'application/json',
                        'HTTP-Referer' => 'http://localhost',
                    ],
                    'json' => [
                        'model' => 'openai/gpt-4o',
                        'messages' => [
                            ['role' => 'system', 'content' => 'Bạn là một chuyên gia phân tích tài chính chỉ trả về JSON.'],
                            ['role' => 'user', 'content' => $prompt]
                        ],
                        'response_format' => ['type' => 'json_object']
                    ]
                ]);

                $result = json_decode($response->getBody(), true);
                $content = $result['choices'][0]['message']['content'] ?? '{}';
                $aiData = json_decode($content, true);
                
                if ($aiData) {
                    $signal['ai_score'] = is_array($aiData['score'] ?? null) ? ($aiData['score'][0] ?? 50) : ($aiData['score'] ?? 50);
                    
                    $aiAnalysis = $aiData['analysis'] ?? 'Không có phân tích.';
                    $signal['ai_analysis'] = is_array($aiAnalysis) ? implode(' ', $aiAnalysis) : $aiAnalysis;
                    
                    $aiRisk = $aiData['risk_warning'] ?? 'Không có cảnh báo.';
                    $signal['ai_risk'] = is_array($aiRisk) ? implode(' ', $aiRisk) : $aiRisk;
                    
                    $aiRec = $aiData['recommendation'] ?? 'Cân nhắc kỹ.';
                    $signal['ai_recommendation'] = is_array($aiRec) ? implode(' ', $aiRec) : $aiRec;
                }
            } catch (\Exception $e) {
                $signal['ai_error'] = "OpenRouter Error: " . $e->getMessage();
            }

            return $signal;
        });
    }
}
