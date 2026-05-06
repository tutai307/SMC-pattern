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
            'indicators' => ['adx' => 0, 'atr' => 0, 'ema200' => 0]
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
            $signal = $this->enrichWithAIScore(
                $signal,
                array_slice($candles, -50),
                $structure,
                $htfStructure,
                $method,
                $symbol,
                $timeframe,
                $indicators,
                $orderBlocks ?? [],
                $fvgs ?? [],
                $volumeProfile['poc'] ?? 0
            );
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

    // ─────────────────────────────────────────────────────────────────────────
    // SNIPER MODULE 1: Swing point detection
    // ─────────────────────────────────────────────────────────────────────────
    private function detectSwingPoints(array $candles, int $wing = 5): array
    {
        $highs = [];
        $lows  = [];
        $n = count($candles);

        for ($i = $wing; $i < $n - $wing; $i++) {
            $h = $candles[$i]['high'];
            $l = $candles[$i]['low'];
            $isHigh = true;
            $isLow  = true;

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

    // ─────────────────────────────────────────────────────────────────────────
    // SNIPER MODULE 1: Liquidity sweep — wick beyond swing, close back inside
    // ─────────────────────────────────────────────────────────────────────────
    private function detectLiquiditySweep(array $candles, int $swingIdx, string $direction, int $maxCandles = 8): ?array
    {
        $level = $direction === 'high' ? $candles[$swingIdx]['high'] : $candles[$swingIdx]['low'];
        $limit = min($swingIdx + $maxCandles + 1, count($candles));

        for ($i = $swingIdx + 1; $i < $limit; $i++) {
            $c = $candles[$i];
            if ($direction === 'high' && $c['high'] > $level && $c['close'] < $level) {
                return ['idx' => $i, 'level' => $level];
            }
            if ($direction === 'low' && $c['low'] < $level && $c['close'] > $level) {
                return ['idx' => $i, 'level' => $level];
            }
        }
        return null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SNIPER MODULE 2: Change of Character (CHoCH) on any timeframe
    //   Bullish CHoCH: lower-low structure on LTF, then close above swing high
    //   Bearish CHoCH: higher-high structure on LTF, then close below swing low
    // ─────────────────────────────────────────────────────────────────────────
    private function detectCHoCH(array $candles, string $direction, int $lookback = 60, int $wing = 3): ?array
    {
        $recent = array_slice($candles, -$lookback);
        $swings = $this->detectSwingPoints($recent, $wing);
        $highs  = $swings['highs'];
        $lows   = $swings['lows'];

        if (count($highs) < 2 || count($lows) < 2) return null;

        $lastClose = end($recent)['close'];
        $lastTime  = end($recent)['time'];

        if ($direction === 'BULLISH') {
            $ll = end($lows);
            $pl = $lows[count($lows) - 2];
            if ($ll['price'] >= $pl['price']) return null; // need lower low
            $lh = end($highs);
            if ($lastClose > $lh['price']) {
                return [
                    'confirmed'    => true,
                    'type'         => 'BULLISH_CHOCH',
                    'choch_level'  => $lh['price'],
                    'swept_low'    => $ll['price'],
                    'time'         => $lastTime,
                ];
            }
        }

        if ($direction === 'BEARISH') {
            $hh = end($highs);
            $ph = $highs[count($highs) - 2];
            if ($hh['price'] <= $ph['price']) return null; // need higher high
            $hl = end($lows);
            if ($lastClose < $hl['price']) {
                return [
                    'confirmed'    => true,
                    'type'         => 'BEARISH_CHOCH',
                    'choch_level'  => $hl['price'],
                    'swept_high'   => $hh['price'],
                    'time'         => $lastTime,
                ];
            }
        }

        return null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SNIPER MODULE 3: Position sizing — loss capped at exactly risk_usd
    // ─────────────────────────────────────────────────────────────────────────
    public static function calculatePositionSize(
        float $balance,
        float $riskPercent,
        float $entry,
        float $stopLoss,
        float $rrRatio    = 3.0,
        int   $maxLeverage = 20
    ): array {
        if ($entry <= 0 || $stopLoss <= 0 || $entry === $stopLoss) return [];

        $riskUsd    = round($balance * $riskPercent / 100, 4);
        $slDistance = abs($entry - $stopLoss);
        $slPct      = $slDistance / $entry;

        // notional so that (notional × sl_pct) = risk_usd exactly
        $notional    = $riskUsd / $slPct;
        $rawLeverage = $notional / $balance;
        $leverage    = max(1, min((int) ceil($rawLeverage), $maxLeverage));

        // Cap notional if leverage hit the ceiling
        if ($rawLeverage > $maxLeverage) {
            $notional = $balance * $maxLeverage;
        }

        $margin  = round($notional / $leverage, 4);
        $volume  = round($notional / $entry, 6);
        $isLong  = $entry > $stopLoss;
        $tp      = round($isLong ? $entry + $slDistance * $rrRatio : $entry - $slDistance * $rrRatio, 6);

        return [
            'risk_usd'        => $riskUsd,
            'notional_usd'    => round($notional, 4),
            'volume'          => $volume,
            'leverage'        => $leverage,
            'margin_usd'      => $margin,
            'sl_pct'          => round($slPct * 100, 4),
            'tp_price'        => $tp,
            'actual_risk_usd' => round($volume * $slDistance, 4),
        ];
    }

    /**
     * Find Order Blocks with displacement, FVG, and Liquidity Sweep validation.
     * OBs with a preceding sweep are marked strength='SNIPER' — highest priority.
     */
    private function findHighQualityOB(array $candles, array $fvgs)
    {
        $obs        = [];
        $count      = count($candles);
        $fvgIndices = array_column($fvgs, 'index');
        $swings     = $this->detectSwingPoints($candles, 5);

        for ($i = 5; $i < $count - 3; $i++) {
            $current           = $candles[$i];
            $displacementCandle = $candles[$i + 1];

            $bodySize = abs($displacementCandle['close'] - $displacementCandle['open']);
            $avgBody  = 0;
            for ($j = $i - 5; $j < $i; $j++) $avgBody += abs($candles[$j]['close'] - $candles[$j]['open']);
            $avgBody /= 5;

            if ($bodySize <= $avgBody * 1.8) continue;

            $hasFvgNearby = in_array($i + 1, $fvgIndices) || in_array($i + 2, $fvgIndices);
            if (!$hasFvgNearby) continue;

            $type = ($displacementCandle['close'] > $displacementCandle['open']) ? 'demand' : 'supply';

            // ── Liquidity sweep check ────────────────────────────────────────
            $liquiditySwept = false;
            $sweptLevel     = null;
            $swingList      = ($type === 'demand') ? $swings['lows'] : $swings['highs'];
            $sweepDir       = ($type === 'demand') ? 'low' : 'high';

            foreach ($swingList as $swing) {
                if ($swing['idx'] < $i - 20 || $swing['idx'] >= $i) continue;
                $sweep = $this->detectLiquiditySweep($candles, $swing['idx'], $sweepDir);
                if ($sweep && $sweep['idx'] <= $i) {
                    $liquiditySwept = true;
                    $sweptLevel     = $swing['price'];
                    break;
                }
            }

            $obs[] = [
                'type'            => $type,
                'top'             => $current['high'],
                'bottom'          => $current['low'],
                'price'           => $current['close'],
                'label'           => $liquiditySwept
                    ? ($type === 'demand' ? 'SNIPER DEMAND' : 'SNIPER SUPPLY')
                    : ($type === 'demand' ? 'SMC DEMAND'    : 'SMC SUPPLY'),
                'strength'        => $liquiditySwept ? 'SNIPER' : 'HIGH',
                'liquidity_swept' => $liquiditySwept,
                'swept_level'     => $sweptLevel,
            ];
        }

        return array_slice($obs, -5);
    }

    private function generateSMCSignal($candles, $structure, $zones, $fvgs, $htfStructure, $htfZones, $poc, $adx, $atr)
    {
        $lastPrice = $structure['last_price'];
        $lastAdx   = end($adx);
        $lastAtr   = end($atr);

        if ($lastAdx < 22) return null;

        // Kiểm tra giá có đang trong vùng HTF POI không
        $inHtfPoi = false;
        foreach ($htfZones as $htfOb) {
            if ($lastPrice >= $htfOb['bottom'] && $lastPrice <= $htfOb['top']) {
                $inHtfPoi = true;
                break;
            }
        }

        $reversedZones = array_reverse($zones);

        foreach ($reversedZones as $zone) {
            $buffer    = $lastAtr * 0.5;
            $isSniper  = ($zone['strength'] ?? '') === 'SNIPER';
            $obHeight  = $zone['top'] - $zone['bottom'];

            // ─── LONG SETUP ───────────────────────────────────────────────
            if ($zone['type'] === 'demand') {
                $entry = ($zone['top'] + $zone['bottom']) / 2;

                if ($entry >= $lastPrice) continue;
                if ($lastPrice < $zone['bottom'] - $buffer) continue;
                if ($lastPrice > $zone['top'] + $buffer * 3) continue;

                // OB mitigated: giá đã xuyên sâu hơn 50% OB → OB yếu, bỏ qua
                if ($obHeight > 0 && $lastPrice < $zone['bottom'] + $obHeight * 0.5) continue;

                // SNIPER: bắt buộc xác nhận CHoCH trên LTF
                $choch = null;
                if ($isSniper) {
                    $choch = $this->detectCHoCH($candles, 'BULLISH', 60, 5);
                    if (!$choch) continue; // chưa có CHoCH → bỏ qua, không vào sớm
                    $entry = $choch['choch_level']; // entry tại điểm phá CHoCH
                }

                $isCounterTrend = ($htfStructure['trend'] === 'GIẢM GIÁ');
                $confluence = 0;
                if ($structure['choch'] || $structure['bos']) $confluence += 20;
                if ($htfStructure['trend'] === 'TĂNG GIÁ') $confluence += 30;
                if ($inHtfPoi) $confluence += 20;

                $inFvg = false;
                foreach (array_slice($fvgs, -5) as $f) {
                    if ($f['type'] === 'BULLISH' && $entry >= $f['bottom'] && $entry <= $f['top']) {
                        $inFvg = true; break;
                    }
                }
                if ($inFvg) $confluence += 15;

                $baseConfidence = $isSniper ? 65 : 50;
                $confidence     = $baseConfidence + $confluence;
                if ($isSniper && $choch)  $confidence += 15;
                if ($isCounterTrend) $confidence -= 20;

                if ($confidence < 40) continue;

                $sl = $zone['bottom'] - ($lastAtr * 0.8);
                // SL tối thiểu 1.5% dưới entry — tránh bị quét bởi noise
                $sl = min($sl, $entry * 0.985);
                $tp = $entry + ($entry - $sl) * 3.0;

                if ($isSniper && $choch) {
                    $pattern = 'OB + CHoCH' . ($inHtfPoi ? ' + HTF POI' : '');
                    $reason  = "🎯 SNIPER: Liquidity sweep @ " . round($zone['swept_level'] ?? 0, 4)
                             . " → MSS → CHoCH xác nhận @ " . round($choch['choch_level'], 4)
                             . ($inHtfPoi ? " | Giá trong vùng HTF POI." : "");
                    $type    = 'MUA (SNIPER)';
                } else {
                    $pattern = 'SMC DEMAND';
                    $reason  = "SMC: Buy Limit tại 50% vùng Demand. Chờ retest.";
                    $type    = 'MUA';
                }

                return [
                    'type'             => $type,
                    'entry'            => round($entry, 4),
                    'tp'               => round($tp, 4),
                    'sl'               => round($sl, 4),
                    'winrate'          => min(95, $confidence),
                    'reason'           => $reason,
                    'pattern'          => $pattern,
                    'sniper'           => $isSniper && $choch !== null,
                    'is_counter_trend' => $isCounterTrend,
                ];
            }

            // ─── SHORT SETUP ──────────────────────────────────────────────
            if ($zone['type'] === 'supply') {
                $entry = ($zone['top'] + $zone['bottom']) / 2;

                if ($entry <= $lastPrice) continue;
                if ($lastPrice > $zone['top'] + $buffer) continue;
                if ($lastPrice < $zone['bottom'] - $buffer * 3) continue;

                // OB mitigated: giá đã xuyên sâu hơn 50% OB → OB yếu, bỏ qua
                if ($obHeight > 0 && $lastPrice > $zone['top'] - $obHeight * 0.5) continue;

                // SNIPER: bắt buộc xác nhận CHoCH trên LTF
                $choch = null;
                if ($isSniper) {
                    $choch = $this->detectCHoCH($candles, 'BEARISH', 60, 5);
                    if (!$choch) continue;
                    $entry = $choch['choch_level'];
                }

                $isCounterTrend = ($htfStructure['trend'] === 'TĂNG GIÁ');
                $confluence = 0;
                if ($structure['choch'] || $structure['bos']) $confluence += 20;
                if ($htfStructure['trend'] === 'GIẢM GIÁ') $confluence += 30;
                if ($inHtfPoi) $confluence += 20;

                $inFvg = false;
                foreach (array_slice($fvgs, -5) as $f) {
                    if ($f['type'] === 'BEARISH' && $entry >= $f['bottom'] && $entry <= $f['top']) {
                        $inFvg = true; break;
                    }
                }
                if ($inFvg) $confluence += 15;

                $baseConfidence = $isSniper ? 65 : 50;
                $confidence     = $baseConfidence + $confluence;
                if ($isSniper && $choch)  $confidence += 15;
                if ($isCounterTrend) $confidence -= 20;

                if ($confidence < 60) continue;

                $sl = $zone['top'] + ($lastAtr * 0.8);
                // SL tối thiểu 1.5% trên entry — tránh bị quét bởi noise
                $sl = max($sl, $entry * 1.015);
                $tp = $entry - ($sl - $entry) * 3.0;

                if ($isSniper && $choch) {
                    $pattern = 'OB + CHoCH' . ($inHtfPoi ? ' + HTF POI' : '');
                    $reason  = "🎯 SNIPER: Liquidity sweep @ " . round($zone['swept_level'] ?? 0, 4)
                             . " → MSS → CHoCH xác nhận @ " . round($choch['choch_level'], 4)
                             . ($inHtfPoi ? " | Giá trong vùng HTF POI." : "");
                    $type    = 'BÁN (SNIPER)';
                } else {
                    $pattern = 'SMC SUPPLY';
                    $reason  = "SMC: Sell Limit tại 50% vùng Supply. Chờ retest.";
                    $type    = 'BÁN';
                }

                return [
                    'type'             => $type,
                    'entry'            => round($entry, 4),
                    'tp'               => round($tp, 4),
                    'sl'               => round($sl, 4),
                    'winrate'          => min(95, $confidence),
                    'reason'           => $reason,
                    'pattern'          => $pattern,
                    'sniper'           => $isSniper && $choch !== null,
                    'is_counter_trend' => $isCounterTrend,
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

    private function enrichWithAIScore(
        array $signal,
        array $recentCandles,
        $structure,
        $htfStructure,
        string $method,
        string $symbol = '',
        string $timeframe = '',
        array $indicators = [],
        array $orderBlocks = [],
        array $fvgs = [],
        float $poc = 0
    ) {
        $apiKey = env('OPENROUTER_API_KEY');
        if (!$apiKey) return $signal;

        $timeBucket = floor(time() / 600);
        $cacheKey = 'ai_v3_' . md5(
            $symbol . $timeframe . $signal['type'] .
            round($signal['entry'], 4) . round($signal['tp'], 4) . round($signal['sl'], 4) .
            $method . $timeBucket
        );

        return \Illuminate\Support\Facades\Cache::remember($cacheKey, 600, function () use (
            $apiKey, $signal, $recentCandles, $structure, $htfStructure,
            $method, $symbol, $timeframe, $indicators, $orderBlocks, $fvgs, $poc
        ) {
            try {
                $client = new \GuzzleHttp\Client(['timeout' => 12, 'connect_timeout' => 4]);

                $last50 = array_slice($recentCandles, -50);
                $currentPrice = end($last50)['close'] ?? $signal['entry'];

                // --- Tính các chỉ số từ candle data ---
                $slPct  = $signal['entry'] > 0 ? round(abs($signal['entry'] - $signal['sl'])  / $signal['entry'] * 100, 2) : 0;
                $tpPct  = $signal['entry'] > 0 ? round(abs($signal['tp']    - $signal['entry']) / $signal['entry'] * 100, 2) : 0;
                $rr     = $slPct > 0 ? round($tpPct / $slPct, 2) : 0;
                $adx    = round($indicators['adx']    ?? 0, 1);
                $atr    = round($indicators['atr']    ?? 0, 6);
                $ema200 = round($indicators['ema200'] ?? 0, 6);

                // Swing high/low trong 50 nến gần nhất
                $highs  = array_column($last50, 'high');
                $lows   = array_column($last50, 'low');
                $swingH = $highs ? round(max($highs), 6) : 0;
                $swingL = $lows  ? round(min($lows),  6) : 0;
                $distToSwingH = $swingH > 0 ? round(abs($currentPrice - $swingH) / $currentPrice * 100, 2) : 0;
                $distToSwingL = $swingL > 0 ? round(abs($currentPrice - $swingL) / $currentPrice * 100, 2) : 0;

                // Momentum: đếm 5 nến xanh/đỏ gần nhất
                $last5 = array_slice($last50, -5);
                $bullCount = count(array_filter($last5, fn($c) => $c['close'] > $c['open']));
                $bearCount = 5 - $bullCount;
                $momentumStr = "{$bullCount} xanh / {$bearCount} đỏ trong 5 nến cuối";

                // Kích thước body nến cuối so với ATR
                $lastC     = end($last5);
                $lastBody  = $atr > 0 ? round(abs($lastC['close'] - $lastC['open']) / $atr, 2) : 0;
                $bodyDesc  = $lastBody >= 1.5 ? 'to mạnh' : ($lastBody >= 0.7 ? 'bình thường' : 'nhỏ/hesitation');

                // OB gần nhất với entry
                $obLines = [];
                foreach (array_slice($orderBlocks, -3) as $ob) {
                    $dist = round(abs(($ob['price'] ?? 0) - $signal['entry']) / $signal['entry'] * 100, 2);
                    $obLines[] = strtoupper($ob['type'] ?? '?') . ' OB @' . ($ob['price'] ?? '?') . " ({$ob['strength']}, cách entry {$dist}%)";
                }
                $obStr = $obLines ? implode("\n  ", $obLines) : 'Không có OB nổi bật';

                // FVG gần nhất
                $fvgLines = [];
                foreach (array_slice($fvgs, -2) as $fvg) {
                    $fvgLines[] = strtoupper($fvg['type'] ?? '?') . ' FVG ' . ($fvg['low'] ?? '?') . '–' . ($fvg['high'] ?? '?');
                }
                $fvgStr = $fvgLines ? implode(', ', $fvgLines) : 'Không có FVG';

                // POC
                $pocStr = $poc > 0
                    ? "@{$poc} (cách giá hiện tại " . round(abs($currentPrice - $poc) / $currentPrice * 100, 2) . "%)"
                    : 'N/A';

                // EMA200
                $emaPos = $ema200 > 0
                    ? ($currentPrice > $ema200
                        ? 'TRÊN EMA200 @' . $ema200 . ' (+' . round(($currentPrice - $ema200) / $ema200 * 100, 2) . '%)'
                        : 'DƯỚI EMA200 @' . $ema200 . ' (-' . round(($ema200 - $currentPrice) / $ema200 * 100, 2) . '%)')
                    : 'N/A';

                // Pattern / Sniper flag
                $isSniper  = str_contains($signal['reason'] ?? '', 'SNIPER');
                $patternStr = $signal['pattern'] ?? ($isSniper ? 'SNIPER ENTRY' : 'STANDARD');

                $methodName = $method === 'elliot' ? 'Elliott Wave' : 'SMC';

                $prompt = <<<PROMPT
SYMBOL: {$symbol} | TF: {$timeframe} | METHOD: {$methodName}
SIGNAL TYPE: {$signal['type']} | PATTERN: {$patternStr}

=== ENTRY SETUP ===
Entry : {$signal['entry']}
TP    : {$signal['tp']} (+{$tpPct}%)
SL    : {$signal['sl']} (-{$slPct}%)
R:R   : 1:{$rr}
Lý do hệ thống: {$signal['reason']}

=== GIÁ HIỆN TẠI & VỊ TRÍ ===
Giá hiện tại : {$currentPrice}
Swing High 50 nến: {$swingH} (cách {$distToSwingH}%)
Swing Low  50 nến: {$swingL} (cách {$distToSwingL}%)
{$emaPos}
POC Volume Profile: {$pocStr}

=== MOMENTUM ===
ADX : {$adx} ({$this->adxDesc($adx)})
ATR : {$atr}
Momentum 5 nến: {$momentumStr}
Nến cuối (body vs ATR): {$lastBody}x — {$bodyDesc}
Trend LTF: {$structure['trend']} | BOS: {$this->boolStr($structure['bos'] ?? false)} | CHoCH: {$this->boolStr($structure['choch'] ?? false)}
Trend HTF: {$htfStructure['trend']}

=== MARKET STRUCTURE ===
Order Blocks:
  {$obStr}
FVG: {$fvgStr}

Đánh giá tín hiệu này với tư cách senior trader. YÊU CẦU NGHIÊM NGẶT:
- Phải CITE GIÁ THỰC (số, không nói chung chung "vùng kháng cự")
- Phải NÊU RÕ lý do score dựa trên data trên (ADX={$adx}, momentum, OB, v.v.)
- KHÔNG dùng câu chung như "quản lý rủi ro tốt" hay "thị trường biến động"
- recommendation phải là 1 trong 3: "VÀO LỆNH NGAY" / "CHỜ RETEST {giá cụ thể}" / "BỎ QUA — {lý do ngắn}"

Trả về JSON:
{
  "score": 0-100,
  "analysis": "2-3 câu CITE GIÁ CỤ THỂ: nhận xét momentum, vị trí entry so với swing/OB/FVG",
  "risk_warning": "1 rủi ro THỰC TẾ nhất với giá cụ thể (vd: resistance tại {$swingH} chỉ cách {$distToSwingH}%)",
  "recommendation": "VÀO LỆNH NGAY | CHỜ RETEST {giá} | BỎ QUA — {lý do}",
  "entry_timing": "market order / limit tại {giá} / chờ close {TF}"
}
PROMPT;

                $response = $client->post('https://openrouter.ai/api/v1/chat/completions', [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $apiKey,
                        'Content-Type'  => 'application/json',
                        'HTTP-Referer'  => 'https://tomai.app',
                    ],
                    'json' => [
                        'model'           => 'openai/gpt-4o',
                        'temperature'     => 0.2,
                        'messages'        => [
                            [
                                'role'    => 'system',
                                'content' => 'Bạn là senior crypto futures trader với 10 năm kinh nghiệm SMC. Phân tích LUÔN dùng số liệu cụ thể từ dữ liệu được cung cấp. TUYỆT ĐỐI KHÔNG dùng câu generic. Chỉ trả về JSON hợp lệ.',
                            ],
                            ['role' => 'user', 'content' => $prompt],
                        ],
                        'response_format' => ['type' => 'json_object'],
                    ],
                ]);

                $result  = json_decode($response->getBody(), true);
                $content = $result['choices'][0]['message']['content'] ?? '{}';
                $aiData  = json_decode($content, true);

                if ($aiData) {
                    $signal['ai_score']          = is_numeric($aiData['score'] ?? null) ? min(100, max(0, (int) $aiData['score'])) : 50;
                    $signal['ai_analysis']        = $this->flattenAiField($aiData['analysis']        ?? '');
                    $signal['ai_risk']            = $this->flattenAiField($aiData['risk_warning']    ?? '');
                    $signal['ai_recommendation']  = $this->flattenAiField($aiData['recommendation']  ?? '');
                    $signal['ai_entry_timing']    = $this->flattenAiField($aiData['entry_timing']    ?? '');
                }

            } catch (\GuzzleHttp\Exception\ConnectException $e) {
                \Log::warning('AI score: connect timeout');
                $signal['ai_score'] = 50;
                $signal['ai_error'] = 'AI timeout';
            } catch (\GuzzleHttp\Exception\RequestException $e) {
                \Log::warning('AI score: ' . $e->getMessage());
                $signal['ai_score'] = 50;
                $signal['ai_error'] = 'AI unavailable';
            } catch (\Exception $e) {
                \Log::warning('AI score: ' . $e->getMessage());
                $signal['ai_score'] = 50;
                $signal['ai_error'] = 'AI error';
            }

            return $signal;
        });
    }

    public function adviseOpenPosition(
        array $klines,
        array $klinesHTF,
        string $symbol,
        string $timeframe,
        string $type,
        float $entry,
        ?float $sl,
        ?float $tp,
        float $currentPrice
    ): array {
        $apiKey = env('OPENROUTER_API_KEY');
        if (!$apiKey) {
            return ['verdict' => 'AI chưa cấu hình', 'analysis' => 'Thiếu OPENROUTER_API_KEY', 'sl_advice' => null, 'tp_advice' => null];
        }

        $candles    = $this->formatCandles($klines);
        $htfCandles = $this->formatCandles($klinesHTF);

        if (count($candles) < 10) {
            return ['verdict' => 'Không đủ dữ liệu', 'analysis' => 'Không lấy được klines', 'sl_advice' => null, 'tp_advice' => null];
        }

        $structure    = $this->detectSMCStructure($candles);
        $htfStructure = $this->detectSMCStructure($htfCandles);
        $fvgs         = $this->detectFVG($candles);
        $orderBlocks  = $this->findHighQualityOB($candles, $fvgs);

        $last50  = array_slice($candles, -50);
        $highs   = array_column($last50, 'high');
        $lows    = array_column($last50, 'low');
        $swingH  = $highs ? round(max($highs), 6) : 0;
        $swingL  = $lows  ? round(min($lows),  6) : 0;

        $adxArr  = $this->calculateADX($candles, 14);
        $atrArr  = $this->calculateATR($candles, 14);
        $adx     = round((float)(end($adxArr) ?: 0), 1);
        $atr     = round((float)(end($atrArr) ?: 0), 6);

        // P&L hiện tại
        $pnlPct = $entry > 0 ? round(($type === 'LONG'
            ? ($currentPrice - $entry) / $entry
            : ($entry - $currentPrice) / $entry) * 100, 2) : 0;
        $pnlSign = $pnlPct >= 0 ? "+{$pnlPct}%" : "{$pnlPct}%";

        // SL/TP phân tích
        $slPct   = ($sl && $entry > 0) ? round(abs($entry - $sl) / $entry * 100, 2) : null;
        $tpPct   = ($tp && $entry > 0) ? round(abs($tp - $entry) / $entry * 100, 2) : null;
        $rr      = ($slPct && $tpPct && $slPct > 0) ? round($tpPct / $slPct, 2) : null;
        $slStr   = $sl ? "{$sl} (-{$slPct}%)" : 'chưa đặt';
        $tpStr   = $tp ? "{$tp} (+{$tpPct}%)" : 'chưa đặt';
        $rrStr   = $rr ? "1:{$rr}" : 'N/A';

        // Khoảng cách tới swing
        $distToSwingH = $swingH > 0 ? round(abs($currentPrice - $swingH) / $currentPrice * 100, 2) : 0;
        $distToSwingL = $swingL > 0 ? round(abs($currentPrice - $swingL) / $currentPrice * 100, 2) : 0;

        // OB gần nhất
        $obLines = [];
        foreach (array_slice($orderBlocks, -3) as $ob) {
            $obLines[] = strtoupper($ob['type']) . ' OB @' . $ob['price'] . ' (' . $ob['strength'] . ')';
        }
        $obStr = $obLines ? implode(', ', $obLines) : 'không có';

        // FVG gần nhất
        $fvgLines = [];
        foreach (array_slice($fvgs, -2) as $fvg) {
            $fvgLines[] = strtoupper($fvg['type']) . ' FVG ' . $fvg['low'] . '–' . $fvg['high'];
        }
        $fvgStr = $fvgLines ? implode(', ', $fvgLines) : 'không có';

        // Momentum 5 nến
        $last5     = array_slice($candles, -5);
        $bullCount = count(array_filter($last5, fn($c) => $c['close'] > $c['open']));
        $momentumStr = "{$bullCount} xanh / " . (5 - $bullCount) . " đỏ";

        $prompt = <<<PROMPT
SYMBOL: {$symbol} | TF: {$timeframe}

=== LỆNH ĐANG MỞ ===
Loại   : {$type}
Entry  : {$entry}
Giá hiện tại: {$currentPrice} (P&L: {$pnlSign})
SL     : {$slStr}
TP     : {$tpStr}
R:R    : {$rrStr}

=== THỊ TRƯỜNG HIỆN TẠI ===
Trend LTF: {$structure['trend']} | BOS: {$this->boolStr($structure['bos'] ?? false)} | CHoCH: {$this->boolStr($structure['choch'] ?? false)}
Trend HTF: {$htfStructure['trend']}
ADX: {$adx} ({$this->adxDesc($adx)})
ATR: {$atr}
Momentum 5 nến: {$momentumStr}
Swing High 50 nến: {$swingH} (cách {$distToSwingH}%)
Swing Low  50 nến: {$swingL} (cách {$distToSwingL}%)
Order Blocks: {$obStr}
FVG: {$fvgStr}

Với tư cách senior trader, hãy tư vấn trader này nên làm gì với lệnh đang mở.
YÊU CẦU: cite giá thực, không dùng câu chung chung. Phán quyết phải là 1 trong: GIỮ LỆNH / DI CHUYỂN SL / ĐIỀU CHỈNH TP / CHỐT LỜI NGAY / CẮT LỖ NGAY / CHỐT 50% + GIỮ 50%.

Trả về JSON:
{
  "verdict": "GIỮ LỆNH | DI CHUYỂN SL | ĐIỀU CHỈNH TP | CHỐT LỜI NGAY | CẮT LỖ NGAY | CHỐT 50% + GIỮ 50%",
  "analysis": "2-3 câu phân tích cụ thể với giá thực tế, lý do rõ ràng",
  "sl_advice": "null hoặc khuyến nghị SL mới cụ thể với giá (vd: di chuyển SL lên {giá} để breakeven)",
  "tp_advice": "null hoặc khuyến nghị TP mới cụ thể với giá"
}
PROMPT;

        try {
            $client   = new \GuzzleHttp\Client(['timeout' => 12, 'connect_timeout' => 4]);
            $response = $client->post('https://openrouter.ai/api/v1/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type'  => 'application/json',
                    'HTTP-Referer'  => 'https://tomai.app',
                ],
                'json' => [
                    'model'           => 'openai/gpt-4o',
                    'temperature'     => 0.2,
                    'messages'        => [
                        ['role' => 'system', 'content' => 'Bạn là senior crypto futures trader. Tư vấn cụ thể, cite giá thực, không nói chung chung. Chỉ trả về JSON hợp lệ.'],
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    'response_format' => ['type' => 'json_object'],
                ],
            ]);

            $result  = json_decode($response->getBody(), true);
            $content = $result['choices'][0]['message']['content'] ?? '{}';
            $data    = json_decode($content, true);

            return [
                'verdict'   => $this->flattenAiField($data['verdict']   ?? 'Không rõ'),
                'analysis'  => $this->flattenAiField($data['analysis']  ?? ''),
                'sl_advice' => $this->flattenAiField($data['sl_advice'] ?? '') ?: null,
                'tp_advice' => $this->flattenAiField($data['tp_advice'] ?? '') ?: null,
            ];
        } catch (\Exception $e) {
            \Log::warning('Advisor AI error: ' . $e->getMessage());
            return ['verdict' => 'AI lỗi', 'analysis' => 'Không kết nối được AI: ' . $e->getMessage(), 'sl_advice' => null, 'tp_advice' => null];
        }
    }

    private function adxDesc(float $adx): string
    {
        if ($adx >= 30) return 'xu hướng rất mạnh';
        if ($adx >= 25) return 'xu hướng mạnh';
        if ($adx >= 20) return 'xu hướng vừa';
        return 'ranging/yếu';
    }

    private function boolStr(bool $v): string
    {
        return $v ? 'Có' : 'Không';
    }

    private function flattenAiField(mixed $v): string
    {
        return is_array($v) ? implode(' ', $v) : (string) $v;
    }
}
