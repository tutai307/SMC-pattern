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

        return response()->json([
            'status'     => 'ok',
            'server_time'=> now('Asia/Ho_Chi_Minh')->format('Y-m-d H:i:s T'),
            'pairs'      => $result,
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

            $channel = $this->priceAction->detectUnpredictableChannel($klines, lookback: 100);
            $atr     = $this->priceAction->calculateATR($klines, 14);

            $ai = ['score' => 'n/a', 'direction' => 'n/a', 'analysis' => ''];
            if ($channel['is_channel']) {
                $ai = $this->priceAction->scoreWithAI($sym, '15m', $channel, $atr, (float)$price, $klines);
            }

            $result[$sym] = [
                'bars'         => count($klines),
                'price'        => $price,
                'atr'          => round($atr, 2),
                'is_channel'   => $channel['is_channel'],
                'type'         => $channel['type']        ?? 'none',
                'direction'    => $channel['direction']   ?? null,
                'upper'        => $channel['upper']       ?? null,
                'lower'        => $channel['lower']       ?? null,
                'compression'  => $channel['compression'] ?? 0,
                'lh_count'     => $channel['lh_count']    ?? 0,
                'hl_count'     => $channel['hl_count']    ?? 0,
                'll_count'     => $channel['ll_count']    ?? 0,
                'hh_count'     => $channel['hh_count']    ?? 0,
                'ai_score'     => $ai['score']               ?? 'n/a',
                'ai_direction' => $ai['breakout_direction']  ?? 'n/a',
                'ai_analysis'  => $ai['analysis']            ?? '',
                'ai_cached'    => $ai['cached']              ?? false,
                'would_fire'   => ($channel['is_channel'] && is_int($ai['score'] ?? null) && ($ai['score'] ?? 0) >= 70),
            ];
        }

        return response()->json([
            'scanned_at' => now('Asia/Ho_Chi_Minh')->format('Y-m-d H:i:s T'),
            'pairs'      => $result,
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
