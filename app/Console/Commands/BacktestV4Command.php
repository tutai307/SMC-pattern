<?php

namespace App\Console\Commands;

use App\Services\BinanceService;
use App\Services\PriceActionService;
use Illuminate\Console\Command;

/**
 * v5 Walk-forward backtest — Channel Compression Breakout.
 *
 * Logic:
 *   Mỗi bar M15: detectUnpredictableChannel()
 *   Tính channelScore() 0-100 → chọn lot cao/thấp
 *   Đặt BUY_STOP + SELL_STOP, scan TP/SL, expiry sau N bars
 */
class BacktestV4Command extends Command
{
    protected $signature = 'backtest:v4
        {--symbol=XAUUSDT        : Symbol}
        {--from=2026-05-01       : Ngày bắt đầu YYYY-MM-DD}
        {--to=                   : Ngày kết thúc (mặc định hôm nay)}
        {--tp-pips=15            : TP cố định (pips)}
        {--sl-pips=20            : SL cứng (pips)}
        {--buf-pips=3            : Buffer ngoài đỉnh/đáy (pips)}
        {--lot=0.05              : Lot size cho mỗi lệnh}
        {--ma=100                : MA period để xác định trend (chỉ trade theo chiều MA)}
        {--expiry-bars=16        : Hủy stop sau N nến không khớp}
        {--lookback=40           : Lookback channel detection}
        {--min-compression=0.0   : Chỉ trade khi compression >= giá trị này}';

    protected $description = 'v5 Backtest — tiered lot theo channel score (0-100)';

    private float $pipSize  = 0.10;
    private float $pipValue = 10.0;
    private int   $decimals = 2;

    public function handle(BinanceService $binance, PriceActionService $pa): int
    {
        $symbol      = strtoupper($this->option('symbol'));
        $from        = $this->option('from');
        $to          = $this->option('to') ?: now()->format('Y-m-d');
        $tpPips     = (float) $this->option('tp-pips');
        $slPips     = (float) $this->option('sl-pips');
        $bufPips    = (float) $this->option('buf-pips');
        $lot        = (float) $this->option('lot');
        $maPeriod   = (int)   $this->option('ma');
        $expiryBars = (int)   $this->option('expiry-bars');
        $lookback   = (int)   $this->option('lookback');
        $minComp    = (float) $this->option('min-compression');

        if (str_contains($symbol, 'XAG')) {
            $this->pipSize  = 0.001;
            $this->pipValue = 1.0;
            $this->decimals = 3;
        }

        $fromTs = strtotime($from) * 1000;
        $toTs   = strtotime($to . ' 23:59:59') * 1000;

        $this->info("╔══════════════════════════════════════════════════╗");
        $this->info("║  FELIX v5 BACKTEST — {$symbol} M15              ║");
        $this->info("╚══════════════════════════════════════════════════╝");
        $this->info("Period    : {$from} → {$to}");
        $this->info("TP/SL     : {$tpPips}p / {$slPips}p cứng  R:R 1:" . round($tpPips / $slPips, 2));
        $this->info("Lot       : {$lot}  |  Trend filter: MA{$maPeriod} (chỉ trade thuận chiều)");
        $this->line('');

        $this->line("Đang tải klines {$symbol} M15...");
        $allKlines = $this->fetchHistoricalKlines($binance, $symbol, $fromTs, $toTs);

        if (count($allKlines) < $lookback + 10) {
            $this->error("Không đủ dữ liệu: " . count($allKlines) . " bars");
            return 1;
        }
        $this->line("Tải được " . count($allKlines) . " nến M15\n");

        // ── Walk-forward simulation ──
        $trades        = [];
        $pendingOrders = [];
        $channelDedup  = [];
        $warmup        = max(200, $lookback + 6);

        $bufDist = $bufPips * $this->pipSize;
        $tpDist  = $tpPips  * $this->pipSize;
        $slDist  = $slPips  * $this->pipSize;

        for ($i = $warmup; $i < count($allKlines); $i++) {
            $bar     = $allKlines[$i];
            $barTime = (int)   $bar[0];
            $barHigh = (float) $bar[2];
            $barLow  = (float) $bar[3];

            // ── A. Check pending orders ──
            foreach ($pendingOrders as $oid => &$order) {
                if ($i - $order['placed_at_bar'] > $expiryBars) {
                    $order['status'] = 'EXPIRED';
                    unset($pendingOrders[$oid]);
                    continue;
                }
                if ($order['status'] !== 'PENDING') continue;

                if (!$order['triggered']) {
                    $hit = $order['side'] === 'BUY'
                        ? $barHigh >= $order['entry']
                        : $barLow  <= $order['entry'];
                    if ($hit) {
                        $order['triggered']      = true;
                        $order['triggered_bar']  = $i;
                        $order['triggered_time'] = $barTime;
                    } else {
                        continue;
                    }
                }

                if ($order['side'] === 'BUY') {
                    if ($barHigh >= $order['tp']) {
                        $order['status'] = 'WIN';  $order['pnl_pips'] = $tpPips;
                        $trades[] = $order; unset($pendingOrders[$oid]);
                    } elseif ($barLow <= $order['sl']) {
                        $order['status'] = 'LOSS'; $order['pnl_pips'] = -$slPips;
                        $trades[] = $order; unset($pendingOrders[$oid]);
                    }
                } else {
                    if ($barLow <= $order['tp']) {
                        $order['status'] = 'WIN';  $order['pnl_pips'] = $tpPips;
                        $trades[] = $order; unset($pendingOrders[$oid]);
                    } elseif ($barHigh >= $order['sl']) {
                        $order['status'] = 'LOSS'; $order['pnl_pips'] = -$slPips;
                        $trades[] = $order; unset($pendingOrders[$oid]);
                    }
                }
            }
            unset($order);

            // ── B. Detect channel ──
            if ($i % 3 !== 0) continue;

            $window  = array_slice($allKlines, max(0, $i - 200), 200);
            $channel = $pa->detectUnpredictableChannel($window, $lookback);

            if (!$channel['is_channel']) continue;
            if ($channel['compression'] < $minComp) continue;

            $fingerprint = round($channel['upper'], 1) . '_' . round($channel['lower'], 1);
            if (isset($channelDedup[$fingerprint]) && $i - $channelDedup[$fingerprint] < $expiryBars) {
                continue;
            }
            $channelDedup[$fingerprint] = $i;

            // Trend filter: tính MA → chỉ place lệnh thuận chiều
            $closes    = array_map(fn($k) => (float) $k[4], $window);
            $lastClose = end($closes);
            $ma        = count($closes) >= $maPeriod
                ? array_sum(array_slice($closes, -$maPeriod)) / $maPeriod
                : null;

            $bullish = $ma === null || $lastClose >= $ma; // không đủ data → trade cả 2
            $bearish = $ma === null || $lastClose <  $ma;

            $buyEntry  = round($channel['upper'] + $bufDist, $this->decimals);
            $buyTp     = round($buyEntry + $tpDist, $this->decimals);
            $buySl     = round($buyEntry - $slDist, $this->decimals);

            $sellEntry = round($channel['lower'] - $bufDist, $this->decimals);
            $sellTp    = round($sellEntry - $tpDist, $this->decimals);
            $sellSl    = round($sellEntry + $slDist, $this->decimals);

            $maScore = $ma !== null ? round($ma, $this->decimals) : 0;

            $baseCommon = [
                'placed_at_bar'  => $i,
                'placed_at_time' => $barTime,
                'sl_pips'        => $slPips,
                'lots'           => $lot,
                'score'          => $maScore,
                'compression'    => $channel['compression'],
                'upper'          => $channel['upper'],
                'lower'          => $channel['lower'],
                'triggered'      => false,
                'status'         => 'PENDING',
                'pnl_pips'       => 0,
            ];

            if ($bullish) {
                $pendingOrders['buy_' . $i] = array_merge($baseCommon, [
                    'id' => 'buy_' . $i, 'side' => 'BUY',
                    'entry' => $buyEntry, 'tp' => $buyTp, 'sl' => $buySl,
                ]);
            }
            if ($bearish) {
                $pendingOrders['sell_' . $i] = array_merge($baseCommon, [
                    'id' => 'sell_' . $i, 'side' => 'SELL',
                    'entry' => $sellEntry, 'tp' => $sellTp, 'sl' => $sellSl,
                ]);
            }
        }

        foreach ($pendingOrders as $order) {
            $order['status'] = 'EXPIRED';
            $trades[] = $order;
        }

        $this->printReport($trades, $tpPips, $slPips, $lot, $maPeriod, $symbol, $from, $to);
        return 0;
    }

    // ──────────────────────────────────────────────────────────────
    // DIRECTIONAL SCORE  0-100
    // ──────────────────────────────────────────────────────────────

    /**
     * Đánh giá khả năng phá theo hướng $side ('BUY' | 'SELL').
     *
     * Trend score (80pts) dựa trên MA50 + MA100:
     *   close > MA50  → +25pts trend bullish
     *   close > MA100 → +25pts trend bullish mạnh hơn
     *   Baseline = 50 (neutral khi không đủ data)
     *
     *   BUY  score = trendScore (cao = uptrend = BUY bias)
     *   SELL score = 100 - trendScore (thấp trend = downtrend = SELL bias)
     *
     * Compression bonus (20pts): kênh nén tốt cộng thêm
     */
    private function directionalScore(array $channel, array $klines, string $side): int
    {
        $closes    = array_map(fn($k) => (float) $k[4], $klines);
        $n         = count($closes);
        $lastClose = end($closes);

        // MA50 + MA100
        $ma50  = $n >= 50  ? array_sum(array_slice($closes, -50))  / 50  : null;
        $ma100 = $n >= 100 ? array_sum(array_slice($closes, -100)) / 100 : null;

        // Trend score 0–100: baseline 50, +25 mỗi MA thuận chiều BUY
        $trendScore = 50;
        if ($ma50  !== null) $trendScore += $lastClose > $ma50  ? 25 : -25;
        if ($ma100 !== null) $trendScore += $lastClose > $ma100 ? 25 : -25;
        $trendScore = max(0, min(100, $trendScore));

        // Compression bonus 0–20pts
        $compBonus = (int) round($channel['compression'] * 20);

        $raw = $side === 'BUY'
            ? $trendScore * 0.8 + $compBonus       // uptrend → BUY score cao
            : (100 - $trendScore) * 0.8 + $compBonus; // downtrend → SELL score cao

        return min(100, (int) round($raw));
    }

    // ──────────────────────────────────────────────────────────────
    // REPORT
    // ──────────────────────────────────────────────────────────────

    private function printReport(
        array  $trades,
        float  $tpPips,
        float  $slPips,
        float  $lot,
        int    $maPeriod,
        string $symbol,
        string $from,
        string $to
    ): void {
        $triggered = array_values(array_filter($trades, fn($t) => $t['triggered'] ?? false));
        $expired   = array_filter($trades, fn($t) => $t['status'] === 'EXPIRED');
        $placed    = count($trades);

        $wins   = array_filter($triggered, fn($t) => $t['status'] === 'WIN');
        $losses = array_filter($triggered, fn($t) => $t['status'] === 'LOSS');
        $nTrig  = count($triggered);
        $nWin   = count($wins);
        $nLoss  = count($losses);

        $winrate  = $nTrig > 0 ? round($nWin / $nTrig * 100, 1) : 0;
        $winPips  = array_sum(array_column(array_values($wins),   'pnl_pips'));
        $lossPips = array_sum(array_column(array_values($losses),  'pnl_pips'));
        $avgWin   = $nWin  > 0 ? round($winPips  / $nWin,  1) : 0;
        $avgLoss  = $nLoss > 0 ? round($lossPips / $nLoss, 1) : 0;
        $ev       = $nTrig > 0
            ? round(($winrate / 100 * $tpPips) + ((1 - $winrate / 100) * $avgLoss), 2)
            : 0;

        $totalUsd  = round(array_sum(array_map(
            fn($t) => $t['pnl_pips'] * $this->pipValue * $t['lots'], $triggered
        )), 2);
        $totalPips = array_sum(array_column($triggered, 'pnl_pips'));

        $sideStats = function(string $side) use ($triggered): array {
            $g  = array_values(array_filter($triggered, fn($t) => $t['side'] === $side));
            $n  = count($g);
            $nW = count(array_filter($g, fn($t) => $t['status'] === 'WIN'));
            return [
                'n'    => $n,
                'wr'   => $n > 0 ? round($nW / $n * 100, 1) : 0,
                'pips' => array_sum(array_column($g, 'pnl_pips')),
                'usd'  => round(array_sum(array_map(fn($t) => $t['pnl_pips'] * 10.0 * $t['lots'], $g)), 2),
            ];
        };
        $bS = $sideStats('BUY');
        $sS = $sideStats('SELL');

        // Daily breakdown
        $dailyPnl = [];
        foreach ($triggered as $t) {
            $day = date('m/d', intdiv((int)$t['placed_at_time'], 1000));
            $dailyPnl[$day] = ($dailyPnl[$day] ?? 0) + $t['pnl_pips'];
        }
        ksort($dailyPnl);

        $this->line('');
        $this->info('══════════════════════════════════════════════════');
        $this->info("  KẾT QUẢ BACKTEST — {$symbol} M15  ({$from} → {$to})");
        $this->info('══════════════════════════════════════════════════');
        $this->line(sprintf('  Channels phát hiện : %d  (lọc MA%d → %d lệnh thực tế)',
            intdiv($placed + 1, 2), $maPeriod, $placed));
        $this->line(sprintf('  Lệnh khớp          : %d  |  Hết hạn: %d', $nTrig, count($expired)));
        $this->line('──────────────────────────────────────────────────');
        ($winrate >= 50 ? fn($s) => $this->info($s) : fn($s) => $this->warn($s))(
            sprintf('  WIN    : %d  (%s%%)', $nWin, $winrate)
        );
        $this->line(sprintf('  LOSS   : %d', $nLoss));
        $this->line(sprintf('  Avg Win : %+.1f pip  |  Avg Loss: %.1f pip', $avgWin, $avgLoss));
        $this->line(sprintf('  EV/trade: %+.2f pip', $ev));
        $this->line('──────────────────────────────────────────────────');
        $this->line(sprintf('  Tổng P&L : %+.1f pip  ≈  %+.2f USD  (%.2f lot/lệnh)',
            $totalPips, $totalUsd, $lot));
        $this->line('──────────────────────────────────────────────────');

        // BUY vs SELL
        $this->info('  PHÂN TÍCH THEO HƯỚNG (MA filter):');
        $this->line(sprintf('  [BUY  ]  %d lệnh  WR %.1f%%  %+.1f pip  ≈ %+.2f USD',
            $bS['n'], $bS['wr'], $bS['pips'], $bS['usd']));
        $this->line(sprintf('  [SELL ]  %d lệnh  WR %.1f%%  %+.1f pip  ≈ %+.2f USD',
            $sS['n'], $sS['wr'], $sS['pips'], $sS['usd']));
        $this->line('──────────────────────────────────────────────────');

        // Daily P&L
        $this->line('  Daily P&L (pips):');
        foreach ($dailyPnl as $day => $pips) {
            $bar   = str_repeat($pips >= 0 ? '█' : '░', min(20, (int) abs($pips / 3)));
            $color = $pips >= 0 ? 'info' : 'warn';
            $this->{$color}(sprintf('    %s  %s%+.1f', $day, $bar, $pips));
        }

        // Top 5 best / worst
        usort($triggered, fn($a, $b) => $b['pnl_pips'] <=> $a['pnl_pips']);
        $this->line('──────────────────────────────────────────────────');
        $this->line('  Top 5 trades tốt nhất:');
        foreach (array_slice($triggered, 0, 5) as $t) {
            $dt = date('m/d H:i', intdiv((int)$t['placed_at_time'], 1000));
            $this->info(sprintf('    [%s] %s @ %.2f  %+.1f pip  score=%d  %.2f lot',
                $dt, $t['side'], $t['entry'], $t['pnl_pips'], $t['score'], $t['lots']));
        }
        $this->line('  Top 5 trades tệ nhất:');
        foreach (array_slice(array_reverse($triggered), 0, 5) as $t) {
            $dt = date('m/d H:i', intdiv((int)$t['placed_at_time'], 1000));
            $this->warn(sprintf('    [%s] %s @ %.2f  %+.1f pip  score=%d  %.2f lot',
                $dt, $t['side'], $t['entry'], $t['pnl_pips'], $t['score'], $t['lots']));
        }
        $this->info('══════════════════════════════════════════════════');
    }

    // ──────────────────────────────────────────────────────────────
    // DATA FETCHING
    // ──────────────────────────────────────────────────────────────

    private function fetchHistoricalKlines(BinanceService $binance, string $symbol, int $fromMs, int $toMs): array
    {
        $all    = [];
        $limit  = 1000;
        $tfMs   = 15 * 60 * 1000;
        $cursor = $fromMs - 200 * $tfMs;

        $this->output->write("  Tải: ");
        while ($cursor < $toMs) {
            $batch = $binance->getKlines($symbol, '15m', $limit, $cursor);
            if (empty($batch)) break;

            foreach ($batch as $k) {
                if ((int)$k[0] > $toMs) break 2;
                $all[] = $k;
            }

            $cursor = (int) end($batch)[0] + $tfMs;
            $this->output->write(".");
            usleep(200000);
        }
        $this->line(" " . count($all) . " bars");
        return $all;
    }
}
