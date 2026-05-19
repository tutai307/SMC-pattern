<?php

namespace App\Http\Controllers;

use App\Services\MarketDataService;
use App\Services\PriceActionService;
use App\Services\TelegramService;
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
    public function __construct(
        private MarketDataService  $marketData,
        private PriceActionService $priceAction,
        private TelegramService    $telegram,
    ) {}

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

        // JSON body (EA v1.2 gửi Content-Length) — fallback query params
        $json      = json_decode($request->getContent(), true) ?? [];
        $symbol    = strtoupper(trim($json['symbol']    ?? $request->query('symbol', '')));
        $timeframe = strtoupper(trim($json['timeframe'] ?? $request->query('timeframe', 'M15')));
        $bid       = (float) ($json['bid']     ?? $request->query('bid', 0));
        $klines    = $json['klines'] ?? [];

        if (empty($symbol) || empty($klines)) {
            return response()->json([
                'error'    => 'Missing required fields',
                'symbol'   => $symbol,
                'klines_n' => count($klines),
                'body_len' => strlen($request->getContent()),
            ], 422);
        }

        if (empty($timeframe)) $timeframe = 'M15';

        $this->marketData->storeKlines($symbol, $timeframe, $klines);
        if ($bid > 0) $this->marketData->storePrice($symbol, $bid);

        \Log::info("MT5 klines: {$symbol}/{$timeframe} — " . count($klines) . " bars");

        return response()->json([
            'ok'        => true,
            'symbol'    => $this->marketData->normalizeSymbol($symbol),
            'count'     => count($klines),
            'stored_at' => now('Asia/Ho_Chi_Minh')->format('Y-m-d H:i:s T'),
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

        // Tick: toàn bộ data trong query params (không có body)
        $symbol = strtoupper(trim($request->query('symbol', '')));
        $bid    = (float) $request->query('bid', 0);

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

        // Bulk data stats (dùng bởi FelixBulkExporter + backtest:v53)
        $bulk = [];
        foreach (['XAUUSDT', 'XAGUSDT'] as $sym) {
            foreach (['15m', '4h'] as $tf) {
                $key  = "mt5_bulk_{$sym}_{$tf}";
                $data = \Cache::get($key, []);
                if (!empty($data)) {
                    $bulk["{$sym}_{$tf}"] = [
                        'bars'  => count($data),
                        'from'  => date('Y-m-d', intdiv((int)$data[0][0], 1000)),
                        'to'    => date('Y-m-d', intdiv((int)end($data)[0], 1000)),
                    ];
                }
            }
        }

        return response()->json([
            'status'     => 'ok',
            'server_time'=> now('Asia/Ho_Chi_Minh')->format('Y-m-d H:i:s T'),
            'pairs'      => $result,
            'bulk'       => $bulk ?: null,
        ]);
    }

    // ──────────────────────────────────────────────────────────────
    // GET /api/mt5/scan  — chạy channel detection trên data hiện tại
    // ──────────────────────────────────────────────────────────────

    public function scanNow(): JsonResponse
    {
        $symbols = [['symbol' => 'XAUUSDT', 'tf' => '15m'], ['symbol' => 'XAGUSDT', 'tf' => '15m']];
        $result  = [];

        foreach ($symbols as $item) {
            $sym    = $item['symbol'];
            $tf     = $item['tf'];
            $klines = $this->marketData->getKlines($sym, $tf, 150);
            $price  = $this->marketData->getPrice($sym);

            if (empty($klines)) {
                $result[$sym] = ['error' => 'no data'];
                continue;
            }

            $channel  = $this->priceAction->detectUnpredictableChannel($klines, lookback: 100);
            $atr      = $this->priceAction->calculateATR($klines, 14);
            $h4Klines = $this->marketData->getKlines($sym, '4h', 100);
            $htfBias  = !empty($h4Klines) ? $this->priceAction->getHTFBias($h4Klines) : null;

            $result[$sym] = [
                'bars'        => count($klines),
                'price'       => $price,
                'atr'         => round($atr, 2),
                'htf_bias'    => $htfBias,
                'is_channel'  => $channel['is_channel'],
                'type'        => $channel['type']        ?? 'none',
                'direction'   => $channel['direction']   ?? null,
                'upper'       => $channel['upper']       ?? null,
                'lower'       => $channel['lower']       ?? null,
                'compression' => $channel['compression'] ?? 0,
                'lh_count'    => $channel['lh_count']    ?? 0,
                'hl_count'    => $channel['hl_count']    ?? 0,
                'll_count'    => $channel['ll_count']    ?? 0,
                'hh_count'    => $channel['hh_count']    ?? 0,
                'would_fire'  => $channel['is_channel'],
            ];
        }

        return response()->json([
            'scanned_at' => now('Asia/Ho_Chi_Minh')->format('Y-m-d H:i:s T'),
            'pairs'      => $result,
        ]);
    }

    // ──────────────────────────────────────────────────────────────
    // POST /api/mt5/bulk-klines  — import lịch sử cho backtest
    // ──────────────────────────────────────────────────────────────

    /**
     * Nhận và tích lũy lịch sử klines từ FelixBulkExporter.mq5.
     * Gọi nhiều lần (chunked) → merge + dedup theo timestamp → lưu 7 ngày.
     * Backtest đọc qua key: mt5_bulk_{SYMBOL}_{TF}
     */
    public function receiveBulkKlines(Request $request): JsonResponse
    {
        if (!$this->verifySecret($request)) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        $symbol    = $request->input('symbol', '');
        $timeframe = $request->input('timeframe', '');
        $newBatch  = $request->input('klines', []);

        if (!$symbol || !$timeframe || empty($newBatch)) {
            return response()->json(['error' => 'missing symbol/timeframe/klines'], 422);
        }

        $sym = $this->marketData->normalizeSymbol($symbol);
        $tf  = $this->marketData->normalizeTimeframe($timeframe);
        $key = "mt5_bulk_{$sym}_{$tf}";

        // Merge với batch cũ → dedup theo timestamp → sort tăng dần
        $existing = \Cache::get($key, []);
        $byTs     = [];
        foreach (array_merge($existing, $newBatch) as $bar) {
            $byTs[(int)$bar[0]] = $bar;
        }
        ksort($byTs);
        $merged = array_values($byTs);

        \Cache::put($key, $merged, now()->addDays(7));

        return response()->json([
            'ok'        => true,
            'symbol'    => $sym,
            'tf'        => $tf,
            'total_bars'=> count($merged),
            'new_bars'  => count($newBatch),
        ]);
    }

    // ──────────────────────────────────────────────────────────────
    // GET /api/mt5/bulk-export  — download bulk klines về local để backtest
    // ──────────────────────────────────────────────────────────────

    public function bulkExport(Request $request): JsonResponse
    {
        if (!$this->verifySecret($request)) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        $sym  = strtoupper($request->query('symbol', 'XAUUSDT'));
        $tf   = strtolower($request->query('tf', '15m'));
        $key  = "mt5_bulk_{$sym}_{$tf}";
        $data = \Cache::get($key, []);

        return response()->json([
            'symbol' => $sym,
            'tf'     => $tf,
            'bars'   => count($data),
            'klines' => $data,
        ]);
    }

    // ──────────────────────────────────────────────────────────────
    // GET /api/mt5/ping-telegram  — kiểm tra Telegram có hoạt động không
    // ──────────────────────────────────────────────────────────────

    public function pingTelegram(): JsonResponse
    {
        $ok = $this->telegram->isConfigured();
        if ($ok) {
            $this->telegram->sendRaw(
                "🔔 <b>Felix ping</b> — " . now('Asia/Ho_Chi_Minh')->format('H:i:s T') . "\nTelegram hoạt động bình thường."
            );
        }
        return response()->json(['telegram_configured' => $ok]);
    }

    // ──────────────────────────────────────────────────────────────
    // PRIVATE
    // ──────────────────────────────────────────────────────────────

    /** Parse compact klines: "o,h,l,c~o,h,l,c~..." + startTs → [[ts_ms,o,h,l,c,0], ...] */
    private function parseCompactKlines(string $k, int $startTs = 0): array
    {
        if (empty($k)) return [];
        $klines = [];
        foreach (explode('-', $k) as $i => $row) {
            $f = explode(',', $row);
            if (count($f) === 4) {
                $ts = $startTs > 0 ? ($startTs + $i * 900) * 1000 : 0;
                $klines[] = [$ts, (float)$f[0], (float)$f[1], (float)$f[2], (float)$f[3], 0.0];
            }
        }
        return $klines;
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
