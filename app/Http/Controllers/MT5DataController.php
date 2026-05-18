<?php

namespace App\Http\Controllers;

use App\Services\MarketDataService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * Nhận data từ MT5 EA (Exness) qua HTTP POST.
 *
 * Routes (routes/api.php):
 *   POST /api/mt5/klines  — EA push klines mỗi M15 bar đóng
 *   POST /api/mt5/tick    — EA push giá bid mỗi 10 giây
 *   GET  /api/mt5/status  — kiểm tra kết nối + data freshness
 */
class MT5DataController extends Controller
{
    public function __construct(private MarketDataService $marketData) {}

    // ──────────────────────────────────────────────────────────────
    // POST /api/mt5/klines
    // ──────────────────────────────────────────────────────────────

    /**
     * Payload từ EA:
     * {
     *   "secret":    "your_webhook_secret",
     *   "symbol":    "XAUUSD",
     *   "timeframe": "M15",
     *   "klines": [
     *     [1715600000000, 3321.50, 3325.80, 3318.20, 3323.40, 1250.5],
     *     ...
     *   ],
     *   "bid": 3323.40
     * }
     */
    public function receiveKlines(Request $request): JsonResponse
    {
        if (!$this->verifySecret($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $data      = $this->body($request);
        $symbol    = strtoupper(trim($data['symbol'] ?? ''));
        $timeframe = strtoupper(trim($data['timeframe'] ?? ''));
        $klines    = $data['klines'] ?? [];
        $bid       = (float) ($data['bid'] ?? 0);

        if (empty($symbol) || empty($timeframe) || empty($klines)) {
            return response()->json(['error' => 'Missing required fields'], 422);
        }

        if (!is_array($klines) || count($klines) < 2) {
            return response()->json(['error' => 'klines phải có ít nhất 2 nến'], 422);
        }

        // Validate format: mỗi kline phải có ít nhất 6 phần tử
        $sample = $klines[0];
        if (!is_array($sample) || count($sample) < 6) {
            return response()->json(['error' => 'kline format: [timestamp_ms, open, high, low, close, volume]'], 422);
        }

        $this->marketData->storeKlines($symbol, $timeframe, $klines);

        if ($bid > 0) {
            $this->marketData->storePrice($symbol, $bid);
        }

        \Log::info("MT5 push klines: {$symbol}/{$timeframe} — " . count($klines) . " bars, bid={$bid}");

        return response()->json([
            'ok'        => true,
            'symbol'    => $this->marketData->normalizeSymbol($symbol),
            'timeframe' => $timeframe,
            'count'     => count($klines),
            'stored_at' => now()->toISOString(),
        ]);
    }

    // ──────────────────────────────────────────────────────────────
    // POST /api/mt5/tick
    // ──────────────────────────────────────────────────────────────

    /**
     * Payload:
     * {
     *   "secret": "...",
     *   "symbol": "XAUUSD",
     *   "bid":    3323.40,
     *   "ask":    3323.60
     * }
     */
    public function receiveTick(Request $request): JsonResponse
    {
        if (!$this->verifySecret($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $data   = $this->body($request);
        $symbol = strtoupper(trim($data['symbol'] ?? ''));
        $bid    = (float) ($data['bid'] ?? 0);

        if (empty($symbol) || $bid <= 0) {
            return response()->json(['error' => 'Missing symbol or bid'], 422);
        }

        $this->marketData->storePrice($symbol, $bid);

        return response()->json(['ok' => true, 'bid' => $bid]);
    }

    // ──────────────────────────────────────────────────────────────
    // GET /api/mt5/status
    // ──────────────────────────────────────────────────────────────

    public function status(): JsonResponse
    {
        $symbols = explode(',', env('SCAN_SYMBOLS', 'XAUUSDT:15m,XAGUSDT:15m'));
        $result  = [];

        foreach ($symbols as $item) {
            [$sym, $tf] = array_pad(explode(':', trim($item)), 2, '15m');
            $sym = strtoupper($sym);
            $tf  = strtoupper($tf);

            $meta  = $this->marketData->getLastPushInfo($sym, $tf);
            $price = $this->marketData->getPrice($sym);

            $result[$sym] = [
                'has_data'   => $this->marketData->hasData($sym, $tf),
                'bid'        => $price,
                'pushed_at'  => $meta['pushed_at']  ?? null,
                'bar_count'  => $meta['count']       ?? 0,
                'last_close' => $meta['last_close']  ?? 0,
            ];
        }

        return response()->json([
            'status'     => 'ok',
            'server_time'=> now()->toISOString(),
            'pairs'      => $result,
        ]);
    }

    // ──────────────────────────────────────────────────────────────
    // PRIVATE
    // ──────────────────────────────────────────────────────────────

    /** MT5 WebRequest không set Content-Type đúng → phải parse raw body thủ công */
    private function body(Request $request): array
    {
        $parsed = $request->all();
        if (!empty($parsed)) return $parsed;
        return (array) (json_decode($request->getContent(), true) ?? []);
    }

    private function verifySecret(Request $request): bool
    {
        $expected = trim(config('services.mt5.webhook_secret', ''));
        if (empty($expected)) return true; // dev mode: không cần secret

        // Check: query param (?secret=) → JSON body → header
        $incoming = trim((string) $request->query('secret', ''));
        if (empty($incoming)) {
            $incoming = trim((string) $request->input('secret', ''));
        }
        if (empty($incoming)) {
            $raw = json_decode($request->getContent(), true);
            $incoming = trim((string) ($raw['secret'] ?? ''));
        }

        return $incoming === $expected
            || trim((string) $request->header('X-MT5-Secret', '')) === $expected;
    }
}
