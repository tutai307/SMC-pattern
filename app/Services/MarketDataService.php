<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * v4 — Local market data store.
 * MT5 EA (Exness) push klines + tick price lên đây qua API.
 * Scanner đọc từ cache thay vì gọi Binance.
 */
class MarketDataService
{
    // Cache TTL — EA phải push trước khi expired
    private const KLINES_TTL_MIN = 120;  // 2 giờ — buffer nếu EA skip 1-2 bar
    private const PRICE_TTL_SEC  = 30;   // 30 giây

    // ──────────────────────────────────────────────────────────────
    // READ — dùng bởi ScanSignalsCommand
    // ──────────────────────────────────────────────────────────────

    /**
     * Lấy klines từ cache (do EA push lên).
     * Format giống Binance: [[timestamp_ms, open, high, low, close, volume], ...]
     *
     * @return array  Rỗng nếu EA chưa push hoặc expired.
     */
    public function getKlines(string $symbol, string $timeframe, int $limit = 200): array
    {
        $key    = $this->klinesKey($symbol, $timeframe);
        $stored = Cache::get($key, []);
        if (empty($stored)) return [];
        return array_slice($stored, -$limit);
    }

    /**
     * Lấy giá bid/ask hiện tại từ cache.
     * @return float|null  null nếu chưa có data.
     */
    public function getPrice(string $symbol): ?float
    {
        $val = Cache::get($this->priceKey($symbol));
        return $val !== null ? (float) $val : null;
    }

    /**
     * Kiểm tra EA đã push data gần đây chưa.
     */
    public function hasData(string $symbol, string $timeframe): bool
    {
        return Cache::has($this->klinesKey($symbol, $timeframe))
            && Cache::has($this->priceKey($symbol));
    }

    /**
     * Lấy metadata lần push gần nhất để hiển thị trên dashboard.
     */
    public function getLastPushInfo(string $symbol, string $timeframe): ?array
    {
        return Cache::get($this->metaKey($symbol, $timeframe));
    }

    // ──────────────────────────────────────────────────────────────
    // WRITE — gọi bởi MT5DataController khi EA push
    // ──────────────────────────────────────────────────────────────

    /**
     * Lưu toàn bộ klines vào cache.
     * EA gọi endpoint này mỗi khi M15 bar đóng.
     *
     * @param array $klines  [[timestamp_ms, open, high, low, close, volume], ...]
     */
    public function storeKlines(string $symbol, string $timeframe, array $klines): void
    {
        if (empty($klines)) return;

        // Normalize symbol: XAUUSD → XAUUSDT (để hệ thống không cần đổi tên ở nhiều chỗ)
        $sym = $this->normalizeSymbol($symbol);

        Cache::put(
            $this->klinesKey($sym, $timeframe),
            $klines,
            now()->addMinutes(self::KLINES_TTL_MIN)
        );

        Cache::put($this->metaKey($sym, $timeframe), [
            'pushed_at'   => now('Asia/Ho_Chi_Minh')->format('Y-m-d H:i:s T'),
            'count'       => count($klines),
            'last_close'  => (float) ($klines[count($klines) - 1][4] ?? 0),
            'last_ts_ms'  => (int)   ($klines[count($klines) - 1][0] ?? 0),
        ], now()->addMinutes(self::KLINES_TTL_MIN));
    }

    /**
     * Lưu giá bid hiện tại.
     * EA gọi endpoint này mỗi 10 giây (tick-based hoặc timer).
     */
    public function storePrice(string $symbol, float $bid): void
    {
        $sym = $this->normalizeSymbol($symbol);
        Cache::put($this->priceKey($sym), $bid, self::PRICE_TTL_SEC);
    }

    // ──────────────────────────────────────────────────────────────
    // HELPERS
    // ──────────────────────────────────────────────────────────────

    /**
     * XAUUSD → XAUUSDT, XAGUSD → XAGUSDT
     * (giữ nhất quán với SCAN_SYMBOLS env)
     */
    public function normalizeSymbol(string $symbol): string
    {
        $s = strtoupper(trim($symbol));
        // XAUUSDm, XAUUSD, XAUUSDT → XAUUSDT
        if (str_starts_with($s, 'XAUUSD')) return 'XAUUSDT';
        // XAGUSDm, XAGUSD, XAGUSDT → XAGUSDT
        if (str_starts_with($s, 'XAGUSD')) return 'XAGUSDT';
        return $s;
    }

    /**
     * Chuẩn hóa timeframe: MT5 format → Binance format
     *   M1→1m, M5→5m, M15→15m, M30→30m
     *   H1→1h, H4→4h, D1→1d, W1→1w
     */
    public function normalizeTimeframe(string $tf): string
    {
        $upper = strtoupper(trim($tf));
        return match ($upper) {
            'M1'    => '1m',  'M5'   => '5m',  'M15'  => '15m', 'M30'  => '30m',
            'H1'    => '1h',  'H4'   => '4h',  'H12'  => '12h',
            'D1'    => '1d',  'W1'   => '1w',
            default => strtolower($upper), // "15m" đã đúng format → lowercase
        };
    }

    private function klinesKey(string $symbol, string $timeframe): string
    {
        return 'mt5_klines_' . strtoupper($symbol) . '_' . $this->normalizeTimeframe($timeframe);
    }

    private function priceKey(string $symbol): string
    {
        return 'mt5_price_' . strtoupper($symbol);
    }

    private function metaKey(string $symbol, string $timeframe): string
    {
        return 'mt5_meta_' . strtoupper($symbol) . '_' . $this->normalizeTimeframe($timeframe);
    }
}
