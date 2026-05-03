<?php

namespace App\Events;

use App\Models\TradingSignal;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SignalStatusChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public TradingSignal $signal) {}

    public function broadcastOn(): array
    {
        return [new Channel('signals')];
    }

    public function broadcastAs(): string
    {
        return 'signal.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'id'        => $this->signal->id,
            'status'    => $this->signal->status,
            'symbol'    => $this->signal->symbol,
            'type'      => $this->signal->type,
            'filled_at' => $this->signal->filled_at?->format('H:i d/m'),
        ];
    }
}
