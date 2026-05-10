<?php

namespace App\Console\Commands;

use App\Models\BacktestRun;
use Illuminate\Console\Command;

class BacktestListCommand extends Command
{
    protected $signature = 'backtest:list
        {--symbol= : Filter theo symbol}
        {--tf= : Filter theo timeframe}
        {--limit=20 : Số dòng hiển thị}';

    protected $description = 'Liệt kê và so sánh các backtest đã lưu';

    public function handle(): int
    {
        $currentHash = md5(file_get_contents(app_path('Services/PriceActionService.php')));

        $query = BacktestRun::query()->latest()->limit((int) $this->option('limit'));
        if ($sym = $this->option('symbol')) $query->where('symbol', strtoupper($sym));
        if ($tf  = $this->option('tf'))     $query->where('timeframe', $tf);

        $runs = $query->get();

        if ($runs->isEmpty()) {
            $this->warn('Chưa có backtest nào được lưu. Dùng --save khi chạy backtest:run.');
            return 0;
        }

        $this->info('═══════════════════════════════════════════════════════════════════════════════');
        $this->info('  BACKTEST LOG — ' . $runs->count() . ' runs');
        $this->info('═══════════════════════════════════════════════════════════════════════════════');
        $this->line(
            str_pad('ID',  5)  . str_pad('Symbol',  10) . str_pad('TF',  6)
            . str_pad('HTF', 6) . str_pad('From',    12) . str_pad('To',    12)
            . str_pad('RR',  5) . str_pad('Risk',    7)  . str_pad('WR%',   7)
            . str_pad('P&L',  9) . str_pad('Cap.',   10) . str_pad('Logic', 8) . 'Date'
        );
        $this->line(str_repeat('─', 110));

        foreach ($runs as $r) {
            $logicOk  = $r->logic_hash === $currentHash;
            $logicMark = $logicOk ? '<fg=green>✓ OK</>' : '<fg=yellow>⚠ OLD</>';
            $pnlColor = $r->pnl >= 0 ? 'green' : 'red';
            $pnlStr   = ($r->pnl >= 0 ? '+' : '') . number_format($r->pnl, 2);
            $wrColor  = $r->winrate >= 40 ? 'green' : ($r->winrate >= 33 ? 'yellow' : 'red');

            $this->line(
                str_pad($r->id,           5)
                . str_pad($r->symbol,     10)
                . str_pad($r->timeframe,   6)
                . str_pad($r->htf,         6)
                . str_pad($r->from_date->format('Y-m-d'), 12)
                . str_pad($r->to_date->format('Y-m-d'),   12)
                . str_pad('1:'.$r->rr,     5)
                . str_pad('$'.$r->risk,    7)
                . "<fg={$wrColor}>" . str_pad($r->winrate.'%', 7) . "</>"
                . "<fg={$pnlColor}>" . str_pad($pnlStr,        9) . "</>"
                . str_pad('$'.number_format($r->capital_end, 0), 10)
                . $logicMark . '   '
                . $r->created_at->format('m-d H:i')
            );
        }

        $this->line(str_repeat('─', 110));
        $this->line("  Ký hiệu: ✓ OK = logic hiện tại | ⚠ OLD = PriceActionService đã thay đổi sau khi chạy");
        $this->info('═══════════════════════════════════════════════════════════════════════════════');

        return 0;
    }
}
