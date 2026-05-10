<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BacktestRun extends Model
{
    protected $fillable = [
        'symbol', 'timeframe', 'htf', 'from_date', 'to_date', 'method',
        'rr', 'risk', 'capital', 'adx_threshold', 'min_confidence',
        'use_session', 'use_ai', 'ai_min', 'use_struct_exit', 'logic_hash',
        'signals_count', 'filled_count', 'win_count', 'loss_count',
        'expired_count', 'struct_exit_count', 'fill_rate', 'winrate',
        'pnl', 'capital_end', 'run_duration_ms', 'signals_json',
    ];

    protected $casts = [
        'use_session'     => 'boolean',
        'use_ai'          => 'boolean',
        'use_struct_exit' => 'boolean',
        'from_date'       => 'date',
        'to_date'         => 'date',
    ];

    public static function findCached(array $params, string $logicHash): ?self
    {
        return self::where('symbol',         $params['symbol'])
            ->where('timeframe',      $params['timeframe'])
            ->where('htf',            $params['htf'])
            ->where('from_date',      $params['from_date'])
            ->where('to_date',        $params['to_date'])
            ->where('method',         $params['method'])
            ->where('rr',             $params['rr'])
            ->where('risk',           $params['risk'])
            ->where('capital',        $params['capital'])
            ->where('adx_threshold',  $params['adx_threshold'])
            ->where('min_confidence', $params['min_confidence'])
            ->where('use_session',    $params['use_session'])
            ->where('use_ai',         $params['use_ai'])
            ->where('use_struct_exit',$params['use_struct_exit'])
            ->where('logic_hash',     $logicHash)
            ->latest()
            ->first();
    }
}
