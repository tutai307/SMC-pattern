<?php

namespace App\Console\Commands;

use App\Services\BinanceService;
use App\Services\PriceActionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class BacktestCommand extends Command
{
    protected $signature = 'backtest:run
        {symbol=XAGUSDT : Symbol to backtest}
        {--tf=1h : Timeframe (1h, 4h, 15m)}
        {--htf=4h : Higher timeframe for bias}
        {--from= : Start date YYYY-MM-DD (default: first day of last month)}
        {--to= : End date YYYY-MM-DD (default: last day of last month)}
        {--risk=2 : Risk per trade in USD}
        {--capital=100 : Starting capital in USD}
        {--session : Apply London/NY session filter (07-10 & 13-17 UTC)}
        {--method=smc : Analysis method (smc/elliot)}
        {--rr=2 : Risk:Reward target multiplier (e.g. 2 = 1:2, 3 = 1:3)}
        {--min-rr=1.4 : Minimum signal R:R to accept (filter weak setups)}';

    protected $description = 'Walk-forward backtest SMC/Elliott signals on historical Binance klines (no AI scoring)';

    public function handle(BinanceService $binance, PriceActionService $service): int
    {
        $symbol        = strtoupper($this->argument('symbol'));
        $tf            = $this->option('tf');
        $htf           = $this->option('htf');
        $risk          = (float) $this->option('risk');
        $capital       = (float) $this->option('capital');
        $useSession    = (bool) $this->option('session');
        $method        = $this->option('method');
        $rrTarget      = (float) $this->option('rr');
        $minRR         = (float) $this->option('min-rr');

        // Resolve date range
        [$fromTs, $toTs, $fromLabel, $toLabel] = $this->resolveDateRange(
            $this->option('from'),
            $this->option('to')
        );

        $sessionLabel = $useSession ? ' | Session: London+NY' : ' | Session: OFF';
        $methodLabel  = strtoupper($method);
        $this->info("═══════════════════════════════════════════════════");
        $this->info("  BACKTEST [{$methodLabel}] — {$symbol} {$tf}  |  {$fromLabel} → {$toLabel}");
        $this->info("  HTF bias: {$htf}  |  Risk/trade: \${$risk}  |  Capital: \${$capital}{$sessionLabel}");
        $this->info("═══════════════════════════════════════════════════");

        // ── 1. Fetch klines ──────────────────────────────────────────────
        $warmupMs     = 200 * $this->tfToMs($tf);
        $fetchFrom    = $fromTs - $warmupMs;
        $dailyWarmup  = 200 * 86_400_000; // 200 days trước test period

        $weeklyWarmup = 80 * 7 * 86_400_000; // 80 tuần trước test period (đủ cho EMA50)

        $this->line("Fetching {$symbol} {$tf} klines from Binance...");
        $klines1h      = $this->fetchKlines($symbol, $tf,   $fetchFrom, $toTs);
        $klinesHTF     = $this->fetchKlines($symbol, $htf,  $fetchFrom - $this->tfToMs($htf) * 100, $toTs);
        $klinesDaily   = $this->fetchKlines($symbol, '1d',  $fromTs - $dailyWarmup, $toTs);
        $klinesWeekly  = $this->fetchKlines($symbol, '1w',  $fromTs - $weeklyWarmup, $toTs);

        if (count($klines1h) < 210) {
            $this->error("Not enough klines fetched: " . count($klines1h));
            return 1;
        }

        $this->line("Fetched " . count($klines1h) . " {$tf} candles, " . count($klinesHTF) . " {$htf} candles, " . count($klinesDaily) . " 1d candles, " . count($klinesWeekly) . " 1w candles.");

        // ── 2. Find April start index ────────────────────────────────────
        $startIdx = 0;
        foreach ($klines1h as $i => $k) {
            if ((int)$k[0] >= $fromTs) { $startIdx = $i; break; }
        }
        if ($startIdx < 200) $startIdx = 200;

        $this->line("Walk-forward from candle #{$startIdx} (warm-up OK).\n");

        // ── 3. Walk-forward simulation ───────────────────────────────────
        $signals      = [];
        $activeSignal = null;
        $scanned      = 0;
        // OB cooldown: [entry_price => candle_index_cooldown_until]
        $obCooldown   = [];

        for ($i = $startIdx; $i < count($klines1h); $i++) {
            $candle = $klines1h[$i];
            $ts     = (int)$candle[0];

            // Stop at end of range
            if ($ts > $toTs) break;

            $high  = (float)$candle[2];
            $low   = (float)$candle[3];
            $close = (float)$candle[4];

            // ── Check active signal ──────────────────────────────────────
            if ($activeSignal) {
                $isLong = str_contains($activeSignal['type'], 'MUA');
                // Check fill first
                if (!$activeSignal['filled']) {
                    $filled = $isLong
                        ? $low  <= $activeSignal['entry']
                        : $high >= $activeSignal['entry'];

                    if ($filled) {
                        $activeSignal['filled']    = true;
                        $activeSignal['fill_time'] = $ts;
                        $activeSignal['fill_candle'] = $i;
                    } else {
                        // Not filled — check if signal expired (48 candles = 2 days for 1h)
                        $maxWait = $tf === '4h' ? 12 : ($tf === '15m' ? 192 : 48);
                        if ($i - $activeSignal['signal_candle'] > $maxWait) {
                            $activeSignal['outcome'] = 'EXPIRED';
                            $signals[]    = $activeSignal;
                            $activeSignal = null;
                        }
                        continue;
                    }
                }

                // Filled — check TP/SL
                if ($isLong) {
                    if ($low <= $activeSignal['sl']) {
                        $activeSignal['outcome']      = 'LOSS';
                        $activeSignal['close_time']   = $ts;
                        $activeSignal['close_price']  = $activeSignal['sl'];
                        // OB cooldown: 24 candles after loss (string key — PHP truncates float keys to int)
                        $obCooldown[sprintf('%.4f', $activeSignal['entry'])] = $i + 24;
                        $signals[]    = $activeSignal;
                        $activeSignal = null;
                        continue;
                    }
                    if ($high >= $activeSignal['tp']) {
                        $activeSignal['outcome']      = 'WIN';
                        $activeSignal['close_time']   = $ts;
                        $activeSignal['close_price']  = $activeSignal['tp'];
                        // OB cooldown after WIN (shorter): same OB re-entry rarely works
                        $obCooldown[sprintf('%.4f', $activeSignal['entry'])] = $i + 8;
                        $signals[]    = $activeSignal;
                        $activeSignal = null;
                        continue;
                    }
                } else {
                    if ($high >= $activeSignal['sl']) {
                        $activeSignal['outcome']      = 'LOSS';
                        $activeSignal['close_time']   = $ts;
                        $activeSignal['close_price']  = $activeSignal['sl'];
                        // OB cooldown: 24 candles after loss (string key)
                        $obCooldown[sprintf('%.4f', $activeSignal['entry'])] = $i + 24;
                        $signals[]    = $activeSignal;
                        $activeSignal = null;
                        continue;
                    }
                    if ($low <= $activeSignal['tp']) {
                        $activeSignal['outcome']      = 'WIN';
                        $activeSignal['close_time']   = $ts;
                        $activeSignal['close_price']  = $activeSignal['tp'];
                        // OB cooldown after WIN too (shorter): same OB re-entry rarely works
                        $obCooldown[sprintf('%.4f', $activeSignal['entry'])] = $i + 8;
                        $signals[]    = $activeSignal;
                        $activeSignal = null;
                        continue;
                    }
                }

                continue; // Signal still running — skip new signal generation
            }

            // ── Generate new signal ──────────────────────────────────────
            $window      = array_slice($klines1h, max(0, $i - 199), 200);
            $htfWin      = array_values(array_filter($klinesHTF,    fn($k) => (int)$k[0] <= $ts));
            $htfWin      = array_slice($htfWin, -60);
            $dailyWin    = array_values(array_filter($klinesDaily,  fn($k) => (int)$k[0] <= $ts));
            $dailyWin    = array_slice($dailyWin, -60);
            $weeklyWin   = array_values(array_filter($klinesWeekly, fn($k) => (int)$k[0] <= $ts));
            $weeklyWin   = array_slice($weeklyWin, -60);

            $result = $service->analyze($window, $htfWin, $method, $symbol, $tf, true, $dailyWin, $useSession, $weeklyWin);
            $sig    = $result['signal'] ?? null;
            $scanned++;

            if (!$sig || empty($sig['entry']) || empty($sig['tp']) || empty($sig['sl'])) continue;

            $entry = (float)$sig['entry'];
            $tp    = (float)$sig['tp'];
            $sl    = (float)$sig['sl'];
            $slDist = abs($entry - $sl);
            if ($slDist <= 0) continue;

            $rr = abs($tp - $entry) / $slDist;
            if ($rr < $minRR) continue; // R:R gate

            // OB cooldown: skip if this entry level (±1%) fired recently after a loss
            $cooledDown = false;
            foreach ($obCooldown as $levelStr => $untilCandle) {
                $level = (float)$levelStr;
                if ($i < $untilCandle && $level > 0 && abs($level - $entry) / $level < 0.01) {
                    $cooledDown = true;
                    break;
                }
            }
            if ($cooledDown) continue;

            $activeSignal = [
                'id'           => count($signals) + 1,
                'type'         => $sig['type'],
                'label'        => $sig['label'] ?? '?',
                'entry'        => $entry,
                'tp'           => $tp,
                'sl'           => $sl,
                'rr'           => round($rr, 2),
                'signal_time'  => $ts,
                'signal_candle'=> $i,
                'filled'       => false,
                'fill_time'    => null,
                'fill_candle'  => null,
                'outcome'      => 'PENDING',
                'close_time'   => null,
                'close_price'  => null,
            ];
        }

        // Close any still-open signal
        if ($activeSignal) {
            $activeSignal['outcome'] = 'OPEN';
            $signals[] = $activeSignal;
        }

        // ── 4. Report ────────────────────────────────────────────────────
        if (empty($signals)) {
            $this->warn("No signals generated in this period.");
            $this->line("Candles scanned: {$scanned}");
            return 0;
        }

        $this->line(str_pad('#', 3) . str_pad('TYPE', 7) . str_pad('LABEL', 14)
            . str_pad('SIGNAL', 14) . str_pad('FILL', 14)
            . str_pad('ENTRY', 10) . str_pad('TP', 10) . str_pad('SL', 10)
            . str_pad('R:R', 6) . 'RESULT');
        $this->line(str_repeat('─', 100));

        foreach ($signals as $s) {
            $sigDate  = date('d/m H:i', (int)($s['signal_time'] / 1000));
            $fillDate = $s['fill_time'] ? date('d/m H:i', (int)($s['fill_time'] / 1000)) : 'NOT FILLED';
            $resultColor = match($s['outcome']) {
                'WIN'     => "<fg=green>{$s['outcome']}</>",
                'LOSS'    => "<fg=red>{$s['outcome']}</>",
                'EXPIRED' => "<fg=yellow>EXPIRED</>",
                'OPEN'    => "<fg=cyan>OPEN</>",
                default   => $s['outcome'],
            };

            $this->line(
                str_pad($s['id'], 3)
                . str_pad($s['type'], 7)
                . str_pad(substr($s['label'], 0, 13), 14)
                . str_pad($sigDate, 14)
                . str_pad($fillDate, 14)
                . str_pad(number_format($s['entry'], 3), 10)
                . str_pad(number_format($s['tp'], 3), 10)
                . str_pad(number_format($s['sl'], 3), 10)
                . str_pad($s['rr'], 6)
                . $resultColor
            );
        }

        // ── 5. Stats ─────────────────────────────────────────────────────
        $wins     = array_filter($signals, fn($s) => $s['outcome'] === 'WIN');
        $losses   = array_filter($signals, fn($s) => $s['outcome'] === 'LOSS');
        $expired  = array_filter($signals, fn($s) => $s['outcome'] === 'EXPIRED');
        $open     = array_filter($signals, fn($s) => $s['outcome'] === 'OPEN');
        $filled   = array_filter($signals, fn($s) => $s['filled']);
        $closed   = count($wins) + count($losses);
        $wr       = $closed > 0 ? round(count($wins) / $closed * 100, 1) : 0;

        // P&L: risk = $risk/trade, R:R 1:$rrTarget → win = $risk*$rrTarget, loss = -$risk
        $pnl = 0;
        foreach ($signals as $s) {
            if ($s['outcome'] === 'WIN')  $pnl += $risk * $rrTarget;
            if ($s['outcome'] === 'LOSS') $pnl -= $risk;
        }

        $fillRate = count($signals) > 0
            ? round(count($filled) / count($signals) * 100, 1) : 0;

        $this->line("\n" . str_repeat('═', 55));
        $this->line("  TỔNG KẾT");
        $this->line(str_repeat('─', 55));
        $this->line("  Tín hiệu tạo:  " . count($signals));
        $this->line("  Fill rate:      {$fillRate}%  (" . count($filled) . "/" . count($signals) . " filled)");
        $this->line("  WIN:            " . count($wins));
        $this->line("  LOSS:           " . count($losses));
        $this->line("  EXPIRED:        " . count($expired) . "  (chưa fill trong thời hạn)");
        $this->line("  OPEN (end):     " . count($open));
        $this->line("  Winrate:        <fg=" . ($wr >= 50 ? 'green' : 'red') . ">{$wr}%</> ({$closed} closed)");
        $pnlColor = $pnl >= 0 ? 'green' : 'red';
        $pnlSign  = $pnl >= 0 ? '+' : '';
        $this->line("  P&L (\${$capital}, {$risk}\$/trade, 1:{$rrTarget}): <fg={$pnlColor}>{$pnlSign}" . number_format($pnl, 2) . " USD</>");
        $this->line("  Capital cuối:   " . number_format($capital + $pnl, 2) . " USD");
        $this->line(str_repeat('═', 55));

        return 0;
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function resolveDateRange(?string $from, ?string $to): array
    {
        if ($from && $to) {
            $fromTs = strtotime($from . ' 00:00:00 UTC') * 1000;
            $toTs   = strtotime($to   . ' 23:59:59 UTC') * 1000;
            return [$fromTs, $toTs, $from, $to];
        }

        // Default: previous calendar month
        $firstDay = strtotime('first day of last month 00:00:00 UTC');
        $lastDay  = strtotime('last day of last month  23:59:59 UTC');
        return [
            $firstDay * 1000,
            $lastDay  * 1000,
            date('Y-m-d', $firstDay),
            date('Y-m-d', $lastDay),
        ];
    }

    private function tfToMs(string $tf): int
    {
        return match($tf) {
            '1m'  =>      60_000,
            '3m'  =>     180_000,
            '5m'  =>     300_000,
            '15m' =>     900_000,
            '30m' =>   1_800_000,
            '1h'  =>   3_600_000,
            '2h'  =>   7_200_000,
            '4h'  =>  14_400_000,
            '1d'  =>  86_400_000,
            default => 3_600_000,
        };
    }

    private function fetchKlines(string $symbol, string $tf, int $startMs, int $endMs): array
    {
        $all    = [];
        $cursor = $startMs;
        $base   = 'https://fapi.binance.com/fapi/v1';

        while ($cursor < $endMs) {
            $resp = Http::timeout(15)->get("{$base}/klines", [
                'symbol'    => $symbol,
                'interval'  => $tf,
                'startTime' => $cursor,
                'endTime'   => $endMs,
                'limit'     => 1500,
            ]);

            if (!$resp->successful()) {
                $this->error("Binance API error: " . $resp->body());
                break;
            }

            $batch = $resp->json();
            if (empty($batch)) break;

            $all    = array_merge($all, $batch);
            $cursor = (int)end($batch)[0] + $this->tfToMs($tf);
        }

        return $all;
    }
}
