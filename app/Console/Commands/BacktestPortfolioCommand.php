<?php

namespace App\Console\Commands;

use App\Services\BinanceService;
use App\Services\PriceActionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class BacktestPortfolioCommand extends Command
{
    protected $signature = 'backtest:portfolio
        {--symbols=ETHUSDT,SOLUSDT,LINKUSDT,XAGUSDT,BTCUSDT : Comma-separated symbols}
        {--tf=15m : Timeframe}
        {--htf=1h : Higher timeframe for bias}
        {--from= : Start date YYYY-MM-DD (default: 2026-01-01)}
        {--to= : End date YYYY-MM-DD (default: today)}
        {--capital=100 : Starting capital in USD}
        {--risk=2 : Base risk per trade in USD}
        {--risk-high=8 : Risk per trade when AI score >= ai-high}
        {--ai-high=85 : AI score threshold for high risk}
        {--rr=2.5 : Risk:Reward target multiplier}
        {--adx=25 : Minimum ADX threshold}
        {--vision : Use Binance Vision data}';

    protected $description = 'Portfolio backtest — multiple symbols sharing one capital pool, trades merged by time';

    public function handle(BinanceService $binance, PriceActionService $service): int
    {
        $symbolsRaw = $this->option('symbols');
        $symbols    = array_map('strtoupper', array_map('trim', explode(',', $symbolsRaw)));
        $tf         = $this->option('tf');
        $htf        = $this->option('htf');
        $capital    = (float) $this->option('capital');
        $risk       = (float) $this->option('risk');
        $riskHigh   = (float) $this->option('risk-high');
        $aiHigh     = (int)   $this->option('ai-high');
        $rrTarget   = (float) $this->option('rr');
        $adxThresh  = (int)   $this->option('adx');
        $useVision  = (bool)  $this->option('vision');

        // Config cứng
        $overrideTp  = true;
        $localScore  = true;
        $aiRisk      = true;
        $minRR       = 1.4;
        $minConf     = 60;
        $structExitSymbols = ['SOLUSDT'];

        [$fromTs, $toTs, $fromLabel, $toLabel] = $this->resolveDateRange(
            $this->option('from'),
            $this->option('to')
        );

        $symbolList = implode(', ', $symbols);
        $this->line(str_repeat('═', 60));
        $this->line('  PORTFOLIO BACKTEST — ' . $fromLabel . ' → ' . $toLabel);
        $this->line('  Symbols: ' . $symbolList);
        $this->line('  Capital: $' . $capital . '  |  Risk: $' . $risk . '/$' . $riskHigh . ' (AI>=' . $aiHigh . ')  |  RR: 1:' . $rrTarget);
        $this->line(str_repeat('═', 60));

        $service->setThresholds($adxThresh, $minConf);

        $allSignals  = [];
        $perPairStat = [];

        foreach ($symbols as $symbol) {
            $useStructExit = in_array($symbol, $structExitSymbols);

            $this->line("\n  Fetching {$symbol}...");

            $warmupMs    = 200 * $this->tfToMs($tf);
            $fetchFrom   = $fromTs - $warmupMs;
            $dailyWarmup = 200 * 86_400_000;
            $weeklyWarmup = 80 * 7 * 86_400_000;

            if ($useVision) {
                $klines    = $binance->getVisionKlines($symbol, $tf,  $fromLabel, $toLabel);
                $klinesHTF = $binance->getVisionKlines($symbol, $htf, $fromLabel, $toLabel);
                $klinesD   = $binance->getVisionKlines($symbol, '1d', $fromLabel, $toLabel);
                $klinesW   = $binance->getVisionKlines($symbol, '1w', $fromLabel, $toLabel);
                $klines    = array_values($klines);
                $klinesHTF = array_values($klinesHTF);
                $klinesD   = array_values($klinesD);
                $klinesW   = array_values($klinesW);
            } else {
                $klines    = $this->fetchKlines($symbol, $tf,  $fetchFrom, $toTs);
                $klinesHTF = $this->fetchKlines($symbol, $htf, $fetchFrom - $this->tfToMs($htf) * 100, $toTs);
                $klinesD   = $this->fetchKlines($symbol, '1d', $fromTs - $dailyWarmup, $toTs);
                $klinesW   = $this->fetchKlines($symbol, '1w', $fromTs - $weeklyWarmup, $toTs);
            }

            $this->line('  ' . count($klines) . " {$tf} candles, " . count($klinesHTF) . " {$htf} candles");

            if (count($klines) < 210) {
                $this->warn("  Not enough klines for {$symbol}, skipping.");
                continue;
            }

            // Find start index
            $startIdx = 0;
            foreach ($klines as $i => $k) {
                if ((int)$k[0] >= $fromTs) { $startIdx = $i; break; }
            }
            if ($startIdx < 200) $startIdx = 200;

            // Walk-forward
            $obCooldown   = [];
            $activeSignal = null;
            $pairSignals  = [];

            for ($i = $startIdx; $i < count($klines); $i++) {
                $candle = $klines[$i];
                $ts     = (int)$candle[0];

                if ($ts > $toTs) break;

                $high  = (float)$candle[2];
                $low   = (float)$candle[3];
                $close = (float)$candle[4];

                if ($activeSignal) {
                    $isLong = str_contains($activeSignal['type'], 'MUA');

                    // Check fill
                    if (!$activeSignal['filled']) {
                        $filled = $isLong
                            ? $low  <= $activeSignal['entry']
                            : $high >= $activeSignal['entry'];

                        if ($filled) {
                            $activeSignal['filled']      = true;
                            $activeSignal['fill_time']   = $ts;
                            $activeSignal['fill_candle'] = $i;
                        } else {
                            $maxWait = $tf === '4h' ? 12 : ($tf === '15m' ? 192 : 48);
                            if ($i - $activeSignal['signal_candle'] > $maxWait) {
                                $activeSignal['outcome']   = 'EXPIRED';
                                $activeSignal['exit_pnl']  = 0.0;
                                $pairSignals[]  = $activeSignal;
                                $activeSignal   = null;
                            }
                            continue;
                        }
                    }

                    // Struct-exit (only for SOLUSDT)
                    if ($useStructExit && ($i - $activeSignal['fill_candle']) % 4 === 0) {
                        $structWin = array_slice($klines, max(0, $i - 49), 50);
                        $struct    = $service->getStructure($structWin);
                        $trend     = $struct['trend'] ?? 'không rõ';
                        $flipLong  = $isLong  && $trend === 'GIẢM GIÁ';
                        $flipShort = !$isLong && $trend === 'TĂNG GIÁ';
                        if ($flipLong || $flipShort) {
                            $slDist  = abs($activeSignal['entry'] - $activeSignal['sl']);
                            $pxDiff  = $isLong
                                ? ($close - $activeSignal['entry'])
                                : ($activeSignal['entry'] - $close);
                            $exitPnl = $slDist > 0
                                ? round($pxDiff / $slDist * $activeSignal['trade_risk'], 2)
                                : 0.0;
                            $activeSignal['outcome']    = 'STRUCT_EXIT';
                            $activeSignal['close_time'] = $ts;
                            $activeSignal['exit_pnl']   = $exitPnl;
                            $pairSignals[]  = $activeSignal;
                            $activeSignal   = null;
                            continue;
                        }
                    }

                    // TP / SL
                    if ($isLong) {
                        if ($low <= $activeSignal['sl']) {
                            $activeSignal['outcome']    = 'LOSS';
                            $activeSignal['close_time'] = $ts;
                            $activeSignal['exit_pnl']   = -$activeSignal['trade_risk'];
                            $obCooldown[sprintf('%.4f', $activeSignal['entry'])] = $i + 24;
                            $pairSignals[]  = $activeSignal;
                            $activeSignal   = null;
                            continue;
                        }
                        if ($high >= $activeSignal['tp']) {
                            $activeSignal['outcome']    = 'WIN';
                            $activeSignal['close_time'] = $ts;
                            $activeSignal['exit_pnl']   = round($activeSignal['trade_risk'] * $rrTarget, 2);
                            $obCooldown[sprintf('%.4f', $activeSignal['entry'])] = $i + 8;
                            $pairSignals[]  = $activeSignal;
                            $activeSignal   = null;
                            continue;
                        }
                    } else {
                        if ($high >= $activeSignal['sl']) {
                            $activeSignal['outcome']    = 'LOSS';
                            $activeSignal['close_time'] = $ts;
                            $activeSignal['exit_pnl']   = -$activeSignal['trade_risk'];
                            $obCooldown[sprintf('%.4f', $activeSignal['entry'])] = $i + 24;
                            $pairSignals[]  = $activeSignal;
                            $activeSignal   = null;
                            continue;
                        }
                        if ($low <= $activeSignal['tp']) {
                            $activeSignal['outcome']    = 'WIN';
                            $activeSignal['close_time'] = $ts;
                            $activeSignal['exit_pnl']   = round($activeSignal['trade_risk'] * $rrTarget, 2);
                            $obCooldown[sprintf('%.4f', $activeSignal['entry'])] = $i + 8;
                            $pairSignals[]  = $activeSignal;
                            $activeSignal   = null;
                            continue;
                        }
                    }

                    continue; // Still running
                }

                // Generate new signal
                $window   = array_slice($klines, max(0, $i - 199), 200);
                $htfWin   = array_values(array_filter($klinesHTF, fn($k) => (int)$k[0] <= $ts));
                $htfWin   = array_slice($htfWin, -60);
                $dailyWin = array_values(array_filter($klinesD,   fn($k) => (int)$k[0] <= $ts));
                $dailyWin = array_slice($dailyWin, -60);
                $weeklyWin = array_values(array_filter($klinesW,  fn($k) => (int)$k[0] <= $ts));
                $weeklyWin = array_slice($weeklyWin, -60);

                $result = $service->analyze($window, $htfWin, 'smc', $symbol, $tf, true, $dailyWin, false, $weeklyWin);
                $sig    = $result['signal'] ?? null;

                if (!$sig || empty($sig['entry']) || empty($sig['tp']) || empty($sig['sl'])) continue;

                // Local confidence score
                $localSc = $service->computeConfidenceScore(
                    $sig,
                    array_slice($window, -50),
                    $result['structure']   ?? [],
                    ['trend' => $result['htf_trend'] ?? 'không rõ'],
                    $result['indicators']  ?? [],
                    $result['orderBlocks'] ?? []
                );
                $sig['ai_score'] = $localSc;

                $tradeRisk  = ($localSc >= $aiHigh) ? $riskHigh : $risk;

                $entry  = (float)$sig['entry'];
                $sl     = (float)$sig['sl'];
                $slDist = abs($entry - $sl);
                if ($slDist <= 0) continue;

                $isLongEntry = str_contains(strtolower($sig['type'] ?? ''), 'mua');
                $tp = $isLongEntry
                    ? round($entry + $slDist * $rrTarget, 8)
                    : round($entry - $slDist * $rrTarget, 8);

                $rr = abs($tp - $entry) / $slDist;
                if ($rr < $minRR) continue;

                // OB cooldown
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
                    'symbol'        => $symbol,
                    'type'          => $sig['type'],
                    'signal_time'   => $ts,
                    'signal_candle' => $i,
                    'fill_time'     => null,
                    'fill_candle'   => null,
                    'close_time'    => null,
                    'entry'         => $entry,
                    'tp'            => $tp,
                    'sl'            => $sl,
                    'rr'            => round($rr, 2),
                    'trade_risk'    => $tradeRisk,
                    'ai_score'      => $localSc,
                    'filled'        => false,
                    'outcome'       => 'PENDING',
                    'exit_pnl'      => 0.0,
                ];
            }

            // Close any still-open signal
            if ($activeSignal) {
                $activeSignal['outcome']  = 'OPEN';
                $activeSignal['exit_pnl'] = 0.0;
                $pairSignals[] = $activeSignal;
            }

            // Collect per-pair stats
            $pWins   = count(array_filter($pairSignals, fn($s) => $s['outcome'] === 'WIN'));
            $pLosses = count(array_filter($pairSignals, fn($s) => $s['outcome'] === 'LOSS'));
            $pSE     = count(array_filter($pairSignals, fn($s) => $s['outcome'] === 'STRUCT_EXIT'));
            $pFilled = count(array_filter($pairSignals, fn($s) => $s['filled']));
            $pClosed = $pWins + $pLosses + $pSE;
            $pWR     = $pClosed > 0 ? round($pWins / $pClosed * 100, 1) : 0.0;
            $pPnl    = array_sum(array_column($pairSignals, 'exit_pnl'));

            $perPairStat[$symbol] = [
                'signals' => count($pairSignals),
                'filled'  => $pFilled,
                'wins'    => $pWins,
                'losses'  => $pLosses,
                'se'      => $pSE,
                'wr'      => $pWR,
                'pnl'     => $pPnl,
                'struct_exit' => $useStructExit,
            ];

            foreach ($pairSignals as $s) {
                $allSignals[] = $s;
            }
        }

        if (empty($allSignals)) {
            $this->warn('No signals generated for any symbol.');
            return 0;
        }

        // ── Portfolio simulation ─────────────────────────────────────────
        // Sort by fill_time ASC; EXPIRED/OPEN (fill_time=null) go last
        usort($allSignals, function ($a, $b) {
            $fa = $a['fill_time'];
            $fb = $b['fill_time'];
            if ($fa === null && $fb === null) return 0;
            if ($fa === null) return 1;
            if ($fb === null) return -1;
            return $fa <=> $fb;
        });

        $currentCapital = $capital;
        $equityCurve    = [$capital];
        $lastEquityPrint = $capital;

        foreach ($allSignals as $s) {
            $currentCapital = round($currentCapital + $s['exit_pnl'], 2);
            // Record equity point when change >= $5
            if (abs($currentCapital - $lastEquityPrint) >= 5.0) {
                $equityCurve[]   = $currentCapital;
                $lastEquityPrint = $currentCapital;
            }
        }
        // Always include final value
        if (end($equityCurve) !== $currentCapital) {
            $equityCurve[] = $currentCapital;
        }

        // ── Output ───────────────────────────────────────────────────────
        $totalFilled = count(array_filter($allSignals, fn($s) => $s['filled']));
        $totalWins   = count(array_filter($allSignals, fn($s) => $s['outcome'] === 'WIN'));
        $totalLosses = count(array_filter($allSignals, fn($s) => $s['outcome'] === 'LOSS'));
        $totalSE     = count(array_filter($allSignals, fn($s) => $s['outcome'] === 'STRUCT_EXIT'));
        $totalClosed = $totalWins + $totalLosses + $totalSE;
        $portfolioWR = $totalClosed > 0 ? round($totalWins / $totalClosed * 100, 1) : 0.0;
        $totalPnl    = round($currentCapital - $capital, 2);
        $pnlPct      = $capital > 0 ? round($totalPnl / $capital * 100, 1) : 0.0;

        $this->line('');
        $this->line(str_repeat('═', 60));
        $this->line('  PORTFOLIO BACKTEST — ' . $fromLabel . ' → ' . $toLabel);
        $this->line('  Symbols: ' . $symbolList);
        $this->line('  Capital: $' . $capital . '  |  Risk: $' . $risk . '/$' . $riskHigh . ' (AI>=' . $aiHigh . ')  |  RR: 1:' . $rrTarget);
        $this->line(str_repeat('═', 60));
        $this->line('');
        $this->line('  PER-PAIR SUMMARY');
        $this->line(str_repeat('─', 60));
        $this->line(
            str_pad('Symbol', 10)
            . str_pad('Signals', 9)
            . str_pad('Filled', 8)
            . str_pad('W', 5)
            . str_pad('L', 5)
            . str_pad('SE', 5)
            . str_pad('WR', 8)
            . 'P&L'
        );
        $this->line(str_repeat('─', 60));

        foreach ($perPairStat as $sym => $stat) {
            $pnlSign  = $stat['pnl'] >= 0 ? '+' : '';
            $seNote   = $stat['struct_exit'] ? '  [struct-exit]' : '';
            $wrColor  = $stat['wr'] >= 50 ? 'green' : 'red';
            $pnlColor = $stat['pnl'] >= 0 ? 'green' : 'red';
            $this->line(
                str_pad($sym, 10)
                . str_pad($stat['signals'], 9)
                . str_pad($stat['filled'], 8)
                . str_pad($stat['wins'], 5)
                . str_pad($stat['losses'], 5)
                . str_pad($stat['se'], 5)
                . '<fg=' . $wrColor . '>' . str_pad($stat['wr'] . '%', 8) . '</>'
                . '<fg=' . $pnlColor . '>' . $pnlSign . '$' . number_format($stat['pnl'], 2) . '</>'
                . $seNote
            );
        }

        $this->line('');
        $this->line('  PORTFOLIO RESULT');
        $this->line(str_repeat('─', 60));
        $this->line('  Total trades:   ' . $totalFilled);
        $this->line('  WIN:            ' . $totalWins . '   LOSS: ' . $totalLosses . '   STRUCT_EXIT: ' . $totalSE);

        $wrColor  = $portfolioWR >= 50 ? 'green' : 'red';
        $this->line('  Portfolio WR:   <fg=' . $wrColor . '>' . $portfolioWR . '%</>');

        $pnlColor = $totalPnl >= 0 ? 'green' : 'red';
        $pnlSign  = $totalPnl >= 0 ? '+' : '';
        $this->line('  P&L:           <fg=' . $pnlColor . '>' . $pnlSign . '$' . number_format($totalPnl, 2) . '   (' . $pnlSign . $pnlPct . '%)</> ');
        $this->line('  Capital cuoi:  $' . number_format($currentCapital, 2));

        // Equity curve ASCII
        $this->line('');
        $this->line('  EQUITY CURVE (moi $5 thay doi)');
        $curveStr = '$' . number_format($equityCurve[0], 0);
        foreach (array_slice($equityCurve, 1) as $eq) {
            $curveStr .= ' ──► $' . number_format($eq, 0);
        }
        $this->line('  ' . $curveStr);

        $this->line(str_repeat('═', 60));

        return 0;
    }

    // ── Helpers (copied from BacktestCommand) ────────────────────────────

    private function resolveDateRange(?string $from, ?string $to): array
    {
        if ($from && $to) {
            $fromTs = strtotime($from . ' 00:00:00 UTC') * 1000;
            $toTs   = strtotime($to   . ' 23:59:59 UTC') * 1000;
            return [$fromTs, $toTs, $from, $to];
        }

        // Default: 2026-01-01 → today
        $defaultFrom = '2026-01-01';
        $defaultTo   = date('Y-m-d');

        if ($from && !$to) {
            $fromTs = strtotime($from . ' 00:00:00 UTC') * 1000;
            $toTs   = strtotime($defaultTo . ' 23:59:59 UTC') * 1000;
            return [$fromTs, $toTs, $from, $defaultTo];
        }

        if (!$from && $to) {
            $fromTs = strtotime($defaultFrom . ' 00:00:00 UTC') * 1000;
            $toTs   = strtotime($to . ' 23:59:59 UTC') * 1000;
            return [$fromTs, $toTs, $defaultFrom, $to];
        }

        $fromTs = strtotime($defaultFrom . ' 00:00:00 UTC') * 1000;
        $toTs   = strtotime($defaultTo   . ' 23:59:59 UTC') * 1000;
        return [$fromTs, $toTs, $defaultFrom, $defaultTo];
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
                $this->error('Binance API error: ' . $resp->body());
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
