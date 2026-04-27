<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Services\BinanceService;
use App\Services\PriceActionService;

class DashboardController extends Controller
{
    protected $binanceService;
    protected $priceActionService;

    public function __construct(BinanceService $binanceService, PriceActionService $priceActionService)
    {
        $this->binanceService = $binanceService;
        $this->priceActionService = $priceActionService;
    }

    public function index()
    {
        $symbol = request('symbol', 'XAGUSDT');
        $timeframe = request('timeframe', '15m');
        $method = request('method', 'smc');
        
        $klines = $this->binanceService->getKlines($symbol, $timeframe, 500);
        $currentPrice = $this->binanceService->getPrice($symbol);

        $htf = '1h';
        if ($timeframe == '15m') $htf = '1h';
        if ($timeframe == '1h') $htf = '4h';
        if ($timeframe == '4h') $htf = '1d';

        $klinesHTF = $this->binanceService->getKlines($symbol, $htf, 50);

        // Phân tích AI
        $analysis = $this->priceActionService->analyze($klines, $klinesHTF, $method);

        // Lưu tín hiệu nếu có và người dùng yêu cầu (qua click reload)
        if ($analysis['signal'] && request('propose')) {
            try {
                \App\Models\TradingSignal::create([
                    'symbol' => $symbol,
                    'timeframe' => $timeframe,
                    'type' => $analysis['signal']['type'] == 'MUA' ? 'LONG' : 'SHORT',
                    'entry_price' => $analysis['signal']['entry'],
                    'tp_price' => $analysis['signal']['tp'],
                    'sl_price' => $analysis['signal']['sl'],
                    'winrate' => $analysis['signal']['winrate'],
                    'reason' => $analysis['signal']['reason'],
                    'status' => 'PENDING'
                ]);
                // Sau khi lưu xong, chuyển hướng để xoá tham số 'propose' khỏi URL
                return redirect()->route('dashboard', [
                    'symbol' => $symbol,
                    'timeframe' => $timeframe,
                    'method' => $method
                ])->with('success', 'Đã đề xuất lệnh thành công!');
            } catch (\Exception $e) {
                \Log::error("Lỗi lưu tín hiệu: " . $e->getMessage());
            }
        }

        // Cập nhật trạng thái các lệnh đang chờ (PENDING)
        $this->updateSignalStatuses($klines);

        // Lấy 10 lệnh gần nhất của ĐỒNG COIN ĐANG XEM
        $signals = \App\Models\TradingSignal::where('symbol', $symbol)
                    ->orderBy('created_at', 'desc')
                    ->limit(10)
                    ->get();

        // Tính toán thống kê CHỈ CHO ĐỒNG COIN ĐANG XEM
        $coinStats = \App\Models\TradingSignal::select('symbol')
            ->selectRaw("COUNT(*) as total")
            ->selectRaw("SUM(CASE WHEN status = 'WIN' THEN 1 ELSE 0 END) as wins")
            ->selectRaw("SUM(CASE WHEN status = 'LOSS' THEN 1 ELSE 0 END) as losses")
            ->whereIn('status', ['WIN', 'LOSS'])
            ->where('symbol', $symbol) // Lọc theo coin đang soi
            ->groupBy('symbol')
            ->get();

        return view('welcome', compact('klines', 'symbol', 'currentPrice', 'analysis', 'timeframe', 'signals', 'coinStats', 'method'));
    }

    private function updateSignalStatuses($klines)
    {
        $pendingSignals = \App\Models\TradingSignal::where('status', 'PENDING')->get();
        if ($pendingSignals->isEmpty()) return;

        foreach ($pendingSignals as $signal) {
            $symbol = $signal->symbol;
            $timeframe = $signal->timeframe;
            $startTime = $signal->created_at->timestamp * 1000; // ms

            // Tải klines từ lúc tạo lệnh
            $historicalKlines = $this->binanceService->getKlines($symbol, $timeframe, 1000, $startTime);
            
            if (empty($historicalKlines)) continue;

            $maxHigh = -1;
            $minLow = 999999999;
            $hitStatus = null;

            foreach ($historicalKlines as $k) {
                $high = (float)$k[2];
                $low = (float)$k[3];

                if ($signal->type == 'LONG') {
                    if ($high >= $signal->tp_price) {
                        $hitStatus = 'WIN';
                        break; 
                    }
                    if ($low <= $signal->sl_price) {
                        $hitStatus = 'LOSS';
                        break;
                    }
                } else { // SHORT
                    if ($low <= $signal->tp_price) {
                        $hitStatus = 'WIN';
                        break;
                    }
                    if ($high >= $signal->sl_price) {
                        $hitStatus = 'LOSS';
                        break;
                    }
                }
            }

            if ($hitStatus) {
                $signal->update(['status' => $hitStatus]);
            }
        }
    }

    public function academy()
    {
        return view('academy');
    }

    public function planner()
    {
        return view('planner');
    }

    public function deleteSignal($id)
    {
        \App\Models\TradingSignal::destroy($id);
        return back()->with('success', 'Đã xoá lệnh.');
    }

    public function bulkDelete(Request $request)
    {
        $ids = $request->input('ids', []);
        if (!empty($ids)) {
            \App\Models\TradingSignal::whereIn('id', $ids)->delete();
            return back()->with('success', 'Đã xoá các lệnh được chọn.');
        }
        return back()->with('error', 'Chưa chọn lệnh nào.');
    }

    public function clearAllSignals()
    {
        \App\Models\TradingSignal::truncate();
        return back()->with('success', 'Đã làm sạch lịch sử.');
    }
}
