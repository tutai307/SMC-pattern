<?php

namespace App\Console\Commands;

use App\Services\BinanceService;
use App\Services\TelegramService;
use Illuminate\Console\Command;

class TrendHourlyCommand extends Command
{
    protected $signature   = 'trend:hourly';
    protected $description = 'Dự đoán xu hướng multi-timeframe mỗi giờ cho XAGUSDT, XAUUSDT, BTCUSDT và gửi Telegram';

    private BinanceService  $binance;
    private TelegramService $telegram;

    private array $symbols = ['XAGUSDT', 'XAUUSDT', 'BTCUSDT'];

    private array $emojiMap = [
        'XAGUSDT' => '🥈',
        'XAUUSDT' => '🥇',
        'BTCUSDT' => '₿',
    ];

    public function __construct(BinanceService $binance, TelegramService $telegram)
    {
        parent::__construct();
        $this->binance  = $binance;
        $this->telegram = $telegram;
    }

    public function handle(): int
    {
        $now     = now();
        $header  = '<b>📊 BÁO CÁO XU HƯỚNG</b> — '
                 . $now->format('H:i') . ' ' . $now->format('d/m/Y');

        $blocks = [];

        foreach ($this->symbols as $symbol) {
            $blocks[] = $this->buildSymbolBlock($symbol);
        }

        $message = $header . "\n\n" . implode("\n\n", $blocks);

        $this->telegram->sendRaw($message);
        $this->info('Trend report sent.');

        return 0;
    }

    // -------------------------------------------------------------------------
    // Per-symbol analysis
    // -------------------------------------------------------------------------

    private function buildSymbolBlock(string $symbol): string
    {
        $emoji = $this->emojiMap[$symbol] ?? '🔸';

        // Fetch klines
        $dailyKlines = $this->binance->getKlines($symbol, '1d', 30);
        $h4Klines    = $this->binance->getKlines($symbol, '4h', 50);
        $h1Klines    = $this->binance->getKlines($symbol, '1h', 50);

        if (empty($dailyKlines) || empty($h4Klines) || empty($h1Klines)) {
            $missing = implode(', ', array_filter([
                empty($dailyKlines) ? '1D' : null,
                empty($h4Klines)    ? '4H' : null,
                empty($h1Klines)    ? '1H' : null,
            ]));
            Log::warning("TrendHourly: no data for {$symbol} (missing: {$missing})");
            return "━━━━━━━━━━━━━━━\n"
                 . "{$emoji} <b>{$symbol}</b>\n"
                 . "━━━━━━━━━━━━━━━\n"
                 . "⚠️ Không lấy được dữ liệu ({$missing}) — Binance API lỗi hoặc timeout.";
        }

        $dailyCandles = $this->formatCandles($dailyKlines);
        $h4Candles    = $this->formatCandles($h4Klines);
        $h1Candles    = $this->formatCandles($h1Klines);

        $currentPrice = (float)(end($dailyCandles)['close']);

        // --- Daily macro ---
        $dailyCloses = array_column($dailyCandles, 'close');
        $dailyEMA20  = $this->simpleEMA($dailyCloses, 20);
        $dailyClose  = end($dailyCloses);
        $dailyBullish = $dailyClose > $dailyEMA20;
        $dailyLabel   = $dailyBullish ? 'TĂNG' : 'GIẢM';

        // --- 4h momentum ---
        $h4Closes   = array_column($h4Candles, 'close');
        $h4EMA20    = $this->simpleEMA($h4Closes, 20);
        $h4Close    = end($h4Closes);
        $ema4hBull  = $h4Close > $h4EMA20;

        $rsi4h      = $this->calculateRSI($h4Candles, 14);
        $adx4h      = $this->calculateADXLast($h4Candles, 14);

        $rsiLabel   = $rsi4h > 55 ? 'Bullish' : ($rsi4h < 45 ? 'Bearish' : 'Neutral');
        $adxLabel   = $adx4h > 25 ? 'Trending' : 'Ranging';
        $h4Bull     = $ema4hBull && $rsi4h > 55;
        $h4Bear     = !$ema4hBull && $rsi4h < 45;

        // --- 1h structure ---
        $h1Trend    = $this->detectTrend($h1Candles);

        // --- Prediction scoring ---
        $greenCount = ($dailyBullish ? 1 : 0)
                    + ($h4Bull       ? 1 : 0)
                    + ($h1Trend === 'TĂNG' ? 1 : 0);

        $redCount   = (!$dailyBullish     ? 1 : 0)
                    + ($h4Bear            ? 1 : 0)
                    + ($h1Trend === 'GIẢM' ? 1 : 0);

        if ($greenCount === 3) {
            $prediction = 'TĂNG MẠNH';
        } elseif ($greenCount === 2) {
            $prediction = 'CÓ THỂ TĂNG';
        } elseif ($redCount === 3) {
            $prediction = 'GIẢM MẠNH';
        } elseif ($redCount === 2) {
            $prediction = 'CÓ THỂ GIẢM';
        } else {
            $prediction = 'ĐI NGANG / KHÔNG RÕ';
        }

        // --- Format price ---
        $priceStr  = $this->formatPrice($currentPrice);
        $ema20Str  = $this->formatPrice($dailyEMA20);

        $rsiRounded = round($rsi4h, 1);
        $adxRounded = round($adx4h, 1);

        return "━━━━━━━━━━━━━━━\n"
             . "{$emoji} <b>{$symbol}</b> — \${$priceStr}\n"
             . "━━━━━━━━━━━━━━━\n"
             . "📈 Daily: {$dailyLabel} (close \${$priceStr} " . ($dailyBullish ? '>' : '<') . " EMA20 \${$ema20Str})\n"
             . "⚡ 4h: {$rsiLabel} (RSI {$rsiRounded}, ADX {$adxRounded} — {$adxLabel})\n"
             . "🔍 1h structure: {$h1Trend}\n"
             . "🎯 <b>Dự đoán: {$prediction}</b>";
    }

    // -------------------------------------------------------------------------
    // Helpers — indicator calculations (inline, no PriceActionService dependency)
    // -------------------------------------------------------------------------

    private function formatCandles(array $klines): array
    {
        return array_map(fn($k) => [
            'time'   => $k[0],
            'open'   => (float)$k[1],
            'high'   => (float)$k[2],
            'low'    => (float)$k[3],
            'close'  => (float)$k[4],
            'volume' => (float)$k[5],
        ], $klines);
    }

    /**
     * Simple EMA (exponential) from a flat array of close prices.
     * Returns the LAST EMA value.
     */
    private function simpleEMA(array $closes, int $period): float
    {
        if (count($closes) < $period) {
            return count($closes) > 0 ? (float)end($closes) : 0.0;
        }

        $multiplier = 2.0 / ($period + 1);
        $ema        = array_sum(array_slice($closes, 0, $period)) / $period;

        for ($i = $period; $i < count($closes); $i++) {
            $ema = ($closes[$i] - $ema) * $multiplier + $ema;
        }

        return $ema;
    }

    /**
     * RSI(14) — returns last RSI value (0-100).
     */
    private function calculateRSI(array $candles, int $period = 14): float
    {
        $closes = array_column($candles, 'close');
        $n      = count($closes);

        if ($n < $period + 1) return 50.0;

        $gains = []; $losses = [];
        for ($i = 1; $i < $n; $i++) {
            $delta = $closes[$i] - $closes[$i - 1];
            $gains[]  = max(0, $delta);
            $losses[] = max(0, -$delta);
        }

        // Initial average
        $avgGain = array_sum(array_slice($gains,  0, $period)) / $period;
        $avgLoss = array_sum(array_slice($losses, 0, $period)) / $period;

        // Wilder's smoothing
        for ($i = $period; $i < count($gains); $i++) {
            $avgGain = ($avgGain * ($period - 1) + $gains[$i])  / $period;
            $avgLoss = ($avgLoss * ($period - 1) + $losses[$i]) / $period;
        }

        if ($avgLoss == 0) return 100.0;

        $rs  = $avgGain / $avgLoss;
        return 100.0 - (100.0 / (1 + $rs));
    }

    /**
     * ADX(14) — returns only the last ADX value.
     */
    private function calculateADXLast(array $candles, int $period = 14): float
    {
        $count = count($candles);
        if ($count < $period * 2) return 0.0;

        $tr = []; $dmPlus = []; $dmMinus = [];

        for ($i = 1; $i < $count; $i++) {
            $h  = $candles[$i]['high'];   $l  = $candles[$i]['low'];
            $ph = $candles[$i-1]['high']; $pl = $candles[$i-1]['low'];
            $pc = $candles[$i-1]['close'];

            $tr[]      = max($h - $l, abs($h - $pc), abs($l - $pc));
            $dmPlus[]  = ($h - $ph > $pl - $l) ? max($h - $ph, 0) : 0;
            $dmMinus[] = ($pl - $l > $h - $ph) ? max($pl - $l, 0) : 0;
        }

        $smoothTR = array_sum(array_slice($tr,      0, $period));
        $smoothDP = array_sum(array_slice($dmPlus,  0, $period));
        $smoothDM = array_sum(array_slice($dmMinus, 0, $period));

        $adxLast = 0.0;

        for ($i = $period; $i < $count - 1; $i++) {
            $diPlus  = 100 * ($smoothDP / ($smoothTR ?: 1));
            $diMinus = 100 * ($smoothDM / ($smoothTR ?: 1));
            $dx      = 100 * abs($diPlus - $diMinus) / (($diPlus + $diMinus) ?: 1);
            $adxLast = $dx;

            if (isset($tr[$i])) {
                $smoothTR = $smoothTR - ($smoothTR / $period) + $tr[$i];
                $smoothDP = $smoothDP - ($smoothDP / $period) + $dmPlus[$i];
                $smoothDM = $smoothDM - ($smoothDM / $period) + $dmMinus[$i];
            }
        }

        return $adxLast;
    }

    /**
     * Detect trend from 1h candles using swing points.
     * Returns 'TĂNG', 'GIẢM', or 'ĐI NGANG'.
     */
    private function detectTrend(array $candles): string
    {
        $count = count($candles);
        if ($count < 10) return 'ĐI NGANG';

        $window = 3;
        $highs  = [];
        $lows   = [];

        for ($i = $window; $i < $count - $window; $i++) {
            $isHigh = true;
            $isLow  = true;
            for ($j = $i - $window; $j <= $i + $window; $j++) {
                if ($candles[$j]['high'] > $candles[$i]['high']) $isHigh = false;
                if ($candles[$j]['low']  < $candles[$i]['low'])  $isLow  = false;
            }
            if ($isHigh) $highs[] = $candles[$i]['high'];
            if ($isLow)  $lows[]  = $candles[$i]['low'];
        }

        if (count($highs) < 2 || count($lows) < 2) return 'ĐI NGANG';

        $lastHigh = end($highs);
        $prevHigh = $highs[count($highs) - 2];
        $lastLow  = end($lows);
        $prevLow  = $lows[count($lows)  - 2];

        if ($lastHigh > $prevHigh && $lastLow > $prevLow) return 'TĂNG';
        if ($lastHigh < $prevHigh && $lastLow < $prevLow) return 'GIẢM';

        return 'ĐI NGANG';
    }

    /**
     * Format price — strip trailing zeros, keep meaningful decimals.
     */
    private function formatPrice(float $price): string
    {
        if ($price >= 1000) {
            return number_format($price, 2);
        }
        if ($price >= 1) {
            $s = number_format($price, 4);
            $s = rtrim($s, '0');
            return rtrim($s, '.');
        }
        // Very small prices
        $s = number_format($price, 6);
        $s = rtrim($s, '0');
        return rtrim($s, '.');
    }
}
