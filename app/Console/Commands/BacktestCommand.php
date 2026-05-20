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
        {--method=smc : Analysis method (smc)}
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
        {--ai-high=80 : AI score threshold for high risk (default 80)}
        {--risk-high=5 : Risk per trade khi AI score≥ai-high (default $5)}
        {--override-tp : Override TP của signal về đúng entry±SL×rr để test R:R thực tế}
        {--local-score : Dùng computeConfidenceScore() thay AI API (free, dùng để so sánh)}
        {--vision : Tải dữ liệu từ data.binance.vision thay Binance API (cho backtest dài ngày, cache local)}
        {--sl-mode=new : SL calculation mode: "old" (OB edge + 0.1% buffer) or "new" (ATR×1.5 adaptive)}
        {--skip-weekend : Skip Saturday and Sunday — no signal generation on weekends}
        {--pct-risk : Interpret --risk and --risk-high as % of current capital (compound sizing)}
        {--breakout : Dùng MT5 cache data + breakout logic (FelixLocalTrader v6.5)}
        {--entry-buf=1.5 : Breakout entry buffer (giá)}
        {--sl-buf=0.5 : SL buffer ngoài rìa hộp (giá)}
        {--min-box=15 : Số nến M15 tối thiểu của hộp nén động}
        {--max-box=50 : Số nến M15 tối đa quét tìm hộp chặt nhất}
        {--max-noise=80.0 : Bỏ qua nếu hộp rộng hơn mức này (quá nhiễu)}
        {--fo-tol=0.20 : Fakeout tolerance — % chiều cao hộp được phép retest}
        {--h4-strength=2 : Số nến H4 mỗi bên confirm swing}
        {--h4-lookback=50 : Số nến H4 tối đa tìm 2 swing}
        {--expiry-bars=16 : Pending huỷ sau N M15 bars (16 = 4h)}
        {--max-sl=25.0 : (legacy) không dùng trong v6.5}
        {--range-period=20 : (legacy) không dùng trong v6.5}
        {--swing-lookback=30 : Swing lookback bars (v7.2)}
        {--swing-strength=3 : Fractal strength mỗi bên (v7.2)}
        {--swing : Dùng Swing/Wedge/BE/Trailing logic (XAUUSD M15 MT5 cache)}
        {--be-trigger=1.5 : Break-Even trigger distance (giá)}
        {--lock-profit=0.3 : Khóa SL tại entry+N khi lãi >= BE_Trigger (0 = bare entry)}
        {--trail-step=2.5 : (legacy) Trailing stop cố định — bị bỏ qua khi dùng --atr-mult}
        {--atr-period=14 : ATR period cho Dynamic Trailing (v7.4)}
        {--atr-mult=0.75 : Hệ số nhân ATR cho trail distance (v7.4)}
        {--wedge-filter : Enable falling wedge filter — BUY ONLY khi phát hiện nêm xuống}
        {--wedge-ratio=0.80 : Wedge convergence ratio}
        {--max-per-day=3 : Max trades filled per day (0 = unlimited)}
        {--fixed-sl=0 : Fixed SL distance (giá) — 0 = dùng Swing SL cũ (v7.7: 10.0)}
        {--fixed-tp=0 : Hard TP distance (giá) — 0 = trailing only (v7.8)}
        {--trail-activation=4.0 : Profit level để force SL ≥ entry+BE_Trigger (Pha C)}';



    protected $description = 'Walk-forward backtest SMC signals on historical Binance klines (no AI scoring)';

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
        $localScore    = (bool)   $this->option('local-score');
        $useVision     = (bool)   $this->option('vision');
        $slMode        = (string) $this->option('sl-mode');
        $skipWeekend = (bool) $this->option('skip-weekend');
        $pctRisk = (bool) $this->option('pct-risk');
        $logicHash     = md5(file_get_contents(app_path('Services/PriceActionService.php')));

        // Resolve date range
        [$fromTs, $toTs, $fromLabel, $toLabel] = $this->resolveDateRange(
            $this->option('from'),
            $this->option('to')
        );

        if ($this->option('breakout')) {
            return $this->runBreakoutBacktest($fromTs, $toTs, $fromLabel, $toLabel, $capital, $risk, (float)$this->option('rr'));
        }

        if ($this->option('swing')) {
            return $this->runSwingBacktest($fromTs, $toTs, $fromLabel, $toLabel, $capital, $risk);
        }

        $sessionLabel = $useSession ? ' | Session: London+NY' : ' | Session: OFF';
        $methodLabel  = strtoupper($method);
        $this->info("═══════════════════════════════════════════════════");
        $this->info("  BACKTEST [{$methodLabel}] — {$symbol} {$tf}  |  {$fromLabel} → {$toLabel}");
        $this->info("  HTF bias: {$htf}  |  Risk/trade: \${$risk}  |  Capital: \${$capital}{$sessionLabel}");
        $aiLabel     = $aiRisk
            ? "AI-RISK ≥{$aiHigh}→\${$riskHigh} / <{$aiHigh}→\${$risk}"
            : ($useAI ? "AI≥{$aiMin}" : ($localScore ? "LOCAL-SCORE: ≥{$aiHigh}→\${$riskHigh} / <{$aiHigh}→\${$risk}" : 'AI: OFF'));
        $structLabel  = $useStructExit ? 'StructExit: ON' : 'StructExit: OFF';
        $tpLabel      = $overrideTp ? "TP=override(1:{$rrTarget})" : "TP=signal";
        $slLabel      = "SL=" . strtoupper($slMode);
        $this->info("  ADX≥{$adxThreshold}  |  Confidence≥{$minConfidence}  |  Min R:R {$minRR}  |  {$aiLabel}  |  {$structLabel}  |  {$tpLabel}  |  {$slLabel}");
        $this->info("═══════════════════════════════════════════════════");

        // ── 1. Fetch klines ──────────────────────────────────────────────
        $warmupMs     = 200 * $this->tfToMs($tf);
        $fetchFrom    = $fromTs - $warmupMs;
        $dailyWarmup  = 200 * 86_400_000; // 200 days trước test period

        $weeklyWarmup = 80 * 7 * 86_400_000; // 80 tuần trước test period (đủ cho EMA50)

        if ($useVision) {
            $this->info("Fetching {$symbol} {$tf} klines from data.binance.vision...");
            $klines1h   = $binance->getVisionKlines($symbol, $tf,  $fromLabel, $toLabel);
            $klinesHTF  = $binance->getVisionKlines($symbol, $htf, $fromLabel, $toLabel);
            $klinesDaily  = $binance->getVisionKlines($symbol, '1d', $fromLabel, $toLabel);
            $klinesWeekly = $binance->getVisionKlines($symbol, '1w', $fromLabel, $toLabel);
            // Ensure proper numeric keys
            $klines1h     = array_values($klines1h);
            $klinesHTF    = array_values($klinesHTF);
            $klinesDaily  = array_values($klinesDaily);
            $klinesWeekly = array_values($klinesWeekly);
            $this->info("Fetched " . count($klines1h) . " {$tf} candles, " . count($klinesHTF) . " {$htf} candles, " . count($klinesDaily) . " 1d candles, " . count($klinesWeekly) . " 1w candles.");
        } else {
            $this->line("Fetching {$symbol} {$tf} klines from Binance...");
            $klines1h      = $this->fetchKlines($symbol, $tf,   $fetchFrom, $toTs);
            $klinesHTF     = $this->fetchKlines($symbol, $htf,  $fetchFrom - $this->tfToMs($htf) * 100, $toTs);
            $klinesDaily   = $this->fetchKlines($symbol, '1d',  $fromTs - $dailyWarmup, $toTs);
            $klinesWeekly  = $this->fetchKlines($symbol, '1w',  $fromTs - $weeklyWarmup, $toTs);

            $this->line("Fetched " . count($klines1h) . " {$tf} candles, " . count($klinesHTF) . " {$htf} candles, " . count($klinesDaily) . " 1d candles, " . count($klinesWeekly) . " 1w candles.");
        }

        if (count($klines1h) < 210) {
            $this->error("Not enough klines fetched: " . count($klines1h));
            return 1;
        }

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

        // Apply custom thresholds and SL mode for backtest exploration
        $service->setThresholds($adxThreshold, $minConfidence);
        $service->setSLMode($slMode);

        // ── 3. Walk-forward simulation ───────────────────────────────────
        $signals      = [];
        $activeSignal = null;
        $scanned      = 0;
        // OB cooldown: [entry_price => candle_index_cooldown_until]
        $obCooldown   = [];
        // Dedup: track candle index of last signal per direction
        $lastSignalCandle = ['LONG' => -999, 'SHORT' => -999];
        $currentCapital = $capital; // track compound capital khi pct-risk

        for ($i = $startIdx; $i < count($klines1h); $i++) {
            $candle = $klines1h[$i];
            $ts     = (int)$candle[0];

            // Weekend filter — T7 (6) và CN (0) không giao dịch
            if ($skipWeekend) {
                $dow = (int) date('w', (int) ($ts / 1000));
                if ($dow === 0 || $dow === 6) continue;
            }

            // Stop at end of range
            if ($ts > $toTs) break;

            $high  = (float)$candle[2];
            $low   = (float)$candle[3];
            $close = (float)$candle[4];

            // ── Check active signal ──────────────────────────────────────
            if ($activeSignal) {
                $isLong = str_contains($activeSignal['type'], 'MUA') || $activeSignal['type'] === 'LONG';
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
                        if ($pctRisk) $currentCapital += ($activeSignal['exit_pnl'] ?? 0);
                        $currentCapital = max($currentCapital, 0);
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
                        if ($pctRisk) $currentCapital -= ($activeSignal['trade_risk'] ?? $tradeRisk);
                        $currentCapital = max($currentCapital, 0);
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
                        if ($pctRisk) $currentCapital += ($activeSignal['trade_risk'] ?? $tradeRisk) * $rrTarget;
                        $currentCapital = max($currentCapital, 0);
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
                        if ($pctRisk) $currentCapital -= ($activeSignal['trade_risk'] ?? $tradeRisk);
                        $currentCapital = max($currentCapital, 0);
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
                        if ($pctRisk) $currentCapital += ($activeSignal['trade_risk'] ?? $tradeRisk) * $rrTarget;
                        $currentCapital = max($currentCapital, 0);
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

            if ($localScore && !$useAI) {
                $localSc = $service->computeConfidenceScore(
                    $sig,
                    array_slice($window, -50),
                    $result['structure']     ?? [],
                    ['trend' => $result['htf_trend'] ?? 'không rõ'],
                    $result['indicators']    ?? [],
                    $result['orderBlocks']   ?? []
                );
                $sig['ai_score'] = $localSc;
                $this->line("  → LocalScore {$localSc}/100");
                if ($localSc < $minConfidence) {
                    $this->line("  → LocalScore {$localSc} < {$minConfidence}, skip");
                    continue;
                }
            }

            // Per-trade risk: fixed USD hoặc % of current capital
            $tradeRisk  = $pctRisk ? round($currentCapital * $risk / 100, 4) : $risk;
            $sigAiScore = (int)($sig['ai_score'] ?? 0);
            if ($aiRisk || $localScore) {
                $highRisk  = $pctRisk ? round($currentCapital * $riskHigh / 100, 4) : $riskHigh;
                $tradeRisk = ($sigAiScore >= $aiHigh) ? $highRisk : $tradeRisk;
                $label     = $localScore ? 'LocalScore' : 'AI';
                $this->line("  → {$label} {$sigAiScore}/100 → Risk: \$" . number_format($tradeRisk, 2) . ($pctRisk ? " ({$sigAiScore}>={$aiHigh} ? {$riskHigh}% : {$risk}% of \${$currentCapital})" : ''));
            }

            $entry  = (float)$sig['entry'];
            $sl     = (float)$sig['sl'];
            $slDist = abs($entry - $sl);
            if ($slDist <= 0) continue;

            $isLongEntry = str_contains(strtolower($sig['type'] ?? ''), 'mua') || ($sig['type'] ?? '') === 'LONG';
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

            // Dedup: không tạo signal cùng hướng trong 20 candles
            $dir = $isLongEntry ? 'LONG' : 'SHORT';
            if ($i - ($lastSignalCandle[$dir] ?? -999) < 20) continue;
            $lastSignalCandle[$dir] = $i;

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
        if ($aiRisk || $localScore) {
            $highCount = count(array_filter($signals, fn($s) => $s['filled'] && ($s['trade_risk'] ?? 0) >= $riskHigh));
            $lowCount  = count(array_filter($signals, fn($s) => $s['filled'] && ($s['trade_risk'] ?? 0) < $riskHigh));
            $label = $localScore ? 'LOCAL-RISK' : 'AI-RISK';
            $this->line("  P&L (\${$capital}, {$label} \${$riskHigh}/\${$risk}, 1:{$rrTarget}): <fg={$pnlColor}>{$pnlSign}" . number_format($pnl, 2) . " USD</>  ({$highCount}×\${$riskHigh} + {$lowCount}×\${$risk})");
        } else {
            $this->line("  P&L (\${$capital}, {$risk}\$/trade, 1:{$rrTarget}): <fg={$pnlColor}>{$pnlSign}" . number_format($pnl, 2) . " USD</>");
        }
        $this->line("  Capital cuối:   " . number_format($capital + $pnl, 2) . " USD");
        if ($pctRisk) {
            $this->line("  Capital cuối (compound):   " . number_format($currentCapital, 2) . " USD  (start: \${$capital})");
        }
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
        if ($from) {
            $toResolved = $to ?: date('Y-m-d'); // --to mặc định = hôm nay nếu không truyền
            $fromTs = strtotime($from        . ' 00:00:00 UTC') * 1000;
            $toTs   = strtotime($toResolved  . ' 23:59:59 UTC') * 1000;
            return [$fromTs, $toTs, $from, $toResolved];
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

    private function runBreakoutBacktest(int $fromTs, int $toTs, string $fromLabel, string $toLabel, float $capital, float $risk, float $rr): int
    {
        $sym    = 'XAUUSDT';
        $m15raw = \Cache::get("mt5_bulk_{$sym}_15m", []);
        $h4raw  = \Cache::get("mt5_bulk_{$sym}_4h",  []);

        if (empty($m15raw) || empty($h4raw)) {
            $this->error("Không có MT5 data trong cache. Chạy FelixBulkExporter trên MT5 trước.");
            return 1;
        }

        $m15 = array_map(fn($b) => [(int)$b[0],(float)$b[1],(float)$b[2],(float)$b[3],(float)$b[4]], $m15raw);
        $h4  = array_map(fn($b) => [(int)$b[0],(float)$b[1],(float)$b[2],(float)$b[3],(float)$b[4]], $h4raw);
        $d1  = $this->buildD1FromH4($h4);

        $entryBuf   = (float) $this->option('entry-buf');
        $slBuf      = (float) $this->option('sl-buf');
        $minBox     = (int)   $this->option('min-box');
        $maxBox     = (int)   $this->option('max-box');
        $maxNoise   = (float) $this->option('max-noise');
        $foTol      = (float) $this->option('fo-tol');
        $h4Strength = (int)   $this->option('h4-strength');
        $h4Lookback = (int)   $this->option('h4-lookback');
        $expiryBars = (int)   $this->option('expiry-bars');

        $N       = count($m15);
        $warmup  = $maxBox + 5;
        $startIdx = $warmup;
        foreach ($m15 as $idx => $bar) {
            if ($bar[0] >= $fromTs && $idx >= $warmup) { $startIdx = $idx; break; }
        }

        $trades      = [];
        $pending     = null;
        $position    = null;
        $cooldownBuy  = 0;  // bar index cooldown — không vào BUY trước bar này
        $cooldownSell = 0;

        $this->info("BREAKOUT BACKTEST v6.5 — XAUUSD M15 | {$fromLabel} → {$toLabel}");
        $this->info("EntryBuf={$entryBuf} | SLBuf={$slBuf} | Box={$minBox}-{$maxBox} | MaxNoise={$maxNoise} | FO_Tol=" . ($foTol*100) . "% | RR={$rr} | Risk=\${$risk}");
        $this->line(str_repeat('─', 65));

        for ($i = $startIdx; $i < $N; $i++) {
            $bar      = $m15[$i];
            $ts       = $bar[0];
            $barHigh  = $bar[2];
            $barLow   = $bar[3];
            $barClose = $bar[4];
            if ($ts > $toTs) break;

            // ── 1. Check position đang mở ─────────────────────────────
            if ($position !== null) {
                $dist    = abs($position['entry'] - $position['sl']);
                $pnl     = 0;
                $outcome = null;
                $tol     = $position['boxHeight'] * $foTol;

                if ($position['dir'] === 'BUY') {
                    if   ($barLow  <= $position['sl'])  { $outcome='LOSS'; $pnl=-$risk; }
                    elseif ($barHigh >= $position['tp']) { $outcome='WIN';  $pnl=$risk*$rr; }
                    // Fakeout: close thụt sâu hơn tolerance vào trong hộp
                    elseif ($barClose < $position['levelRef'] - $tol) {
                        $outcome = 'FAKEOUT';
                        $pnl = $dist > 0 ? ($barClose - $position['entry']) / $dist * $risk : 0;
                    }
                } else {
                    if   ($barHigh >= $position['sl'])  { $outcome='LOSS'; $pnl=-$risk; }
                    elseif ($barLow  <= $position['tp']) { $outcome='WIN';  $pnl=$risk*$rr; }
                    elseif ($barClose > $position['levelRef'] + $tol) {
                        $outcome = 'FAKEOUT';
                        $pnl = $dist > 0 ? ($position['entry'] - $barClose) / $dist * $risk : 0;
                    }
                }

                if ($outcome !== null) {
                    $capital += $pnl;
                    $trades[] = ['dir'=>$position['dir'],'outcome'=>$outcome,'pnl'=>$pnl,'capital'=>$capital,'ts'=>$ts,
                                 'boxHeight'=>$position['boxHeight'],'dist'=>$dist];
                    $extra = $outcome==='FAKEOUT'
                        ? sprintf(" close=%.2f ref=%.2f tol=%.2f", $barClose, $position['levelRef'], $tol)
                        : '';
                    $this->line(sprintf("  %s %s %s | P&L %.2f | Cap %.2f%s",
                        date('m-d H:i',$ts/1000), $position['dir'], $outcome, $pnl, $capital, $extra));
                    // Cooldown sau WIN/LOSS = expiryBars, sau FAKEOUT = 4 bars (1h chờ cấu trúc ổn định)
                    $cd = ($outcome === 'FAKEOUT') ? $i + 4 : $i + $expiryBars;
                    if ($position['dir'] === 'BUY')  $cooldownBuy  = $cd;
                    else                              $cooldownSell = $cd;
                    $position = null;
                }
                continue;
            }

            // ── 2. Check pending fill / expiry ────────────────────────
            if ($pending !== null) {
                if ($i >= $pending['expiryIdx']) {
                    $this->line(sprintf("  %s %s EXPIRED", date('m-d H:i',$ts/1000), $pending['dir']));
                    $pending = null;
                } elseif ($pending['dir']==='BUY'  && $barHigh >= $pending['entry']) {
                    $position = $pending; $pending = null;
                    $this->line(sprintf("  %s BUY  FILLED @%.2f SL=%.2f TP=%.2f box_h=%.2f",
                        date('m-d H:i',$ts/1000), $position['entry'], $position['sl'], $position['tp'], $position['boxHeight']));
                } elseif ($pending['dir']==='SELL' && $barLow  <= $pending['entry']) {
                    $position = $pending; $pending = null;
                    $this->line(sprintf("  %s SELL FILLED @%.2f SL=%.2f TP=%.2f box_h=%.2f",
                        date('m-d H:i',$ts/1000), $position['entry'], $position['sl'], $position['tp'], $position['boxHeight']));
                }
                if ($pending !== null) continue;
            }

            if ($pending !== null || $position !== null) continue;

            // ── 3. Tìm Hộp Nén chặt nhất (sliding window) ────────────
            $box = $this->findTightestBoxArr($m15, $i, $minBox, $maxBox);
            if (!$box) continue;
            ['upper' => $upper, 'lower' => $lower, 'len' => $boxLen, 'range' => $boxRange] = $box;

            if ($boxRange > $maxNoise) continue; // quá nhiễu

            $prevHigh = $m15[$i-1][2];
            $prevLow  = $m15[$i-1][3];
            $breakUp   = $prevHigh > $upper;
            $breakDown = $prevLow  < $lower;
            if (!$breakUp && !$breakDown) continue;

            $bias = $this->getMTFBiasFromData($h4, $d1, $ts, $h4Strength, $h4Lookback);

            // BUY STOP
            if ($breakUp && in_array($bias, ['BUY','BOTH']) && $i >= $cooldownBuy) {
                $entry = $upper + $entryBuf;
                $sl    = $lower - $slBuf;
                $dist  = $entry - $sl;
                if ($dist > 0) {
                    $tp = $entry + $dist * $rr;
                    $pending = ['dir'=>'BUY','entry'=>$entry,'sl'=>$sl,'tp'=>$tp,
                                'levelRef'=>$upper,'boxHeight'=>$boxRange,
                                'expiryIdx'=>$i+$expiryBars];
                    $this->line(sprintf("  %s → BUY  @%.2f SL=%.2f TP=%.2f | box=%d nến range=%.2f [%s]",
                        date('m-d H:i',$ts/1000), $entry,$sl,$tp,$boxLen,$boxRange,$bias));
                }
            }

            // SELL STOP
            if ($breakDown && in_array($bias, ['SELL','BOTH']) && $i >= $cooldownSell) {
                $entry = $lower - $entryBuf;
                $sl    = $upper + $slBuf;
                $dist  = $sl - $entry;
                if ($dist > 0) {
                    $tp = $entry - $dist * $rr;
                    $pending = ['dir'=>'SELL','entry'=>$entry,'sl'=>$sl,'tp'=>$tp,
                                'levelRef'=>$lower,'boxHeight'=>$boxRange,
                                'expiryIdx'=>$i+$expiryBars];
                    $this->line(sprintf("  %s → SELL @%.2f SL=%.2f TP=%.2f | box=%d nến range=%.2f [%s]",
                        date('m-d H:i',$ts/1000), $entry,$sl,$tp,$boxLen,$boxRange,$bias));
                }
            }
        }

        // ── Kết quả ──────────────────────────────────────────────────────
        $wins     = array_filter($trades, fn($t) => $t['outcome']==='WIN');
        $losses   = array_filter($trades, fn($t) => $t['outcome']==='LOSS');
        $fakeouts = array_filter($trades, fn($t) => $t['outcome']==='FAKEOUT');
        $total    = count($trades);
        $wr       = $total > 0 ? round(count($wins)/$total*100,1) : 0;
        $netPnl   = array_sum(array_column($trades,'pnl'));

        $avgBox = $total > 0 ? round(array_sum(array_column($trades,'boxHeight'))/$total,2) : 0;
        $avgDist= $total > 0 ? round(array_sum(array_column($trades,'dist'))/$total,2) : 0;

        $this->line(str_repeat('═', 65));
        $this->info("BREAKOUT v6.5 RESULTS — XAUUSD M15 | {$fromLabel} → {$toLabel}");
        $this->line(str_repeat('─', 65));
        $this->line("  Tổng lệnh     : {$total}");
        $this->line("  WIN           : " . count($wins) . "  ({$wr}%)");
        $this->line("  LOSS          : " . count($losses));
        $this->line("  FAKEOUT       : " . count($fakeouts) . "  (thoát sớm)");
        $this->line("  Avg box range : {$avgBox}  |  Avg dist : {$avgDist}");
        $this->line("  Net P&L       : " . round($netPnl,2) . " USD");
        $this->line("  Capital       : " . round($capital,2) . " USD  (ban đầu: " . ($capital - $netPnl) . ")");
        $this->line("  Profit Factor : " . $this->calcPF($trades));
        $this->line(str_repeat('═', 65));
        return 0;
    }

    // Sliding window: tìm hộp MinBox bars chặt nhất trong MaxBox bars gần nhất
    private function findTightestBoxArr(array $m15, int $curIdx, int $minBox, int $maxBox): ?array
    {
        // bar[$curIdx-1] = breakout candidate (excluded), bar[$curIdx-2..] = search space
        $bestRange = PHP_FLOAT_MAX;
        $bestUpper = 0; $bestLower = PHP_FLOAT_MAX; $bestLen = $minBox;

        $maxWindows = $maxBox - $minBox + 1;
        for ($offset = 0; $offset < $maxWindows; $offset++) {
            $wEnd   = $curIdx - 2 - $offset;           // newest bar of this window
            $wStart = $wEnd - $minBox + 1;              // oldest bar of this window
            if ($wStart < 0) break;

            $hi = 0; $lo = PHP_FLOAT_MAX;
            for ($j = $wStart; $j <= $wEnd; $j++) {
                $hi = max($hi, $m15[$j][2]);
                $lo = min($lo, $m15[$j][3]);
            }
            $rng = $hi - $lo;
            if ($rng < $bestRange) {
                $bestRange = $rng; $bestUpper = $hi; $bestLower = $lo;
            }
        }
        if ($bestRange >= PHP_FLOAT_MAX) return null;
        return ['upper'=>$bestUpper,'lower'=>$bestLower,'len'=>$minBox,'range'=>$bestRange];
    }

    // Build D1 bars từ H4 bằng cách group theo ngày UTC
    private function buildD1FromH4(array $h4): array
    {
        $days = [];
        foreach ($h4 as $bar) {
            $day = gmdate('Y-m-d', $bar[0] / 1000);
            if (!isset($days[$day])) {
                $days[$day] = ['ts' => $bar[0], 'open' => $bar[1], 'high' => $bar[2], 'low' => $bar[3], 'close' => $bar[4]];
            } else {
                $days[$day]['high']  = max($days[$day]['high'],  $bar[2]);
                $days[$day]['low']   = min($days[$day]['low'],   $bar[3]);
                $days[$day]['close'] = $bar[4];
            }
        }
        return array_values($days);
    }

    // Lấy MTF bias tại thời điểm $ts
    private function getMTFBiasFromData(array $h4, array $d1, int $ts, int $h4Strength, int $h4Lookback): string
    {
        // D1: màu nến hôm qua — xanh = BULL, đỏ = BEAR (v5.9 simplified)
        $d1Bias = 'NEUTRAL';
        $prevD1 = null;
        foreach ($d1 as $bar) {
            if ($bar['ts'] >= $ts) break;
            $prevD1 = $bar;
        }
        if ($prevD1) {
            if ($prevD1['close'] > $prevD1['open']) $d1Bias = 'BULL';
            elseif ($prevD1['close'] < $prevD1['open']) $d1Bias = 'BEAR';
        }

        // H4: tìm các H4 bar đã đóng trước $ts
        $h4Bars = [];
        foreach ($h4 as $bar) {
            if ($bar[0] >= $ts) break;
            $h4Bars[] = $bar;
        }
        $h4N = count($h4Bars);
        $h4Bias = 'NEUTRAL';

        // Tìm 2 swing high và 2 swing low gần nhất trên H4
        [$sh1, $sh2] = $this->findTwoSwings($h4Bars, $h4N, $h4Strength, $h4Lookback, 'high');
        [$sl1, $sl2] = $this->findTwoSwings($h4Bars, $h4N, $h4Strength, $h4Lookback, 'low');

        $h4Bearish = ($sh1 > 0 && $sh2 > 0 && $sh1 < $sh2); // Lower High
        $h4Bullish = ($sl1 > 0 && $sl2 > 0 && $sl1 > $sl2); // Higher Low

        if ($h4Bullish && $d1Bias === 'BULL') return 'BUY';
        if ($h4Bearish && $d1Bias === 'BEAR') return 'SELL';
        return 'NONE';
    }

    // Tìm 2 swing liên tiếp trong mảng bars (oldest→newest)
    // Trả về [swing_mới_nhất, swing_cũ_hơn]
    private function findTwoSwings(array $bars, int $n, int $sw, int $lookback, string $type): array
    {
        $found = []; $idx = 0;
        // Quét ngược từ cuối (mới nhất trước)
        for ($i = $n - $sw - 2; $i >= max(0, $n - $lookback - $sw) && count($found) < 2; $i--) {
            if ($i - $sw < 0 || $i + $sw >= $n) continue;
            $candidate = $type === 'high' ? $bars[$i][2] : $bars[$i][3];
            $isSwing = true;
            for ($j = 1; $j <= $sw && $isSwing; $j++) {
                $left  = $type === 'high' ? $bars[$i - $j][2] : $bars[$i - $j][3];
                $right = $type === 'high' ? $bars[$i + $j][2] : $bars[$i + $j][3];
                if ($type === 'high' && ($left >= $candidate || $right >= $candidate)) $isSwing = false;
                if ($type === 'low'  && ($left <= $candidate || $right <= $candidate)) $isSwing = false;
            }
            if ($isSwing) $found[] = $candidate;
        }
        return [($found[0] ?? 0), ($found[1] ?? 0)];
    }

    // Tìm structural swing low từ index $from ngược về quá khứ
    private function findSwingLowArr(array $bars, int $from, int $lookback, int $sw): float
    {
        for ($i = $from; $i >= max(0, $from - $lookback); $i--) {
            if ($i - $sw < 0 || $i + $sw >= count($bars)) continue;
            $candidate = $bars[$i][3];
            $isSwing = true;
            for ($j = 1; $j <= $sw && $isSwing; $j++) {
                if (($bars[$i - $j][3] ?? PHP_FLOAT_MAX) <= $candidate) $isSwing = false;
                if (($bars[$i + $j][3] ?? PHP_FLOAT_MAX) <= $candidate) $isSwing = false;
            }
            if ($isSwing) return $candidate;
        }
        return 0.0;
    }

    // Tìm structural swing high từ index $from ngược về quá khứ
    private function findSwingHighArr(array $bars, int $from, int $lookback, int $sw): float
    {
        for ($i = $from; $i >= max(0, $from - $lookback); $i--) {
            if ($i - $sw < 0 || $i + $sw >= count($bars)) continue;
            $candidate = $bars[$i][2];
            $isSwing = true;
            for ($j = 1; $j <= $sw && $isSwing; $j++) {
                if (($bars[$i - $j][2] ?? 0) >= $candidate) $isSwing = false;
                if (($bars[$i + $j][2] ?? 0) >= $candidate) $isSwing = false;
            }
            if ($isSwing) return $candidate;
        }
        return 0.0;
    }

    // Profit factor
    private function calcPF(array $trades): string
    {
        $gross_win  = array_sum(array_map(fn($t) => max(0, $t['pnl']), $trades));
        $gross_loss = array_sum(array_map(fn($t) => abs(min(0, $t['pnl'])), $trades));
        return $gross_loss > 0 ? round($gross_win / $gross_loss, 2) : ($gross_win > 0 ? '∞' : '0');
    }

    // ══════════════════════════════════════════════════════════════
    //  SWING BACKTEST v7.2 — Fractal Swing + BE + Trailing Stop
    // ══════════════════════════════════════════════════════════════

    private function runSwingBacktest(int $fromTs, int $toTs, string $fromLabel, string $toLabel, float $capital, float $risk): int
    {
        $sym    = 'XAUUSDT';
        $m15raw = \Cache::get("mt5_bulk_{$sym}_15m", []);
        if (empty($m15raw)) {
            $this->error("Không có MT5 data. Chạy FelixBulkExporter trên MT5 trước.");
            return 1;
        }
        $m15 = array_map(fn($b) => [(int)$b[0],(float)$b[1],(float)$b[2],(float)$b[3],(float)$b[4]], $m15raw);

        $entryBuf    = (float) $this->option('entry-buf');
        $slBuf       = (float) $this->option('sl-buf');
        $swingStr    = (int)   $this->option('swing-strength');
        $swingLook   = (int)   $this->option('swing-lookback');
        $foTol       = (float) $this->option('fo-tol');
        $beTrigger   = (float) $this->option('be-trigger');
        $lockProfit  = (float) $this->option('lock-profit');
        $atrPeriod   = (int)   $this->option('atr-period');
        $atrMult     = (float) $this->option('atr-mult');
        $expiryBars  = (int)   $this->option('expiry-bars');
        $maxPerDay   = (int)   $this->option('max-per-day');
        $wedgeFilter = (bool)  $this->option('wedge-filter');
        $wedgeRatio  = (float) $this->option('wedge-ratio');
        $fixedSL         = (float) $this->option('fixed-sl');
        $fixedTP         = (float) $this->option('fixed-tp');
        $trailActivation = (float) $this->option('trail-activation');

        $N       = count($m15);
        $warmup  = max($swingLook + $swingStr * 2 + 5, $atrPeriod + 2);
        $startIdx = $warmup;
        foreach ($m15 as $idx => $bar) {
            if ($bar[0] >= $fromTs && $idx >= $warmup) { $startIdx = $idx; break; }
        }

        if ($fixedSL > 0 && $fixedTP > 0) $version = 'v7.8';
        elseif ($fixedSL > 0)              $version = 'v7.7';
        else                               $version = 'v7.6';
        $slLabel  = $fixedSL > 0 ? "FixedSL=±{$fixedSL}" : "SL=Swing";
        $tpLabel  = $fixedTP > 0 ? "FixedTP=±{$fixedTP}" : "TP=Trail";
        $this->info("SWING BACKTEST {$version} — XAUUSD M15 | {$fromLabel} → {$toLabel}");
        $this->info("Sw={$swingStr}×{$swingLook} | Lock={$beTrigger}+{$lockProfit} | Trail≥{$trailActivation} | ATR({$atrPeriod})×{$atrMult} | {$slLabel} | {$tpLabel} | FO=" . ($foTol*100) . "% | Wedge=" . ($wedgeFilter?'ON':'OFF') . " | MaxDay={$maxPerDay} | Risk=\${$risk}");
        $this->line(str_repeat('─', 72));

        $trades       = [];
        $pending      = null;
        $position     = null;
        $cooldownBuy  = 0;
        $cooldownSell = 0;
        $todayCount   = 0;
        $todayDate    = '';

        for ($i = $startIdx; $i < $N; $i++) {
            $bar      = $m15[$i];
            $ts       = $bar[0];
            $barHigh  = $bar[2];
            $barLow   = $bar[3];
            $barClose = $bar[4];
            if ($ts > $toTs) break;

            // Reset quota ngày
            $today = gmdate('Y-m-d', $ts / 1000);
            if ($today !== $todayDate) { $todayDate = $today; $todayCount = 0; }

            // ── Dynamic ATR trailing distance (bar i-1 = last closed bar) ─
            $dynamicTrail = $this->computeBarATR($m15, $i, $atrPeriod) * $atrMult;
            if ($dynamicTrail <= 0) $dynamicTrail = 5.0; // fallback an toàn

            // ── 1. Quản lý position đang mở ───────────────────────
            if ($position !== null) {
                $entry       = $position['entry'];
                $currentSL   = $position['currentSL'];
                $beActivated = $position['beActivated'];
                $swingRef    = $position['swingRef'];
                $swingRange  = $position['swingRange'];
                $initSL      = $position['sl'];
                $slDist      = abs($entry - $initSL);
                $outcome     = null;
                $closePrice  = null;

                if ($position['dir'] === 'BUY') {
                    $tol = $swingRange * $foTol;
                    $hasTP = isset($position['tp']) && $position['tp'] > 0;
                    // Hard TP check TRƯỚC SL
                    if ($hasTP && $barHigh >= $position['tp']) {
                        $closePrice = $position['tp'];
                        $outcome    = 'TP_WIN';
                    } elseif ($barLow <= $currentSL) {
                        // SL hit — phân loại outcome theo mức SL hiện tại
                        $closePrice = $currentSL;
                        if (!$beActivated)                      $outcome = 'LOSS';
                        elseif ($currentSL <= $entry + 0.05)   $outcome = 'BE_EXIT';
                        else                                    $outcome = 'TRAIL_WIN';
                    } elseif ($foTol > 0 && $barClose < $swingRef - $tol) {
                        $closePrice = $barClose;
                        $outcome    = 'FAKEOUT';
                    } else {
                        // Phase A: Instant ATR Trail (LUÔN chạy từ bar 1)
                        $trailSL = $barHigh - $dynamicTrail;
                        if ($trailSL > $position['currentSL'])
                            $position['currentSL'] = $trailSL;

                        // Phase B: Early Lock (một lần khi lãi >= BE_Trigger)
                        if (!$beActivated && ($barHigh - $entry) >= $beTrigger) {
                            $lockSL = $entry + $lockProfit;
                            if ($position['currentSL'] < $lockSL)
                                $position['currentSL'] = $lockSL;
                            $position['beActivated'] = true;
                            $beActivated = true;
                            $this->line(sprintf("    %s  BUY  LOCK  profit≥%.1f → SL→%.2f (+%.2f)",
                                date('m-d H:i', $ts/1000), $beTrigger, $position['currentSL'], $lockProfit));
                        }

                        // Phase C: Trail Activation (một lần khi lãi >= Trail_Activation)
                        if (!$position['trailActivated'] && ($barHigh - $entry) >= $trailActivation) {
                            $activeSL = $entry + $beTrigger;
                            if ($position['currentSL'] < $activeSL) {
                                $position['currentSL'] = $activeSL;
                                $this->line(sprintf("    %s  BUY  TRAIL_ACT profit≥%.1f → SL→%.2f",
                                    date('m-d H:i', $ts/1000), $trailActivation, $position['currentSL']));
                            }
                            $position['trailActivated'] = true;
                        }
                    }
                } else {
                    // SELL
                    $tol = $swingRange * $foTol;
                    $hasTP = isset($position['tp']) && $position['tp'] > 0;
                    // Hard TP check TRƯỚC SL
                    if ($hasTP && $barLow <= $position['tp']) {
                        $closePrice = $position['tp'];
                        $outcome    = 'TP_WIN';
                    } elseif ($barHigh >= $currentSL) {
                        $closePrice = $currentSL;
                        if (!$beActivated)                      $outcome = 'LOSS';
                        elseif ($currentSL >= $entry - 0.05)   $outcome = 'BE_EXIT';
                        else                                    $outcome = 'TRAIL_WIN';
                    } elseif ($foTol > 0 && $barClose > $swingRef + $tol) {
                        $closePrice = $barClose;
                        $outcome    = 'FAKEOUT';
                    } else {
                        // Phase A: Instant ATR Trail (LUÔN chạy từ bar 1)
                        $trailSL = $barLow + $dynamicTrail;
                        if ($trailSL < $position['currentSL'])
                            $position['currentSL'] = $trailSL;

                        // Phase B: Early Lock (một lần khi lãi >= BE_Trigger)
                        if (!$beActivated && ($entry - $barLow) >= $beTrigger) {
                            $lockSL = $entry - $lockProfit;
                            if ($position['currentSL'] > $lockSL)
                                $position['currentSL'] = $lockSL;
                            $position['beActivated'] = true;
                            $beActivated = true;
                            $this->line(sprintf("    %s  SELL LOCK  profit≥%.1f → SL→%.2f (-%.2f)",
                                date('m-d H:i', $ts/1000), $beTrigger, $position['currentSL'], $lockProfit));
                        }

                        // Phase C: Trail Activation (một lần khi lãi >= Trail_Activation)
                        if (!$position['trailActivated'] && ($entry - $barLow) >= $trailActivation) {
                            $activeSL = $entry - $beTrigger;
                            if ($position['currentSL'] > $activeSL) {
                                $position['currentSL'] = $activeSL;
                                $this->line(sprintf("    %s  SELL TRAIL_ACT profit≥%.1f → SL→%.2f",
                                    date('m-d H:i', $ts/1000), $trailActivation, $position['currentSL']));
                            }
                            $position['trailActivated'] = true;
                        }
                    }
                }

                if ($outcome !== null) {
                    $pnlPts = $position['dir'] === 'BUY'
                        ? ($closePrice - $entry)
                        : ($entry - $closePrice);
                    $pnl       = $slDist > 0 ? round($pnlPts / $slDist * $risk, 2) : 0;
                    $rMultiple = $slDist > 0 ? round($pnlPts / $slDist, 2) : 0;
                    $capital  += $pnl;
                    $sign      = $pnl >= 0 ? '+' : '';
                    $beTag     = $position['beActivated'] ? ' [BE]' : '';
                    $this->line(sprintf("  %s  %s  %-10s | P&L %s%.2f | R=%+.2f | Cap %.2f%s",
                        date('m-d H:i',$ts/1000), $position['dir'], $outcome,
                        $sign, $pnl, $rMultiple, $capital, $beTag));
                    $trades[] = [
                        'dir'        => $position['dir'],
                        'outcome'    => $outcome,
                        'pnl'        => $pnl,
                        'rMultiple'  => $rMultiple,
                        'capital'    => $capital,
                        'ts'         => $ts,
                        'entry'      => $entry,
                        'close'      => $closePrice,
                        'beActivated'=> $position['beActivated'],
                        'finalSL'    => $position['currentSL'],
                        'swingRange' => $swingRange,
                    ];
                    $cd = ($outcome === 'FAKEOUT') ? $i + 4 : $i + $expiryBars;
                    if ($position['dir'] === 'BUY')  $cooldownBuy  = $cd;
                    else                              $cooldownSell = $cd;
                    $position = null;
                }
                continue;
            }

            // ── 2. Check pending fill / expiry ─────────────────────
            if ($pending !== null) {
                if ($i >= $pending['expiryIdx']) {
                    $this->line(sprintf("  %s  %s  EXPIRED", date('m-d H:i',$ts/1000), $pending['dir']));
                    $pending = null;
                } elseif ($pending['dir'] === 'BUY'  && $barHigh >= $pending['entry']) {
                    $position = array_merge($pending, ['currentSL' => $pending['sl'], 'beActivated' => false, 'trailActivated' => false]);
                    $pending  = null;
                    $todayCount++;
                    $tpInfo   = isset($position['tp']) && $position['tp'] > 0 ? " TP=".number_format($position['tp'],2) : '';
                    $this->line(sprintf("  %s  BUY  FILLED    @%.2f SL=%.2f swR=%.2f%s",
                        date('m-d H:i',$ts/1000), $position['entry'], $position['sl'], $position['swingRange'], $tpInfo));
                } elseif ($pending['dir'] === 'SELL' && $barLow  <= $pending['entry']) {
                    $position = array_merge($pending, ['currentSL' => $pending['sl'], 'beActivated' => false, 'trailActivated' => false]);
                    $pending  = null;
                    $todayCount++;
                    $tpInfo   = isset($position['tp']) && $position['tp'] > 0 ? " TP=".number_format($position['tp'],2) : '';
                    $this->line(sprintf("  %s  SELL FILLED    @%.2f SL=%.2f swR=%.2f%s",
                        date('m-d H:i',$ts/1000), $position['entry'], $position['sl'], $position['swingRange'], $tpInfo));
                }
                if ($pending !== null) continue;
            }
            if ($pending !== null || $position !== null) continue;

            // ── 3. Quota ngày ──────────────────────────────────────
            if ($maxPerDay > 0 && $todayCount >= $maxPerDay) continue;

            // ── 4. Tìm Fractal Swings ──────────────────────────────
            [$sh1, $sh2, $sl1, $sl2] = $this->findFractalSwings($m15, $i, $swingStr, $swingLook);
            if ($sh1 <= 0 || $sl1 <= 0) continue;
            $swingRange = $sh1 - $sl1;
            if ($swingRange <= 0) continue;

            // ── 5. Smart Trend & Wedge Filter ─────────────────────
            $allowBuy  = true;
            $allowSell = true;
            $trendTag  = 'BOTH';
            if ($wedgeFilter && $sh2 > 0 && $sl2 > 0) {
                $downtrend = ($sh1 < $sh2 && $sl1 < $sl2);
                $uptrend   = ($sh1 > $sh2 && $sl1 > $sl2);
                if ($downtrend) {
                    $dH = $sh2 - $sh1; $dL = $sl2 - $sl1;
                    if ($dH > 0 && $dL < $dH * $wedgeRatio) {
                        $allowBuy = true; $allowSell = false; $trendTag = 'DOWN+WEDGE→BUY';
                    } else {
                        $allowBuy = false; $allowSell = true; $trendTag = 'DOWNTREND→SELL';
                    }
                } elseif ($uptrend) {
                    $dH = $sh1 - $sh2; $dL = $sl1 - $sl2;
                    if ($dH > 0 && $dH < $dL * $wedgeRatio) {
                        $allowBuy = false; $allowSell = true; $trendTag = 'UP+WEDGE→SELL';
                    } else {
                        $allowBuy = true; $allowSell = false; $trendTag = 'UPTREND→BUY';
                    }
                } else {
                    $trendTag = 'SIDEWAY→BOTH';
                }
            }

            // ── 6. Đặt pending ─────────────────────────────────────
            $entryBuy  = $sh1 + $entryBuf;
            $entrySell = $sl1 - $entryBuf;
            // v7.7: Fixed SL từ entry; v7.6: SL từ swing đối diện
            $slBuy     = $fixedSL > 0 ? $entryBuy  - $fixedSL : $sl1 - $slBuf;
            $slSell    = $fixedSL > 0 ? $entrySell + $fixedSL : $sh1 + $slBuf;
            // v7.8: Hard TP từ entry
            $tpBuy     = $fixedTP > 0 ? $entryBuy  + $fixedTP : 0.0;
            $tpSell    = $fixedTP > 0 ? $entrySell - $fixedTP : 0.0;

            if ($allowBuy && $entryBuy > $barClose && $i >= $cooldownBuy) {
                $this->line(sprintf("  %s  →BUY  @%.2f SL=%.2f%s | SH=%.2f SL1=%.2f range=%.2f [%s]",
                    date('m-d H:i',$ts/1000), $entryBuy, $slBuy,
                    $tpBuy > 0 ? " TP=".number_format($tpBuy,2) : '',
                    $sh1, $sl1, $swingRange, $trendTag));
                $pending = ['dir'=>'BUY','entry'=>$entryBuy,'sl'=>$slBuy,'tp'=>$tpBuy,
                            'swingRef'=>$sh1,'swingRange'=>$swingRange,'expiryIdx'=>$i+$expiryBars];
            } elseif ($allowSell && $entrySell < $barClose && $i >= $cooldownSell) {
                $this->line(sprintf("  %s  →SELL @%.2f SL=%.2f%s | SH=%.2f SL1=%.2f range=%.2f [%s]",
                    date('m-d H:i',$ts/1000), $entrySell, $slSell,
                    $tpSell > 0 ? " TP=".number_format($tpSell,2) : '',
                    $sh1, $sl1, $swingRange, $trendTag));
                $pending = ['dir'=>'SELL','entry'=>$entrySell,'sl'=>$slSell,'tp'=>$tpSell,
                            'swingRef'=>$sl1,'swingRange'=>$swingRange,'expiryIdx'=>$i+$expiryBars];
            }
        }
        if ($position !== null) {
            $trades[] = array_merge($position, ['outcome'=>'OPEN','pnl'=>0,'rMultiple'=>0,'capital'=>$capital,'ts'=>0,'close'=>null]);
        }

        // ── Kết quả ───────────────────────────────────────────────
        $tpWins    = array_filter($trades, fn($t) => $t['outcome'] === 'TP_WIN');
        $trailWins = array_filter($trades, fn($t) => $t['outcome'] === 'TRAIL_WIN');
        $beExits   = array_filter($trades, fn($t) => $t['outcome'] === 'BE_EXIT');
        $losses    = array_filter($trades, fn($t) => $t['outcome'] === 'LOSS');
        $fakeouts  = array_filter($trades, fn($t) => $t['outcome'] === 'FAKEOUT');
        $open      = array_filter($trades, fn($t) => $t['outcome'] === 'OPEN');
        $closed    = count($tpWins) + count($trailWins) + count($beExits) + count($losses) + count($fakeouts);
        $profitable = count(array_filter($trades, fn($t) => $t['pnl'] > 0));
        $wr         = $closed > 0 ? round($profitable / $closed * 100, 1) : 0;
        $netPnl     = array_sum(array_column($trades, 'pnl'));
        $avgR       = count($trailWins) > 0 ? round(array_sum(array_column(array_values($trailWins), 'rMultiple')) / count($trailWins), 2) : 0;
        $tpPnl      = array_sum(array_column(array_values($tpWins), 'pnl'));
        $foPnl      = array_sum(array_column(array_values($fakeouts), 'pnl'));
        $beWithTrail = count(array_filter($trades, fn($t) => $t['beActivated']));

        $this->line(str_repeat('═', 72));
        $this->info("SWING {$version} RESULTS — XAUUSD M15 | {$fromLabel} → {$toLabel}");
        $this->line(str_repeat('─', 72));
        $this->line("  Tổng closed    : {$closed}  (OPEN: " . count($open) . ")");
        if ($fixedTP > 0) {
            $this->line("  TP_WIN (Hard)  : <fg=green>" . count($tpWins) . "</>  (P&L +" . number_format($tpPnl,2) . "\$)");
        }
        $this->line("  TRAIL_WIN      : <fg=green>" . count($trailWins) . "</>  (avg R = +{$avgR})");
        $this->line("  BE_EXIT        : " . count($beExits) . "  (hòa vốn, không mất tiền)");
        $this->line("  LOSS           : <fg=red>" . count($losses) . "</>");
        $foPnlSign = $foPnl >= 0 ? '+' : '';
        $this->line("  FAKEOUT        : " . count($fakeouts) . "  (P&L {$foPnlSign}" . round($foPnl,2) . "\$)");
        $this->line("  Trades có BE   : {$beWithTrail}/" . count($trades) . "  (đã khóa hòa vốn)");
        $wrColor = $wr >= 50 ? 'green' : 'red';
        $this->line("  Winrate (P>0)  : <fg={$wrColor}>{$wr}%</> ({$closed} closed)");
        $pnlColor = $netPnl >= 0 ? 'green' : 'red';
        $pnlSign  = $netPnl >= 0 ? '+' : '';
        $this->line("  Net P&L        : <fg={$pnlColor}>{$pnlSign}" . number_format($netPnl,2) . " USD</>");
        $this->line("  Capital        : " . number_format($capital, 2) . " USD");
        $this->line("  Profit Factor  : " . $this->calcPF($trades));
        $this->line(str_repeat('═', 72));
        return 0;
    }

    // Tìm 2 fractal SwingHigh + 2 fractal SwingLow gần nhất
    // Trả về [sh1, sh2, sl1, sl2] — sh1/sl1 = gần nhất, sh2/sl2 = cũ hơn
    private function findFractalSwings(array $m15, int $curIdx, int $sw, int $lookback): array
    {
        $sh1 = 0.0; $sh2 = 0.0; $sl1 = 0.0; $sl2 = 0.0;
        $foundH = 0; $foundL = 0;

        // bar[curIdx] = nến vừa đóng. Fractal tại j cần j+sw <= curIdx và j-sw >= 0.
        $start = $curIdx - $sw;
        $end   = max($sw, $curIdx - $lookback - $sw);

        for ($j = $start; $j >= $end && ($foundH < 2 || $foundL < 2); $j--) {
            if ($j - $sw < 0 || $j + $sw >= count($m15)) break;
            $h = $m15[$j][2]; $l = $m15[$j][3];
            $isH = ($foundH < 2); $isL = ($foundL < 2);
            for ($k = 1; $k <= $sw; $k++) {
                if ($isH && ($m15[$j+$k][2] >= $h || $m15[$j-$k][2] >= $h)) $isH = false;
                if ($isL && ($m15[$j+$k][3] <= $l || $m15[$j-$k][3] <= $l)) $isL = false;
            }
            if ($isH) { if ($foundH === 0) $sh1 = $h; else $sh2 = $h; $foundH++; }
            if ($isL) { if ($foundL === 0) $sl1 = $l; else $sl2 = $l; $foundL++; }
        }
        return [$sh1, $sh2, $sl1, $sl2];
    }

    /**
     * ATR đơn giản (simple average of TR) cho bar i-1 (last closed bar khi đang ở bar i).
     * bars: [[ts,open,high,low,close], ...]
     */
    private function computeBarATR(array $bars, int $i, int $period): float
    {
        $end = $i - 1; // bar vừa đóng
        $start = $end - $period + 1;
        if ($start < 1 || $end >= count($bars)) return 0.0;

        $sum = 0.0;
        for ($j = $start; $j <= $end; $j++) {
            $h  = $bars[$j][2];
            $l  = $bars[$j][3];
            $pc = $bars[$j - 1][4]; // close của bar trước
            $sum += max($h - $l, abs($h - $pc), abs($l - $pc));
        }
        return $sum / $period;
    }
}
