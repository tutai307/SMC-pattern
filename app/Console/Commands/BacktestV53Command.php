<?php

namespace App\Console\Commands;

use App\Services\BinanceService;
use App\Services\PriceActionService;
use App\Services\SignalFormatterService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Backtest v5.3 — Felix Gold Scanner (No AI, 4-Channel Geometry)
 *
 * Dữ liệu: MT5 historical klines, import trước bằng FelixBulkExporter.mq5
 *   → POST /api/mt5/bulk-klines (stored as mt5_bulk_{SYMBOL}_{TF})
 *
 * Logic:
 *   Walk-forward mỗi M15 bar → detectUnpredictableChannel() + getHTFBias()
 *   → buildSignals() → track TP/SL trên các bar tiếp theo
 *
 * Chạy:
 *   php artisan backtest:v53 --capital=100
 *   php artisan backtest:v53 --symbol=XAUUSDT --capital=500 --expiry-bars=16
 */
class BacktestV53Command extends Command
{
    protected $signature = 'backtest:v53
        {--symbol=XAUUSDT     : Symbol (phải khớp với key mt5_bulk_*)}
        {--from=              : Lọc window đặt lệnh từ ngày (YYYY-MM-DD, HCM TZ)}
        {--to=                : Lọc window đặt lệnh đến ngày (YYYY-MM-DD, HCM TZ)}
        {--seed               : Tự fetch data từ Binance Futures nếu cache trống}
        {--capital=100        : Vốn USD — dùng để tính lot 2% cố định}
        {--expiry-bars=16     : Hủy lệnh pending sau N nến M15 không khớp}
        {--lookback=100       : Lookback channel detection (nến M15)}
        {--htf-lookback=50    : Lookback H4 cho HTF bias}
        {--dedup-bars=32      : Dedup: cùng kênh không trade lại trong N bars}
        {--scan-every=1       : Quét channel mỗi N bars (1=mỗi bar, 3=mỗi 45p)}';

    protected $description = 'Backtest v5.3 — 4-kênh hình học, không AI, dữ liệu từ MT5';

    public function handle(
        PriceActionService    $pa,
        SignalFormatterService $sf,
        BinanceService        $binance,
    ): int {
        $symbol      = strtoupper($this->option('symbol'));
        $capital     = (float) $this->option('capital');
        $expiryBars  = (int)   $this->option('expiry-bars');
        $lookback    = (int)   $this->option('lookback');
        $htfLookback = (int)   $this->option('htf-lookback');
        $dedupBars   = (int)   $this->option('dedup-bars');
        $scanEvery   = (int)   $this->option('scan-every');

        $m15Key = "mt5_bulk_{$symbol}_15m";
        $h4Key  = "mt5_bulk_{$symbol}_4h";

        // ── Seed từ Binance nếu cache trống ───────────────────────
        if ($this->option('seed') && count(Cache::get($m15Key, [])) < $lookback + 20) {
            $this->seedFromBinance($binance, $symbol, $m15Key, $h4Key);
        }

        // ── Load M15 data ──────────────────────────────────────────
        $m15Klines = Cache::get($m15Key, []);
        if (count($m15Klines) < $lookback + 20) {
            $this->error("Chưa có data M15 (key: {$m15Key}, bars: " . count($m15Klines) . ")");
            $this->line("Dùng --seed để tự fetch, hoặc chạy FelixBulkExporter.mq5 trên MT5.");
            return 1;
        }

        // ── Window báo cáo (--from / --to) — chỉ đặt lệnh trong range ──
        // Toàn bộ M15 data vẫn dùng cho lookback/warmup, không cắt bỏ.
        $fromOpt    = $this->option('from');
        $toOpt      = $this->option('to');
        // strtotime không dùng UTC suffix — dùng server TZ (Asia/Ho_Chi_Minh)
        // để khớp với date display trong báo cáo (HCM local time)
        $reportFrom = $fromOpt ? strtotime($fromOpt . ' 00:00:00') * 1000 : null;
        $reportTo   = $toOpt   ? strtotime($toOpt   . ' 23:59:59') * 1000 : null;

        // ── Load H4 data ───────────────────────────────────────────
        $h4Klines = Cache::get($h4Key, []);
        $hasH4    = count($h4Klines) >= $htfLookback;

        $dateFrom = $fromOpt ?? date('Y-m-d', intdiv((int)$m15Klines[0][0], 1000));
        $dateTo   = $toOpt   ?? date('Y-m-d', intdiv((int)end($m15Klines)[0], 1000));

        $this->info('╔══════════════════════════════════════════════════════╗');
        $this->info("║  FELIX v5.3 BACKTEST — {$symbol} M15                 ║");
        $this->info('╚══════════════════════════════════════════════════════╝');
        $this->line("Period    : {$dateFrom} → {$dateTo}");
        $this->line("M15 bars  : " . count($m15Klines) . "  |  H4 bars: " . count($h4Klines) . ($hasH4 ? '' : ' (thiếu — HTF filter OFF)'));
        $this->line("Capital   : \${$capital}  |  Lot: 2% cố định (min 0.01)");
        $this->line("Expiry    : {$expiryBars} bars = " . ($expiryBars * 15) . " phút");
        $this->line('');

        // ── Walk-forward simulation ────────────────────────────────
        $trades        = [];       // closed trades
        $pendingOrders = [];       // open pending orders
        $channelDedup  = [];       // [fingerprint => barIdx last placed]
        $warmup        = max(200, $lookback + 6);
        $n             = count($m15Klines);

        for ($i = $warmup; $i < $n; $i++) {
            $bar      = $m15Klines[$i];
            $barTs    = (int)   $bar[0];
            $barHigh  = (float) $bar[2];
            $barLow   = (float) $bar[3];
            $barClose = (float) $bar[4];

            // ── A. Resolve pending orders ──────────────────────────
            $resolvedOcoGroups = [];
            foreach ($pendingOrders as $oid => &$order) {
                // Expiry
                if ($i - $order['placed_bar'] > $expiryBars) {
                    $order['status'] = 'EXPIRED';
                    $trades[]        = $order;
                    unset($pendingOrders[$oid]);
                    continue;
                }

                // OCO: nếu đối tác đã triggered, cancel lệnh này
                $ocoGroup = $order['oco_group'] ?? null;
                if ($ocoGroup && isset($resolvedOcoGroups[$ocoGroup])) {
                    $order['status'] = 'CANCELLED';
                    unset($pendingOrders[$oid]);
                    continue;
                }

                // Check trigger
                if (!$order['triggered']) {
                    $triggered = match ($order['side']) {
                        'BUY_STOP', 'SELL_LIMIT' => $barHigh >= $order['entry'],
                        'BUY_LIMIT', 'SELL_STOP' => $barLow  <= $order['entry'],
                        default                  => false,
                    };
                    if ($triggered) {
                        $order['triggered']      = true;
                        $order['triggered_bar']  = $i;
                        $order['triggered_ts']   = $barTs;
                        if ($ocoGroup) $resolvedOcoGroups[$ocoGroup] = true;
                    }
                }

                if (!$order['triggered']) continue;

                // Check TP/SL
                $isBuy = in_array($order['side'], ['BUY_STOP', 'BUY_LIMIT']);
                if ($isBuy) {
                    if ($barHigh >= $order['tp']) {
                        $order['status'] = 'WIN';
                        $order['pnl_gia']  = $order['tp_gia'];
                        $order['pnl_usd']  = round($order['tp_gia'] * 100 * $order['lot'], 2);
                        $trades[] = $order; unset($pendingOrders[$oid]);
                    } elseif ($barLow <= $order['sl']) {
                        $order['status'] = 'LOSS';
                        $order['pnl_gia']  = -$order['sl_gia'];
                        $order['pnl_usd']  = round(-$order['sl_gia'] * 100 * $order['lot'], 2);
                        $trades[] = $order; unset($pendingOrders[$oid]);
                    }
                } else {
                    if ($barLow <= $order['tp']) {
                        $order['status'] = 'WIN';
                        $order['pnl_gia']  = $order['tp_gia'];
                        $order['pnl_usd']  = round($order['tp_gia'] * 100 * $order['lot'], 2);
                        $trades[] = $order; unset($pendingOrders[$oid]);
                    } elseif ($barHigh >= $order['sl']) {
                        $order['status'] = 'LOSS';
                        $order['pnl_gia']  = -$order['sl_gia'];
                        $order['pnl_usd']  = round(-$order['sl_gia'] * 100 * $order['lot'], 2);
                        $trades[] = $order; unset($pendingOrders[$oid]);
                    }
                }
            }
            unset($order);

            // ── B. Skip non-scan bars ──────────────────────────────
            if ($i % $scanEvery !== 0) continue;

            // ── B2. Time filter — phiên Âu+Mỹ 14h-23h HCM (7h-16h UTC) ──
            $barHourUtc = (int) gmdate('H', intdiv($barTs, 1000));
            if ($barHourUtc < 7 || $barHourUtc >= 16) continue;

            // ── C. Channel detection ───────────────────────────────
            // detectUnpredictableChannel cần count >= lookback+6
            $winSize = $lookback + 6;
            $window  = array_slice($m15Klines, max(0, $i + 1 - $winSize), $winSize);
            if (count($window) < $winSize) continue;

            $channel     = $pa->detectUnpredictableChannel($window, $lookback);
            $channelType = $channel['type'] ?? 'none';

            if (!$channel['is_channel']) continue;

            // ── D. HTF bias ────────────────────────────────────────
            $htfBias = null;
            if ($hasH4) {
                $h4Window = array_values(array_filter(
                    $h4Klines, fn($k) => (int)$k[0] <= $barTs
                ));
                $h4Slice = array_slice($h4Window, -$htfLookback);
                if (count($h4Slice) >= 10) {
                    $htfBias = $pa->getHTFBias($h4Slice);
                }
            }

            $channelDir = $channel['direction'] ?? null;

            // ── E. HTF alignment filter ────────────────────────────
            if ($htfBias !== null && $channelDir !== null && $htfBias !== $channelDir) {
                continue;
            }

            // ── F. Proximity check — giá phải sát biên kênh ≤ 2.0 giá
            if ($channelType === 'descending' && $barClose < $channel['upper'] - 2.0) continue;
            if ($channelType === 'ascending'  && $barClose > $channel['lower'] + 2.0) continue;

            // ── G. ATR + build signals ─────────────────────────────
            $atr     = $pa->calculateATR($window, 14);
            $signals = $sf->buildSignals($symbol, $channel, $capital, $atr);
            if ($signals === null) continue; // R:R < 1:1

            // ── H. Report window gate — chỉ đặt lệnh trong from/to ──
            if ($reportFrom && $barTs < $reportFrom) continue;
            if ($reportTo   && $barTs > $reportTo)   continue;

            // ── H2. Dedup ──────────────────────────────────────────
            $fp = $channelType . '_' . round($channel['upper'], 0) . '_' . round($channel['lower'], 0);
            if (isset($channelDedup[$fp]) && $i - $channelDedup[$fp] < $dedupBars) continue;
            $channelDedup[$fp] = $i;

            // ── I. Lot sizing (2% rule, min 0.01) ─────────────────
            $slGia    = $signals['sl_gia'];
            $tpGia    = $signals['tp_gia'];
            $rawLot = $capital > 0 && $slGia > 0
                ? ($capital * 0.02) / ($slGia * 100.0)
                : 0.0;
            if ($rawLot < 0.01) continue; // Layer 2 Hard Stop — HỦY lệnh, không clamp
            $lot = round($rawLot, 2);

            $ocoGroup = count($signals['orders']) > 1
                ? "oco_{$i}"
                : null;

            foreach ($signals['orders'] as $order) {
                $pendingOrders[$order['side'] . '_' . $i] = [
                    'id'          => $order['side'] . '_' . $i,
                    'side'        => $order['side'],
                    'entry'       => $order['entry'],
                    'tp'          => $order['tp'],
                    'sl'          => $order['sl'],
                    'sl_gia'      => $slGia,
                    'tp_gia'      => $tpGia,
                    'lot'         => $lot,
                    'placed_bar'  => $i,
                    'placed_ts'   => $barTs,
                    'channel_type'=> $channelType,
                    'htf_bias'    => $htfBias,
                    'atr'         => round($atr, 2),
                    'upper'       => $channel['upper'],
                    'lower'       => $channel['lower'],
                    'rr'          => $signals['rr'],
                    'oco_group'   => $ocoGroup,
                    'triggered'   => false,
                    'triggered_bar'=> null,
                    'triggered_ts' => null,
                    'status'      => 'PENDING',
                    'pnl_gia'     => 0.0,
                    'pnl_usd'     => 0.0,
                ];
            }
        }

        // ── Đóng tất cả lệnh còn pending → EXPIRED ──────────────
        foreach ($pendingOrders as $order) {
            $order['status'] = 'EXPIRED';
            $trades[] = $order;
        }

        $this->printReport($trades, $capital, $symbol, $dateFrom, $dateTo);
        return 0;
    }

    // ──────────────────────────────────────────────────────────────
    // SEED FROM BINANCE (fallback khi không có MT5 data)
    // ──────────────────────────────────────────────────────────────

    private function seedFromBinance(
        BinanceService $binance,
        string $symbol,
        string $m15Key,
        string $h4Key,
    ): void {
        $from = $this->option('from');
        $to   = $this->option('to');
        $this->line("Fetching {$symbol} từ Binance Futures ({$from} → {$to})...");

        $fromMs = strtotime($from) * 1000;
        // warmup 200 bars × 15m = 3000 phút = 2.08 ngày
        $warmupMs = 200 * 15 * 60 * 1000;
        $toMs     = strtotime($to . ' 23:59:59') * 1000;

        foreach ([
            ['interval' => '15m', 'key' => $m15Key, 'tfMs' => 15 * 60 * 1000],
            ['interval' => '4h',  'key' => $h4Key,  'tfMs' => 4 * 60 * 60 * 1000],
        ] as $tf) {
            $all    = [];
            $cursor = $fromMs - $warmupMs;
            $this->output->write("  [{$tf['interval']}] Tải: ");
            while ($cursor < $toMs) {
                $batch = $binance->getKlines($symbol, $tf['interval'], 1000, $cursor);
                if (empty($batch)) break;
                foreach ($batch as $k) {
                    if ((int)$k[0] > $toMs) break 2;
                    $all[] = $k;
                }
                $cursor = (int) end($batch)[0] + $tf['tfMs'];
                $this->output->write('.');
                usleep(180000);
            }
            $this->line(' ' . count($all) . ' bars');
            if (!empty($all)) {
                Cache::put($tf['key'], $all, now()->addDays(7));
            }
        }
    }

    // ──────────────────────────────────────────────────────────────
    // REPORT
    // ──────────────────────────────────────────────────────────────

    private function printReport(
        array  $trades,
        float  $capital,
        string $symbol,
        string $dateFrom,
        string $dateTo,
    ): void {
        $triggered = array_values(array_filter($trades, fn($t) => $t['triggered'] ?? false));
        $expired   = array_filter($trades, fn($t) => $t['status'] === 'EXPIRED');
        $cancelled = array_filter($trades, fn($t) => $t['status'] === 'CANCELLED');

        $wins   = array_filter($triggered, fn($t) => $t['status'] === 'WIN');
        $losses = array_filter($triggered, fn($t) => $t['status'] === 'LOSS');
        $nT     = count($triggered);
        $nW     = count($wins);
        $nL     = count($losses);

        $winrate = $nT > 0 ? round($nW / $nT * 100, 1) : 0;

        $totalGia = array_sum(array_column($triggered, 'pnl_gia'));
        $totalUsd = array_sum(array_column($triggered, 'pnl_usd'));
        $totalUsd = round($totalUsd, 2);

        // Equity curve + max drawdown
        $equity   = $capital;
        $peak     = $capital;
        $maxDD    = 0.0;
        $equityCurve = [];
        foreach ($triggered as $t) {
            $equity += $t['pnl_usd'];
            $peak   = max($peak, $equity);
            $dd     = $peak > 0 ? ($peak - $equity) / $peak * 100 : 0;
            $maxDD  = max($maxDD, $dd);
            $equityCurve[] = $equity;
        }

        // By channel type
        $typeStats = function(string $type) use ($triggered): array {
            $g  = array_values(array_filter($triggered, fn($t) => $t['channel_type'] === $type));
            $n  = count($g);
            $nW = count(array_filter($g, fn($t) => $t['status'] === 'WIN'));
            return [
                'n'   => $n,
                'wr'  => $n > 0 ? round($nW / $n * 100, 1) : 0,
                'gia' => round(array_sum(array_column($g, 'pnl_gia')), 2),
                'usd' => round(array_sum(array_column($g, 'pnl_usd')), 2),
            ];
        };
        $tDesc  = $typeStats('descending');
        $tAsc   = $typeStats('ascending');
        $tTri   = $typeStats('triangle');

        // Daily P&L
        $dailyUsd = [];
        foreach ($triggered as $t) {
            $day = date('m/d', intdiv((int)$t['placed_ts'], 1000));
            $dailyUsd[$day] = round(($dailyUsd[$day] ?? 0) + $t['pnl_usd'], 2);
        }
        ksort($dailyUsd);

        // Min capital note
        $minCapRequired = 0;
        foreach ($triggered as $t) {
            $needed = (int) ceil(0.01 * $t['sl_gia'] * 100 / 0.02);
            $minCapRequired = max($minCapRequired, $needed);
        }

        $this->line('');
        $this->info('══════════════════════════════════════════════════════');
        $this->info("  KẾT QUẢ BACKTEST v5.3 — {$symbol} M15  ({$dateFrom} → {$dateTo})");
        $this->info('══════════════════════════════════════════════════════');
        $this->line(sprintf('  Lệnh đặt    : %d  (expired: %d, cancelled OCO: %d)',
            count($trades), count($expired), count($cancelled)));
        $this->line(sprintf('  Lệnh khớp   : %d  (WIN: %d  LOSS: %d)',
            $nT, $nW, $nL));
        $this->line('──────────────────────────────────────────────────────');

        $wrFmt = fn($wr) => $wr >= 50 ? fn($s) => $this->info($s) : fn($s) => $this->warn($s);
        ($wrFmt($winrate))(sprintf('  Win Rate    : %.1f%%  (%d/%d)', $winrate, $nW, $nT));

        $this->line(sprintf('  P&L (giá)   : %+.2f giá', $totalGia));

        $pnlFmt = $totalUsd >= 0 ? fn($s) => $this->info($s) : fn($s) => $this->warn($s);
        $pnlFmt(sprintf('  P&L (USD)   : %+.2f USD  (vốn: $%.0f → $%.2f)',
            $totalUsd, $capital, $capital + $totalUsd));

        $this->line(sprintf('  Max Drawdown: %.1f%%', $maxDD));
        $this->line('──────────────────────────────────────────────────────');

        // By channel type
        $this->info('  PHÂN TÍCH THEO LOẠI KÊNH:');
        if ($tDesc['n'] > 0)
            $this->line(sprintf('  [DESCENDING]  %d lệnh  WR %.1f%%  %+.2f giá  %+.2f USD',
                $tDesc['n'], $tDesc['wr'], $tDesc['gia'], $tDesc['usd']));
        if ($tAsc['n'] > 0)
            $this->line(sprintf('  [ASCENDING ]  %d lệnh  WR %.1f%%  %+.2f giá  %+.2f USD',
                $tAsc['n'], $tAsc['wr'], $tAsc['gia'], $tAsc['usd']));
        if ($tTri['n'] > 0)
            $this->line(sprintf('  [TRIANGLE  ]  %d lệnh  WR %.1f%%  %+.2f giá  %+.2f USD',
                $tTri['n'], $tTri['wr'], $tTri['gia'], $tTri['usd']));

        // Capital note — min capital for lot >= 0.01
        if ($minCapRequired > $capital) {
            $this->line('──────────────────────────────────────────────────────');
            $this->warn(sprintf(
                '  ⚠  Vốn tối thiểu để giữ đúng 2%% risk: ~$%d (hiện tại: $%.0f)',
                $minCapRequired, $capital
            ));
        }

        $this->line('──────────────────────────────────────────────────────');
        $this->line('  Daily P&L (USD):');
        foreach ($dailyUsd as $day => $usd) {
            $bar   = str_repeat($usd >= 0 ? '█' : '░', min(20, max(1, (int) abs($usd / 0.5))));
            $color = $usd >= 0 ? 'info' : 'warn';
            $this->{$color}(sprintf('    %s  %s  %+.2f USD', $day, $bar, $usd));
        }

        // Top 5 best / worst
        usort($triggered, fn($a, $b) => $b['pnl_usd'] <=> $a['pnl_usd']);
        $this->line('──────────────────────────────────────────────────────');
        $this->line('  Top 5 lệnh tốt nhất:');
        foreach (array_slice($triggered, 0, 5) as $t) {
            $dt = date('m/d H:i', intdiv((int)$t['placed_ts'], 1000));
            $this->info(sprintf('    [%s] %-10s %s @ %.2f  TP:%.2f  SL:%.2f  %+.2f USD',
                $dt, $t['channel_type'], $t['side'], $t['entry'],
                $t['tp'], $t['sl'], $t['pnl_usd']));
        }
        $this->line('  Top 5 lệnh tệ nhất:');
        foreach (array_slice(array_reverse($triggered), 0, 5) as $t) {
            $dt = date('m/d H:i', intdiv((int)$t['placed_ts'], 1000));
            $this->warn(sprintf('    [%s] %-10s %s @ %.2f  TP:%.2f  SL:%.2f  %+.2f USD',
                $dt, $t['channel_type'], $t['side'], $t['entry'],
                $t['tp'], $t['sl'], $t['pnl_usd']));
        }
        $this->info('══════════════════════════════════════════════════════');
    }
}
