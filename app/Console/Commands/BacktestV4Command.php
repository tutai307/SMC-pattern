<?php

namespace App\Console\Commands;

use App\Services\BinanceService;
use App\Services\PriceActionService;
use App\Services\SignalFormatterService;
use Illuminate\Console\Command;

/**
 * v4 Walk-forward backtest — Channel Compression Breakout.
 *
 * Logic:
 *   Mỗi bar M15: detectUnpredictableChannel()
 *   Nếu có kênh → đặt BUY_STOP tại upper+buf, SELL_STOP tại lower-buf
 *   Scan các bar tiếp theo: entry trigger → kiểm tra TP/SL hit
 *   Expiry: hủy nếu không khớp sau --expiry-bars nến
 */
class BacktestV4Command extends Command
{
    protected $signature = 'backtest:v4
        {--symbol=XAUUSDT       : Symbol (XAUUSDT hoặc XAGUSDT)}
        {--from=2026-05-01      : Ngày bắt đầu YYYY-MM-DD}
        {--to=                  : Ngày kết thúc (mặc định hôm nay)}
        {--capital=1000         : Vốn ban đầu (USD)}
        {--multi=7              : Hệ số nhân lot khi breakout}
        {--tp-pips=15           : TP cố định (pips)}
        {--buf-pips=3           : Buffer ngoài đỉnh/đáy (pips)}
        {--expiry-bars=16       : Hủy stop sau N nến không khớp (16 × 15m = 4h)}
        {--lookback=40          : Lookback channel detection (số nến M15)}
        {--min-compression=0.0  : Chỉ trade khi compression >= giá trị này (0.0=tất cả)}';

    protected $description = 'v4 Walk-forward backtest — channel compression breakout Gold/Silver';

    // XAUUSD: 1 pip = $0.10
    private float $pipSize   = 0.10;
    private float $pipValue  = 10.0;  // USD per pip per standard lot
    private int   $decimals  = 2;

    public function handle(BinanceService $binance, PriceActionService $pa, SignalFormatterService $fmt): int
    {
        $symbol      = strtoupper($this->option('symbol'));
        $from        = $this->option('from');
        $to          = $this->option('to') ?: now()->format('Y-m-d');
        $capital     = (float) $this->option('capital');
        $multi       = (int)   $this->option('multi');
        $tpPips      = (float) $this->option('tp-pips');
        $bufPips     = (float) $this->option('buf-pips');
        $expiryBars  = (int)   $this->option('expiry-bars');
        $lookback    = (int)   $this->option('lookback');
        $minComp     = (float) $this->option('min-compression');

        // XAGUSDT dùng pip_size khác
        if (str_contains($symbol, 'XAG')) {
            $this->pipSize  = 0.001;
            $this->pipValue = 1.0;
            $this->decimals = 3;
        }

        $fromTs = strtotime($from) * 1000;
        $toTs   = strtotime($to . ' 23:59:59') * 1000;

        $this->info("╔══════════════════════════════════════════════════╗");
        $this->info("║  FELIX v4 BACKTEST — {$symbol} M15              ║");
        $this->info("╚══════════════════════════════════════════════════╝");
        $this->info("Period : {$from} → {$to}");
        $this->info("Capital: \${$capital} | Multi: ×{$multi} | TP: {$tpPips}pip | Buf: {$bufPips}pip | Expiry: {$expiryBars}bars");
        $this->line('');

        // ── 1. Tải toàn bộ klines từ Binance ──
        $this->line("Đang tải klines {$symbol} M15...");
        $allKlines = $this->fetchHistoricalKlines($binance, $symbol, $fromTs, $toTs);

        if (count($allKlines) < $lookback + 10) {
            $this->error("Không đủ dữ liệu: " . count($allKlines) . " bars (cần ≥ " . ($lookback + 10) . ")");
            return 1;
        }
        $this->line("Tải được " . count($allKlines) . " nến M15");
        $this->line('');

        // ── 2. Walk-forward simulation ──
        $trades       = [];
        $pendingOrders = []; // active stop orders chưa khớp
        $channelDedup = []; // fingerprint kênh đã đặt lệnh

        $warmup = max(200, $lookback + 6);

        for ($i = $warmup; $i < count($allKlines); $i++) {
            $currentBar = $allKlines[$i];
            $barTime    = (int) $currentBar[0];
            $barHigh    = (float) $currentBar[2];
            $barLow     = (float) $currentBar[3];
            $barClose   = (float) $currentBar[4];

            // ── A. Kiểm tra pending orders: trigger / TP / SL / expiry ──
            foreach ($pendingOrders as $oid => &$order) {
                // Expiry check
                if ($i - $order['placed_at_bar'] > $expiryBars) {
                    $order['status'] = 'EXPIRED';
                    unset($pendingOrders[$oid]);
                    continue;
                }

                if ($order['status'] !== 'PENDING') continue;

                // Trigger check
                if (!$order['triggered']) {
                    $triggered = $order['side'] === 'BUY'
                        ? ($barHigh >= $order['entry'])
                        : ($barLow  <= $order['entry']);

                    if ($triggered) {
                        $order['triggered']      = true;
                        $order['triggered_bar']  = $i;
                        $order['triggered_time'] = $barTime;
                    } else {
                        continue;
                    }
                }

                // TP / SL check (sau khi triggered)
                if ($order['side'] === 'BUY') {
                    if ($barHigh >= $order['tp']) {
                        $order['status']   = 'WIN';
                        $order['exit_bar'] = $i;
                        $order['pnl_pips'] = $tpPips;
                        $trades[] = $order;
                        unset($pendingOrders[$oid]);
                    } elseif ($barLow <= $order['sl']) {
                        $order['status']   = 'LOSS';
                        $order['exit_bar'] = $i;
                        $order['pnl_pips'] = -$order['sl_pips'];
                        $trades[] = $order;
                        unset($pendingOrders[$oid]);
                    }
                } else {
                    if ($barLow <= $order['tp']) {
                        $order['status']   = 'WIN';
                        $order['exit_bar'] = $i;
                        $order['pnl_pips'] = $tpPips;
                        $trades[] = $order;
                        unset($pendingOrders[$oid]);
                    } elseif ($barHigh >= $order['sl']) {
                        $order['status']   = 'LOSS';
                        $order['exit_bar'] = $i;
                        $order['pnl_pips'] = -$order['sl_pips'];
                        $trades[] = $order;
                        unset($pendingOrders[$oid]);
                    }
                }
            }
            unset($order);

            // ── B. Detect channel + đặt stop orders ──
            // Chỉ scan mỗi 3 bars (≈ 45 phút) để tiết kiệm CPU và tránh over-signal
            if ($i % 3 !== 0) continue;

            $window  = array_slice($allKlines, max(0, $i - 200), 200);
            $channel = $pa->detectUnpredictableChannel($window, $lookback);

            if (!$channel['is_channel']) continue;
            if ($channel['compression'] < $minComp) continue;

            // Dedup: không đặt 2 lệnh cho cùng 1 kênh trong 16 bars
            $fingerprint = round($channel['upper'], 1) . '_' . round($channel['lower'], 1);
            if (isset($channelDedup[$fingerprint]) && $i - $channelDedup[$fingerprint] < $expiryBars) {
                continue;
            }
            $channelDedup[$fingerprint] = $i;

            $pa->calculateATR($window, 14); // warm ATR (unused in SL calc now)

            // SL = channel boundary + 5pip buffer (backtest-validated)
            $channelWidthPips = ($channel['upper'] - $channel['lower']) / $this->pipSize;
            $slBufPips        = 5;
            $slPips           = max(10, round($channelWidthPips + $slBufPips + $bufPips));

            $bufDist = $bufPips * $this->pipSize;
            $tpDist  = $tpPips  * $this->pipSize;

            // Lot sizing
            $probeLot = $capital > 0
                ? max(0.01, round(($capital * 0.005) / ($slPips * $this->pipValue), 2))
                : 0.01;
            $mainLot = round($probeLot * $multi, 2);

            // BUY STOP: SL = dưới lower - 5pip
            $buyEntry = round($channel['upper'] + $bufDist, $this->decimals);
            $buyTp    = round($buyEntry + $tpDist, $this->decimals);
            $buySl    = round($channel['lower'] - ($slBufPips * $this->pipSize), $this->decimals);

            $pendingOrders['buy_' . $i] = [
                'id'             => 'buy_' . $i,
                'side'           => 'BUY',
                'placed_at_bar'  => $i,
                'placed_at_time' => $barTime,
                'entry'          => $buyEntry,
                'tp'             => $buyTp,
                'sl'             => $buySl,
                'sl_pips'        => $slPips,
                'lots'           => $mainLot,
                'compression'    => $channel['compression'],
                'upper'          => $channel['upper'],
                'lower'          => $channel['lower'],
                'triggered'      => false,
                'status'         => 'PENDING',
                'pnl_pips'       => 0,
            ];

            // SELL STOP: SL = trên upper + 5pip
            $sellEntry = round($channel['lower'] - $bufDist, $this->decimals);
            $sellTp    = round($sellEntry - $tpDist, $this->decimals);
            $sellSl    = round($channel['upper'] + ($slBufPips * $this->pipSize), $this->decimals);

            $pendingOrders['sell_' . $i] = [
                'id'             => 'sell_' . $i,
                'side'           => 'SELL',
                'placed_at_bar'  => $i,
                'placed_at_time' => $barTime,
                'entry'          => $sellEntry,
                'tp'             => $sellTp,
                'sl'             => $sellSl,
                'sl_pips'        => $slPips,
                'lots'           => $mainLot,
                'compression'    => $channel['compression'],
                'upper'          => $channel['upper'],
                'lower'          => $channel['lower'],
                'triggered'      => false,
                'status'         => 'PENDING',
                'pnl_pips'       => 0,
            ];
        }

        // Flush pending orders còn lại → EXPIRED
        foreach ($pendingOrders as $order) {
            $order['status'] = 'EXPIRED';
            $trades[] = $order;
        }

        // ── 3. Report ──
        $this->printReport($trades, $capital, $multi, $tpPips, $symbol, $from, $to);
        return 0;
    }

    // ──────────────────────────────────────────────────────────────
    // REPORT
    // ──────────────────────────────────────────────────────────────

    private function printReport(array $trades, float $capital, int $multi, float $tpPips, string $symbol, string $from, string $to): void
    {
        $triggered = array_filter($trades, fn($t) => $t['triggered'] ?? false);
        $wins      = array_filter($triggered, fn($t) => $t['status'] === 'WIN');
        $losses    = array_filter($triggered, fn($t) => $t['status'] === 'LOSS');
        $expired   = array_filter($trades,    fn($t) => $t['status'] === 'EXPIRED');
        $placed    = count($trades);
        $nTrig     = count($triggered);
        $nWin      = count($wins);
        $nLoss     = count($losses);
        $nExpired  = count($expired);

        $winrate   = $nTrig > 0 ? round($nWin / $nTrig * 100, 1) : 0;

        // P&L tính bằng USD (dùng main lot)
        $totalPips = array_sum(array_column(iterator_to_array((function() use ($triggered) { foreach ($triggered as $t) yield $t; })(), false), 'pnl_pips'));
        $avgLot    = $nTrig > 0 ? array_sum(array_column(iterator_to_array((function() use ($triggered) { foreach ($triggered as $t) yield $t; })(), false), 'lots')) / $nTrig : 0;
        $totalUsd  = round($totalPips * $this->pipValue * $avgLot, 2);
        $returnPct = $capital > 0 ? round($totalUsd / $capital * 100, 2) : 0;

        // Win/Loss pips
        $winPips  = array_sum(array_column(array_values($wins),   'pnl_pips'));
        $lossPips = array_sum(array_column(array_values($losses),  'pnl_pips'));
        $avgWin   = $nWin   > 0 ? round($winPips  / $nWin,  1) : 0;
        $avgLoss  = $nLoss  > 0 ? round($lossPips / $nLoss, 1) : 0;

        // Expected value per trade
        $ev = $nTrig > 0
            ? round(($winrate / 100 * $tpPips) + ((1 - $winrate / 100) * $avgLoss), 2)
            : 0;

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
        $this->line(sprintf('  Channels phát hiện  : %d', $placed / 2));
        $this->line(sprintf('  Lệnh đặt (stops)    : %d  (BUY + SELL mỗi channel)', $placed));
        $this->line(sprintf('  Lệnh khớp (triggered): %d', $nTrig));
        $this->line(sprintf('  Lệnh hết hạn (expired): %d', $nExpired));
        $this->line('──────────────────────────────────────────────────');
        $wr_color = $winrate >= 50 ? 'info' : 'warn';
        $this->{$wr_color}(sprintf('  WIN    : %d  (%s%%)', $nWin, $winrate));
        $this->line(sprintf('  LOSS   : %d', $nLoss));
        $this->line(sprintf('  Avg Win : +%.1f pips | Avg Loss: %.1f pips', $avgWin, $avgLoss));
        $this->line(sprintf('  EV/trade: %+.2f pips', $ev));
        $this->line('──────────────────────────────────────────────────');

        $pnl_fn = $totalPips >= 0 ? 'info' : 'warn';
        $this->{$pnl_fn}(sprintf('  Tổng P&L : %+.1f pips  ≈  %+.2f USD  (%+.2f%%)',
            $totalPips, $totalUsd, $returnPct));
        $this->line(sprintf('  Vốn cuối : $%.2f → $%.2f', $capital, $capital + $totalUsd));
        $this->line('──────────────────────────────────────────────────');

        // Daily P&L table
        $this->line('  Daily P&L (pips):');
        foreach ($dailyPnl as $day => $pips) {
            $bar   = str_repeat($pips >= 0 ? '█' : '░', min(20, (int) abs($pips / 3)));
            $sign  = $pips >= 0 ? '+' : '';
            $color = $pips >= 0 ? 'info' : 'warn';
            $this->{$color}(sprintf('    %s  %s%+.1f', $day, $bar, $pips));
        }

        // Top 5 best + worst trades
        $trigArr = array_values($triggered);
        usort($trigArr, fn($a, $b) => $b['pnl_pips'] <=> $a['pnl_pips']);
        $this->line('──────────────────────────────────────────────────');
        $this->line('  Top 5 trades tốt nhất:');
        foreach (array_slice($trigArr, 0, 5) as $t) {
            $dt = date('m/d H:i', intdiv((int)$t['placed_at_time'], 1000));
            $this->info(sprintf('    [%s] %s @ %.2f  %+.1f pip  (comp=%.0f%%)',
                $dt, $t['side'], $t['entry'], $t['pnl_pips'], $t['compression'] * 100));
        }
        $this->line('  Top 5 trades tệ nhất:');
        foreach (array_slice(array_reverse($trigArr), 0, 5) as $t) {
            $dt = date('m/d H:i', intdiv((int)$t['placed_at_time'], 1000));
            $this->warn(sprintf('    [%s] %s @ %.2f  %+.1f pip  (comp=%.0f%%)',
                $dt, $t['side'], $t['entry'], $t['pnl_pips'], $t['compression'] * 100));
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
        $tfMs   = 15 * 60 * 1000; // 15m in ms
        $cursor = $fromMs - 200 * $tfMs; // warmup buffer

        $this->output->write("  Tải: ");
        while ($cursor < $toMs) {
            $batch = $binance->getKlines($symbol, '15m', $limit, $cursor);
            if (empty($batch)) break;

            foreach ($batch as $k) {
                if ((int)$k[0] < $fromMs - 200 * $tfMs) { $all[] = $k; continue; }
                if ((int)$k[0] > $toMs) break 2;
                $all[] = $k;
            }

            $cursor = (int) end($batch)[0] + $tfMs;
            $this->output->write(".");
            usleep(200000); // 200ms rate limit
        }
        $this->line(" " . count($all) . " bars");
        return $all;
    }
}
