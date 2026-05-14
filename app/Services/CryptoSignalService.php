<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class CryptoSignalService
{
    public function __construct(
        private BinanceService     $binance,
        private PriceActionService $pas,
    ) {}

    /**
     * Analyze a crypto symbol and return a signal array or null.
     * Uses 4h SMC + EMA alignment + funding rate + L/S ratio.
     */
    public function analyze(string $symbol, float $currentPrice): ?array
    {
        // Fetch data
        $k4h = $this->binance->getKlines($symbol, '4h', 200);
        $k1d = $this->binance->getKlines($symbol, '1d', 60);

        if (count($k4h) < 50) return null;

        // ── 1. EMA 20/50/200 alignment on 4h ──
        $closes = array_map(fn($k) => (float)$k[4], $k4h);
        $ema20  = $this->ema($closes, 20);
        $ema50  = $this->ema($closes, 50);
        $ema200 = $this->ema($closes, 200);
        $last   = end($closes);

        $emaBull = $ema20 > $ema50 && $ema50 > $ema200 && $last > $ema200;
        $emaBear = $ema20 < $ema50 && $ema50 < $ema200 && $last < $ema200;

        if (!$emaBull && !$emaBear) return null; // no clear EMA alignment

        $emaBias = $emaBull ? 'LONG' : 'SHORT';

        // ── 2. Funding rate filter ──
        $funding = $this->binance->getFundingRate($symbol);
        // Over-long market (funding > +0.05%) → skip LONG signals
        if ($emaBias === 'LONG' && $funding > 0.0005) return null;
        // Over-short market (funding < -0.02%) → skip SHORT signals
        if ($emaBias === 'SHORT' && $funding < -0.0002) return null;

        // ── 3. Top trader L/S ratio confirmation ──
        $lsRatio = $this->binance->getTopLSRatio($symbol, '4h');
        // For LONG: top traders should be leaning long (> 0.45) or at least neutral
        // For SHORT: top traders leaning short (< 0.55)
        if ($emaBias === 'LONG' && $lsRatio < 0.35) return null;
        if ($emaBias === 'SHORT' && $lsRatio > 0.65) return null;

        // ── 4. SMC signal from PriceActionService on 4h ──
        $this->pas->setThresholds(20, 70);
        $analysis = $this->pas->analyze(
            $k4h, $k1d, 'smc', $symbol, '4h',
            true, $k1d, false
        );
        $signal = $analysis['signal'] ?? null;

        if (!$signal) return null;
        if ($signal['type'] !== $emaBias) return null; // signal must match EMA bias

        // ── 5. Widen SL to 1.5× ATR(14) from 4h ──
        $atr = $this->atr($k4h, 14);
        if ($atr <= 0) return null;

        $slDist = $atr * 1.5;
        $sl = $signal['type'] === 'LONG'
            ? round($currentPrice - $slDist, 4)
            : round($currentPrice + $slDist, 4);

        // ── 6. Recalculate TP at R:R 2.5 ──
        $tp = $signal['type'] === 'LONG'
            ? round($currentPrice + $slDist * 2.5, 4)
            : round($currentPrice - $slDist * 2.5, 4);

        // ── 7. Volume confirmation ──
        $volumes = array_map(fn($k) => (float)$k[5], array_slice($k4h, -21));
        $avgVol  = array_sum(array_slice($volumes, 0, 20)) / 20;
        $lastVol = end($volumes);
        if ($lastVol < $avgVol * 0.8) return null; // low volume = weak setup

        $fundingPct = round($funding * 100, 4);
        $lsPct      = round($lsRatio * 100, 1);
        $fundingStr = ($funding >= 0 ? '+' : '') . $fundingPct . '%';

        return array_merge($signal, [
            'entry'        => $currentPrice,
            'tp'           => $tp,
            'sl'           => $sl,
            'timeframe'    => '4h',
            'funding_rate' => $funding,
            'ls_ratio'     => $lsRatio,
            'reason'       => "EMA {$emaBias} alignment | Funding {$fundingStr} | TopTraders {$lsPct}% long | " . ($signal['reason'] ?? 'SMC OB 4h'),
        ]);
    }

    // ── Helpers ──

    private function ema(array $closes, int $period): float
    {
        if (count($closes) < $period) return end($closes) ?: 0.0;
        $k   = 2.0 / ($period + 1);
        $ema = array_sum(array_slice($closes, 0, $period)) / $period;
        for ($i = $period; $i < count($closes); $i++) {
            $ema = $closes[$i] * $k + $ema * (1 - $k);
        }
        return $ema;
    }

    private function atr(array $klines, int $period = 14): float
    {
        $n  = count($klines);
        if ($n < $period + 1) return 0.0;
        $trs = [];
        for ($i = 1; $i < $n; $i++) {
            $trs[] = max(
                (float)$klines[$i][2] - (float)$klines[$i][3],
                abs((float)$klines[$i][2] - (float)$klines[$i-1][4]),
                abs((float)$klines[$i][3] - (float)$klines[$i-1][4])
            );
        }
        return array_sum(array_slice($trs, -$period)) / $period;
    }
}
