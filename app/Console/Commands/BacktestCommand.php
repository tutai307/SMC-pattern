<?php

namespace App\Console\Commands;

use App\Services\BinanceService;
use App\Services\PriceActionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use App\Models\BacktestRun;

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
        {--min-rr=1.4 : Minimum signal R:R to accept (filter weak setups)}
        {--adx=25 : Minimum ADX threshold (default 25, lower=more signals)}
        {--min-confidence=60 : Minimum confidence score (default 60, lower=more signals)}
        {--ai : Enable AI scoring filter (calls OpenRouter per signal)}
        {--ai-min=65 : Minimum AI score to accept signal (default 65, used with --ai)}
        {--struct-exit : Exit early when LTF structure flips against signal direction}
        {--save : Lưu kết quả vào DB để tái sử dụng}
        {--use-cache : Dùng kết quả đã lưu nếu params + logic trùng khớp}
        {--ai-risk : Dynamic sizing: AI score≥ai-high → risk-high USD, otherwise → risk USD}
        {--ai-high=75 : AI score threshold for high risk (default 75)}
        {--risk-high=5 : Risk per trade khi AI score≥ai-high (default $5)}
        {--override-tp : Override TP của signal về đúng entry±SL×rr để test R:R thực tế}';

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
        $adxThreshold  = (int)   $this->option('adx');
        $minConfidence = (int)   $this->option('min-confidence');
        $useAI         = (bool)  $this->option('ai');
        $aiMin         = (int)   $this->option('ai-min');
        $useStructExit = (bool)  $this->option('struct-exit');
        $saveResult    = (bool)  $this->option('save');
        $useCache      = (bool)  $this->option('use-cache');
        $aiRisk        = (bool)  $this->option('ai-risk');
        $aiHigh        = (int)   $this->option('ai-high');
        $riskHigh      = (float) $this->option('risk-high');
        $overrideTp    = (bool)  $this->option('override-tp');
        $logicHash     = md5(file_get_contents(app_path('Services/PriceActionService.php')));

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
        $aiLabel     = $aiRisk
            ? "AI-RISK ≥{$aiHigh}→\${$riskHigh} / <{$aiHigh}→\${$risk}"
            : ($useAI ? "AI≥{$aiMin}" : 'AI: OFF');
        $structLabel  = $useStructExit ? 'StructExit: ON' : 'StructExit: OFF';
        $tpLabel      = $overrideTp ? "TP=override(1:{$rrTarget})" : "TP=signal";
        $this->info("  ADX≥{$adxThreshold}  |  Confidence≥{$minConfidence}  |  Min R:R {$minRR}  |  {$aiLabel}  |  {$structLabel}  |  {$tpLabel}");
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

        // Cache lookup
        if ($useCache) {
            $cacheParams = [
                'symbol' => $symbol, 'timeframe' => $tf, 'htf' => $htf,
                'from_date' => date('Y-m-d', $fromTs / 1000), 'to_date' => date('Y-m-d', $toTs / 1000),
                'method' => $method, 'rr' => $rrTarget, 'risk' => $risk, 'capital' => $capital,
                'adx_threshold' => $adxThreshold, 'min_confidence' => $minConfidence,
                'use_session' => $useSession, 'use_ai' => $useAI, 'use_struct_exit' => $useStructExit,
            ];
            $cached = BacktestRun::findCached($cacheParams, $logicHash);
            if ($cached) {
                $this->info("  ✅ Cache HIT — ID #{$cached->id} (chạy lúc {$cached->created_at->format('Y-m-d H:i')})");
                $this->line("  Signals: {$cached->signals_count} | Filled: {$cached->filled_count} | WR: {$cached->winrate}% | P&L: " . ($cached->pnl >= 0 ? '+' : '') . "\${$cached->pnl}");
                $this->line("  Capital cuối: \${$cached->capital_end}");
                $this->info(str_repeat('═', 55));
                return 0;
            }
            $this->line('  Cache MISS — chạy mới...');
        }

        // Apply custom thresholds for backtest exploration
        $service->setThresholds($adxThreshold, $minConfidence);

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

                // Filled — structure exit check every 4 candles (= 1h for 15m TF)
                if ($useStructExit && ($i - $activeSignal['fill_candle']) % 4 === 0) {
                    $structWin = array_slice($klines1h, max(0, $i - 49), 50);
                    $struct    = $service->getStructure($structWin);
                    $trend     = $struct['trend'] ?? 'không rõ';
                    $flipLong  = $isLong  && $trend === 'GIẢM GIÁ';
                    $flipShort = !$isLong && $trend === 'TĂNG GIÁ';
                    if ($flipLong || $flipShort) {
                        $slDist  = abs($activeSignal['entry'] - $activeSignal['sl']);
                        $pxDiff  = $isLong ? ($close - $activeSignal['entry']) : ($activeSignal['entry'] - $close);
                        $exitPnl = $slDist > 0 ? round($pxDiff / $slDist * ($activeSignal['trade_risk'] ?? $risk), 2) : 0;
                        $activeSignal['outcome']     = 'STRUCT_EXIT';
                        $activeSignal['close_time']  = $ts;
                        $activeSignal['close_price'] = $close;
                        $activeSignal['exit_pnl']    = $exitPnl;
                        $signals[]    = $activeSignal;
                        $activeSignal = null;
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

            $result = $service->analyze($window, $htfWin, $method, $symbol, $tf, !$useAI && !$aiRisk, $dailyWin, $useSession, $weeklyWin);
            $sig    = $result['signal'] ?? null;
            $scanned++;

            if (!$sig || empty($sig['entry']) || empty($sig['tp']) || empty($sig['sl'])) continue;

            if ($useAI) {
                $aiScore = (int) ($sig['ai_score'] ?? 0);
                $aiErr   = $sig['ai_error'] ?? null;
                if ($aiErr || $aiScore < $aiMin) {
                    $this->line("  → AI score {$aiScore}/100" . ($aiErr ? " (err: {$aiErr})" : '') . " < {$aiMin}, skip");
                    continue;
                }
                $this->line("  → AI score {$aiScore}/100 ✓");
            }

            // Per-trade risk based on AI score
            $tradeRisk  = $risk;
            $sigAiScore = (int)($sig['ai_score'] ?? 0);
            if ($aiRisk) {
                $tradeRisk = ($sigAiScore >= $aiHigh) ? $riskHigh : $risk;
                $this->line("  → AI {$sigAiScore}/100 → Risk: \${$tradeRisk}");
            }

            $entry  = (float)$sig['entry'];
            $sl     = (float)$sig['sl'];
            $slDist = abs($entry - $sl);
            if ($slDist <= 0) continue;

            $isLongEntry = str_contains(strtolower($sig['type'] ?? ''), 'mua');
            $tp = $overrideTp
                ? ($isLongEntry
                    ? round($entry + $slDist * $rrTarget, 8)
                    : round($entry - $slDist * $rrTarget, 8))
                : (float)$sig['tp'];

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
                'trade_risk'   => $tradeRisk,
                'ai_score'     => $sigAiScore,
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

        $riskCol = $aiRisk ? str_pad('RISK/AI', 12) : '';
        $this->line(str_pad('#', 3) . str_pad('TYPE', 7) . str_pad('LABEL', 14)
            . str_pad('SIGNAL', 14) . str_pad('FILL', 14)
            . str_pad('ENTRY', 10) . str_pad('TP', 10) . str_pad('SL', 10)
            . str_pad('R:R', 6) . $riskCol . 'RESULT');
        $this->line(str_repeat('─', $aiRisk ? 112 : 100));

        foreach ($signals as $s) {
            $sigDate  = date('d/m H:i', (int)($s['signal_time'] / 1000));
            $fillDate = $s['fill_time'] ? date('d/m H:i', (int)($s['fill_time'] / 1000)) : 'NOT FILLED';
            $resultColor = match($s['outcome']) {
                'WIN'         => "<fg=green>WIN</>",
                'LOSS'        => "<fg=red>LOSS</>",
                'EXPIRED'     => "<fg=yellow>EXPIRED</>",
                'OPEN'        => "<fg=cyan>OPEN</>",
                'STRUCT_EXIT' => ($s['exit_pnl'] ?? 0) >= 0
                    ? "<fg=green>CUT+" . number_format($s['exit_pnl'] ?? 0, 2) . "</>"
                    : "<fg=yellow>CUT" . number_format($s['exit_pnl'] ?? 0, 2) . "</>",
                default       => $s['outcome'],
            };

            $riskAiCol = $aiRisk
                ? str_pad('$' . number_format($s['trade_risk'] ?? $risk, 0) . '/AI:' . ($s['ai_score'] ?? 0), 12)
                : '';
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
                . $riskAiCol
                . $resultColor
            );
        }

        // ── 5. Stats ─────────────────────────────────────────────────────
        $wins        = array_filter($signals, fn($s) => $s['outcome'] === 'WIN');
        $losses      = array_filter($signals, fn($s) => $s['outcome'] === 'LOSS');
        $structExits = array_filter($signals, fn($s) => $s['outcome'] === 'STRUCT_EXIT');
        $expired     = array_filter($signals, fn($s) => $s['outcome'] === 'EXPIRED');
        $open        = array_filter($signals, fn($s) => $s['outcome'] === 'OPEN');
        $filled      = array_filter($signals, fn($s) => $s['filled']);
        $closed      = count($wins) + count($losses) + count($structExits);
        $wr          = $closed > 0 ? round(count($wins) / $closed * 100, 1) : 0;

        // P&L: WIN = +tradeRisk*rr, LOSS = -tradeRisk, STRUCT_EXIT = actual exit_pnl
        $pnl = 0;
        foreach ($signals as $s) {
            $tr = $s['trade_risk'] ?? $risk;
            if ($s['outcome'] === 'WIN')         $pnl += $tr * $rrTarget;
            if ($s['outcome'] === 'LOSS')        $pnl -= $tr;
            if ($s['outcome'] === 'STRUCT_EXIT') $pnl += ($s['exit_pnl'] ?? 0);
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
        if (count($structExits) > 0) {
            $seTotal = array_sum(array_map(fn($s) => $s['exit_pnl'] ?? 0, $structExits));
            $seAvg   = round($seTotal / count($structExits), 2);
            $seSign  = $seTotal >= 0 ? '+' : '';
            $this->line("  STRUCT_EXIT:    " . count($structExits) . "  (avg {$seSign}{$seAvg}\$ | total {$seSign}" . round($seTotal, 2) . "\$)");
        }
        $this->line("  EXPIRED:        " . count($expired) . "  (chưa fill trong thời hạn)");
        $this->line("  OPEN (end):     " . count($open));
        $this->line("  Winrate:        <fg=" . ($wr >= 50 ? 'green' : 'red') . ">{$wr}%</> ({$closed} closed)");
        $pnlColor = $pnl >= 0 ? 'green' : 'red';
        $pnlSign  = $pnl >= 0 ? '+' : '';
        if ($aiRisk) {
            $highCount = count(array_filter($signals, fn($s) => $s['filled'] && ($s['trade_risk'] ?? 0) >= $riskHigh));
            $lowCount  = count(array_filter($signals, fn($s) => $s['filled'] && ($s['trade_risk'] ?? 0) < $riskHigh));
            $this->line("  P&L (\${$capital}, AI-RISK \${$riskHigh}/\${$risk}, 1:{$rrTarget}): <fg={$pnlColor}>{$pnlSign}" . number_format($pnl, 2) . " USD</>  ({$highCount}×\${$riskHigh} + {$lowCount}×\${$risk})");
        } else {
            $this->line("  P&L (\${$capital}, {$risk}\$/trade, 1:{$rrTarget}): <fg={$pnlColor}>{$pnlSign}" . number_format($pnl, 2) . " USD</>");
        }
        $this->line("  Capital cuối:   " . number_format($capital + $pnl, 2) . " USD");
        $this->line(str_repeat('═', 55));

        // ── 6. Daily stats ───────────────────────────────────────────────
        $byDay = [];
        foreach ($signals as $s) {
            // Dùng signal_time để group (ngày tín hiệu xuất hiện)
            $day = date('Y-m-d', (int)($s['signal_time'] / 1000));
            if (!isset($byDay[$day])) {
                $byDay[$day] = ['signals' => 0, 'filled' => 0, 'wins' => 0, 'losses' => 0, 'pnl' => 0];
            }
            $byDay[$day]['signals']++;
            if ($s['filled'])              $byDay[$day]['filled']++;
            if ($s['outcome'] === 'WIN')  { $byDay[$day]['wins']++;   $byDay[$day]['pnl'] += ($s['trade_risk'] ?? $risk) * $rrTarget; }
            if ($s['outcome'] === 'LOSS') { $byDay[$day]['losses']++; $byDay[$day]['pnl'] -= ($s['trade_risk'] ?? $risk); }
        }
        ksort($byDay);

        $totalDays      = count($byDay);
        $profitDays     = count(array_filter($byDay, fn($d) => $d['pnl'] > 0));
        $breakEvenDays  = count(array_filter($byDay, fn($d) => $d['pnl'] == 0 && $d['filled'] > 0));
        $lossDays       = count(array_filter($byDay, fn($d) => $d['pnl'] < 0));
        $avgSignals     = $totalDays > 0 ? round(array_sum(array_column($byDay, 'signals')) / $totalDays, 2) : 0;
        $avgFilled      = $totalDays > 0 ? round(array_sum(array_column($byDay, 'filled'))  / $totalDays, 2) : 0;
        $dayWR          = ($profitDays + $lossDays) > 0
            ? round($profitDays / ($profitDays + $lossDays) * 100, 1) : 0;

        $this->line("\n  THỐNG KÊ THEO NGÀY");
        $this->line(str_repeat('─', 55));
        $this->line("  Ngày có signal:  {$totalDays}");
        $this->line("  Avg signal/ngày: {$avgSignals}  (filled: {$avgFilled})");
        $this->line("  Ngày có lãi:     <fg=green>{$profitDays}</>");
        $this->line("  Ngày hòa:        {$breakEvenDays}");
        $this->line("  Ngày lỗ:         <fg=red>{$lossDays}</>");
        $this->line("  Winrate/ngày:    <fg=" . ($dayWR >= 50 ? 'green' : 'red') . ">{$dayWR}%</>");
        $this->line(str_repeat('─', 55));

        // Top 5 ngày tốt nhất và tệ nhất
        uasort($byDay, fn($a, $b) => $b['pnl'] <=> $a['pnl']);
        $this->line("  Top ngày tốt nhất:");
        foreach (array_slice($byDay, 0, 3, true) as $date => $d) {
            $sign = $d['pnl'] >= 0 ? '+' : '';
            $this->line("    {$date}  {$d['wins']}W/{$d['losses']}L  <fg=green>{$sign}" . number_format($d['pnl'], 2) . "</>");
        }
        $this->line("  Top ngày tệ nhất:");
        foreach (array_slice(array_reverse($byDay, true), 0, 3, true) as $date => $d) {
            $sign = $d['pnl'] >= 0 ? '+' : '';
            $color = $d['pnl'] < 0 ? 'red' : 'green';
            $this->line("    {$date}  {$d['wins']}W/{$d['losses']}L  <fg={$color}>{$sign}" . number_format($d['pnl'], 2) . "</>");
        }
        $this->line(str_repeat('═', 55));

        // Save result to DB
        if ($saveResult) {
            $structExitArr = array_filter($signals, fn($s) => $s['outcome'] === 'STRUCT_EXIT');
            BacktestRun::create([
                'symbol'            => $symbol,
                'timeframe'         => $tf,
                'htf'               => $htf,
                'from_date'         => date('Y-m-d', $fromTs / 1000),
                'to_date'           => date('Y-m-d', $toTs / 1000),
                'method'            => $method,
                'rr'                => $rrTarget,
                'risk'              => $risk,
                'capital'           => $capital,
                'adx_threshold'     => $adxThreshold,
                'min_confidence'    => $minConfidence,
                'use_session'       => $useSession,
                'use_ai'            => $useAI,
                'ai_min'            => $useAI ? $aiMin : null,
                'use_struct_exit'   => $useStructExit,
                'logic_hash'        => $logicHash,
                'signals_count'     => count($signals),
                'filled_count'      => count(array_filter($signals, fn($s) => $s['filled'])),
                'win_count'         => count(array_filter($signals, fn($s) => $s['outcome'] === 'WIN')),
                'loss_count'        => count(array_filter($signals, fn($s) => $s['outcome'] === 'LOSS')),
                'expired_count'     => count(array_filter($signals, fn($s) => $s['outcome'] === 'EXPIRED')),
                'struct_exit_count' => count($structExitArr),
                'fill_rate'         => $fillRate,
                'winrate'           => $wr,
                'pnl'               => $pnl,
                'capital_end'       => round($capital + $pnl, 2),
                'signals_json'      => json_encode($signals),
            ]);
            $this->info("  💾 Đã lưu vào DB — dùng --use-cache để tái sử dụng lần sau.");
        }

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
