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
        'order_type',
        'lot_size',
        'is_auto_traded',
        'auto_traded_at',
        'mt5_ticket',
        'mt5_close_price',
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
        'lot_size'                 => 'decimal:4',
        'is_auto_traded'           => 'boolean',
        'auto_traded_at'           => 'datetime',
        'mt5_ticket'               => 'integer',
        'mt5_close_price'          => 'decimal:8',
    ];
}
