<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TradingSignal extends Model
{
    protected $fillable = [
        'symbol',
        'timeframe',
        'type',
        'entry_price',
        'tp_price',
        'sl_price',
        'winrate',
        'status',
        'reason',
        'notified_near_sl',
        'notified_near_tp',
        'notified_structure_break',
        'notified_tp',
        'notified_sl',
        'capital',
        'filled_at',
        'notified_expiry',
    ];

    protected $casts = [
        'notified_near_sl'         => 'boolean',
        'notified_near_tp'         => 'boolean',
        'notified_structure_break' => 'boolean',
        'notified_tp'              => 'boolean',
        'notified_sl'              => 'boolean',
        'notified_expiry'          => 'boolean',
        'capital'                  => 'decimal:2',
        'filled_at'                => 'datetime',
    ];
}
