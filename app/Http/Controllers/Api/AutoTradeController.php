<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TradingSignal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AutoTradeController extends Controller
{
    /**
     * GET /api/auto-trade/signals
     * Trả về các PENDING signals chưa được auto-trade, tạo trong 30 phút gần nhất.
     */
    public function pendingSignals(): JsonResponse
    {
        $signals = TradingSignal::where('status', 'PENDING')
            ->where('is_auto_traded', false)
            ->where('created_at', '>=', now()->subMinutes(30))
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get(['id', 'symbol', 'type', 'entry_price', 'tp_price', 'sl_price', 'order_type', 'lot_size', 'timeframe', 'created_at']);

        $result = $signals->map(function ($s) {
            return [
                'id'          => $s->id,
                'symbol'      => $s->symbol,
                'type'        => $s->type,
                'entry_price' => (float) $s->entry_price,
                'tp_price'    => (float) $s->tp_price,
                'sl_price'    => (float) $s->sl_price,
                'order_type'  => $s->order_type ?? 'STOP',
                'lot_size'    => $s->lot_size ? (float) $s->lot_size : 0.01,
                'timeframe'   => $s->timeframe,
                'created_at'  => $s->created_at?->toIso8601String(),
            ];
        });

        return response()->json($result);
    }

    /**
     * POST /api/auto-trade/signals/{id}/executed
     * Đánh dấu signal đã được đặt lệnh trên MT5.
     */
    public function markExecuted(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'mt5_ticket' => 'required|integer',
        ]);

        $signal = TradingSignal::findOrFail($id);

        $signal->update([
            'is_auto_traded' => true,
            'auto_traded_at' => now(),
            'mt5_ticket'     => $request->integer('mt5_ticket'),
        ]);

        return response()->json(['success' => true, 'id' => $id]);
    }

    /**
     * POST /api/auto-trade/signals/{id}/closed
     * Cập nhật kết quả lệnh sau khi đóng trên MT5.
     */
    public function markClosed(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'status'      => 'required|in:WIN,LOSS',
            'close_price' => 'required|numeric',
        ]);

        $signal = TradingSignal::findOrFail($id);

        $signal->update([
            'status'          => $request->input('status'),
            'mt5_close_price' => $request->input('close_price'),
            'closed_price'    => $request->input('close_price'),
        ]);

        return response()->json(['success' => true]);
    }
}
