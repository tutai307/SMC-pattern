<?php

namespace App\Http\Controllers;

use App\Events\SignalStatusChanged;
use App\Services\BinanceService;
use App\Services\PriceActionService;
use App\Services\TelegramService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class DashboardController extends Controller
{
    protected $binanceService;
    protected $priceActionService;
    protected $telegramService;

    public function __construct(BinanceService $binanceService, PriceActionService $priceActionService, TelegramService $telegramService)
    {
        $this->binanceService = $binanceService;
        $this->priceActionService = $priceActionService;
        $this->telegramService = $telegramService;
    }

    public function index()
    {
        $symbol    = strtoupper(preg_replace('/[^A-Z0-9]/i', '', request('symbol', 'XAGUSDT')));
        $symbol    = substr($symbol, 0, 20) ?: 'XAGUSDT';
        $timeframe = in_array(request('timeframe'), ['1m','5m','15m','1h','4h','1d']) ? request('timeframe') : '15m';
        $method    = in_array(request('method'), ['smc','elliot']) ? request('method') : 'smc';
        $capital   = request('capital') ? max(0, (float) request('capital')) : null;
        
        $klines = $this->binanceService->getKlines($symbol, $timeframe, 500);
        $currentPrice = $this->binanceService->getPrice($symbol);

        if (empty($klines) || $currentPrice === null) {
            abort(404);
        }

        $htf = '1h';
        if ($timeframe == '15m') $htf = '1h';
        if ($timeframe == '1h') $htf = '4h';
        if ($timeframe == '4h') $htf = '1d';

        $klinesHTF = $this->binanceService->getKlines($symbol, $htf, 50);

        // Phân tích AI
        $analysis = $this->priceActionService->analyze($klines, $klinesHTF, $method, $symbol, $timeframe);

        // Lưu tín hiệu nếu có và người dùng yêu cầu (qua click reload)
        if ($analysis['signal'] && request('propose')) {
            try {
                $signal = \App\Models\TradingSignal::create([
                    'symbol'       => $symbol,
                    'timeframe'    => $timeframe,
                    'type'         => str_starts_with($analysis['signal']['type'], 'MUA') ? 'LONG' : 'SHORT',
                    'entry_price'  => $analysis['signal']['entry'],
                    'tp_price'     => $analysis['signal']['tp'],
                    'sl_price'     => $analysis['signal']['sl'],
                    'winrate'      => $analysis['signal']['winrate'],
                    'reason'       => $analysis['signal']['reason'],
                    'capital'      => $capital,
                    'status'       => 'PENDING',
                ]);

                // Tính position sizing nếu có capital
                $positionSize = null;
                if ($signal->capital > 0) {
                    $positionSize = PriceActionService::calculatePositionSize(
                        balance:    (float) $signal->capital,
                        riskPercent: 2.0,
                        entry:      (float) $signal->entry_price,
                        stopLoss:   (float) $signal->sl_price,
                    );
                }

                // Gửi chi tiết lệnh qua Telegram ngay khi đề xuất
                $this->telegramService->sendNewSignal($signal, $currentPrice, $analysis['signal'] ?? [], $positionSize);
                broadcast(new SignalStatusChanged($signal));
                // Sau khi lưu xong, chuyển hướng để xoá tham số 'propose' khỏi URL
                return redirect()->route('dashboard', [
                    'symbol' => $symbol,
                    'timeframe' => $timeframe,
                    'method' => $method,
                    'capital' => request('capital')
                ])->with('success', 'Đã đề xuất lệnh thành công!');
            } catch (\Exception $e) {
                \Log::error("Lỗi lưu tín hiệu: " . $e->getMessage());
            }
        }

        // Kiểm tra chất lượng coin
        $coinQuality = $this->binanceService->getCoinQuality($symbol);

        // Phán quyết: ENTER | CAUTION | SKIP
        $verdict = $this->computeVerdict($analysis['signal'] ?? null, $coinQuality);

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

        return view('welcome', compact('klines', 'symbol', 'currentPrice', 'analysis', 'timeframe', 'signals', 'coinStats', 'method', 'coinQuality', 'verdict'));
    }

    private function computeVerdict(?array $signal, array $coinQuality): array
    {
        $decision = 'NEUTRAL';
        $reasons  = [];

        if ($coinQuality['status'] === 'AVOID') {
            $decision  = 'SKIP';
            $reasons[] = 'Coin rác / thanh khoản cực thấp';
        }

        if (!$signal) {
            return ['decision' => $decision ?: 'NEUTRAL', 'reasons' => $reasons];
        }

        $entry = (float) ($signal['entry'] ?? 0);
        $sl    = (float) ($signal['sl']    ?? 0);
        $tp    = (float) ($signal['tp']    ?? 0);

        if ($entry > 0 && $sl > 0) {
            $slPct = abs($entry - $sl) / $entry;

            if ($slPct < 0.008) {
                $decision  = 'SKIP';
                $reasons[] = 'SL quá chật (<0.8%) — dễ bị noise quét';
            }

            $tpPct   = $tp > 0 ? abs($tp - $entry) / $entry : 0;
            $rrRatio = $slPct > 0 ? round($tpPct / $slPct, 1) : 0;

            if ($rrRatio > 0 && $rrRatio < 1.5 && $decision !== 'SKIP') {
                $decision  = 'CAUTION';
                $reasons[] = "R:R thấp (1:{$rrRatio})";
            }
        }

        $aiScore = $signal['ai_score'] ?? null;
        if ($aiScore !== null && $aiScore < 50) {
            $decision  = 'SKIP';
            $reasons[] = "AI score thấp ({$aiScore}/100)";
        }

        $aiRec = strtolower($signal['ai_recommendation'] ?? '');
        if (str_contains($aiRec, 'bỏ qua') || str_contains($aiRec, 'không vào') || str_contains($aiRec, 'skip') || str_contains($aiRec, 'avoid')) {
            if ($decision !== 'SKIP') {
                $decision  = 'SKIP';
                $reasons[] = 'AI khuyên bỏ qua';
            }
        }

        if ($coinQuality['status'] === 'CAUTION' && $decision === 'NEUTRAL') {
            $decision  = 'CAUTION';
            $reasons[] = 'Thanh khoản coin trung bình';
        }

        if ($decision === 'NEUTRAL') {
            if (($signal['winrate'] ?? 0) >= 65 && ($aiScore === null || $aiScore >= 65)) {
                $decision  = 'ENTER';
                $reasons[] = 'Setup hợp lệ — coin thanh khoản tốt';
            } else {
                $decision  = 'CAUTION';
                $reasons[] = 'Tín hiệu chưa đủ mạnh để tự tin vào';
            }
        }

        return compact('decision', 'reasons');
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

    public function resetSignal($id)
    {
        $signal = \App\Models\TradingSignal::findOrFail($id);
        $signal->update([
            'status'                   => 'PENDING',
            'filled_at'                => null,
            'notified_tp'              => false,
            'notified_sl'              => false,
            'notified_near_tp'         => false,
            'notified_near_sl'         => false,
            'notified_structure_break' => false,
        ]);
        return back()->with('success', "Lệnh #{$id} đã reset về PENDING — bot sẽ theo dõi lại.");
    }

    public function fillSignal($id)
    {
        $signal = \App\Models\TradingSignal::where('id', $id)->where('status', 'PENDING')->firstOrFail();

        if (!$signal->filled_at) {
            $signal->update(['filled_at' => now()]);
        }

        return back()->with('success', "Lệnh #{$id} {$signal->symbol} đã được đánh dấu KHỚP — bot bắt đầu theo dõi.");
    }

    public function analysisJson()
    {
        $symbol    = strtoupper(preg_replace('/[^A-Z0-9]/i', '', request('symbol', 'XAGUSDT')));
        $symbol    = substr($symbol, 0, 20) ?: 'XAGUSDT';
        $timeframe = in_array(request('timeframe'), ['1m','5m','15m','1h','4h','1d']) ? request('timeframe') : '15m';
        $method    = in_array(request('method'), ['smc','elliot']) ? request('method') : 'smc';

        $klines       = $this->binanceService->getKlines($symbol, $timeframe, 500);
        $currentPrice = $this->binanceService->getPrice($symbol);

        if (empty($klines) || $currentPrice === null) {
            return response()->json(['error' => 'Không lấy được dữ liệu'], 502);
        }

        $htf = match ($timeframe) { '15m' => '1h', '1h' => '4h', default => '1d' };
        $klinesHTF   = $this->binanceService->getKlines($symbol, $htf, 50);
        $analysis    = $this->priceActionService->analyze($klines, $klinesHTF, $method, $symbol, $timeframe);
        $coinQuality = $this->binanceService->getCoinQuality($symbol);
        $verdict     = $this->computeVerdict($analysis['signal'] ?? null, $coinQuality);

        return response()->json([
            'symbol'       => $symbol,
            'timeframe'    => $timeframe,
            'method'       => $method,
            'currentPrice' => $currentPrice,
            'klines'       => $klines,
            'analysis'     => $analysis,
            'coinQuality'  => $coinQuality,
            'verdict'      => $verdict,
        ]);
    }

    public function advisor(Request $request)
    {
        $symbol    = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $request->input('symbol', 'BTCUSDT')));
        $timeframe = in_array($request->input('timeframe'), ['1m','5m','15m','1h','4h','1d']) ? $request->input('timeframe') : '15m';
        $type      = in_array(strtoupper($request->input('type')), ['LONG','SHORT']) ? strtoupper($request->input('type')) : 'LONG';
        $entry     = (float) $request->input('entry');
        $sl        = $request->input('sl') ? (float) $request->input('sl') : null;
        $tp        = $request->input('tp') ? (float) $request->input('tp') : null;

        if ($entry <= 0) {
            return response()->json(['error' => 'Giá entry không hợp lệ'], 422);
        }

        $currentPrice = $this->binanceService->getPrice($symbol);
        if ($currentPrice === null) {
            return response()->json(['error' => 'Không lấy được giá ' . $symbol], 502);
        }

        $klines    = $this->binanceService->getKlines($symbol, $timeframe, 100);
        $htf       = match($timeframe) { '15m' => '1h', '1h' => '4h', default => '1d' };
        $klinesHTF = $this->binanceService->getKlines($symbol, $htf, 50);

        $advice = $this->priceActionService->adviseOpenPosition(
            $klines, $klinesHTF, $symbol, $timeframe, $type, $entry, $sl, $tp, (float) $currentPrice
        );

        return response()->json(array_merge($advice, ['current_price' => $currentPrice]));
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
