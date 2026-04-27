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
        'reason'
    ];
}
