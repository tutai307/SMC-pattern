# Backtest — Kiểm tra chiến lược trên dữ liệu lịch sử

Chạy backtest cho SMC hoặc Elliott Wave trên historical klines từ Binance.

## Usage

```
/backtest <symbol> <timeframe> <method> [days]
```

Ví dụ: `/backtest BTCUSDT 15m smc 30`, `/backtest ETHUSDT 1h elliott 60`

## What to Build

Tạo artisan command `php artisan backtest {symbol} {timeframe} {method} {--days=30}`:

### File: `app/Console/Commands/BacktestCommand.php`

```php
// Logic flow:
// 1. Fetch historical klines từ Binance (dùng startTime pagination)
//    - Binance limit 1500 candles per request
//    - Paginate để lấy {days} ngày dữ liệu
// 2. Slice data thành rolling windows (200 candles min)
// 3. Với mỗi window, chạy PriceActionService::analyze()
// 4. Nếu có signal → simulate entry
// 5. Check subsequent candles: TP hit? SL hit? (max 20 candles look-ahead)
// 6. Record result: WIN/LOSS/TIMEOUT
// 7. Output thống kê
```

### Pagination Pattern cho Historical Data

```php
// BinanceService cần method mới:
public function getHistoricalKlines(string $symbol, string $interval, int $days): array
{
    $startTime = now()->subDays($days)->timestamp * 1000;
    $endTime = now()->timestamp * 1000;
    $allKlines = [];
    
    while ($startTime < $endTime) {
        $batch = $this->getKlines($symbol, $interval, 1500, $startTime);
        if (empty($batch)) break;
        $allKlines = array_merge($allKlines, $batch);
        $startTime = end($batch)[0] + 1; // next candle open_time
    }
    return $allKlines;
}
```

### Output Format

```
=== BACKTEST RESULTS ===
Symbol: BTCUSDT | Timeframe: 15m | Method: SMC | Period: 30 days
Candles analyzed: 2880
Signals generated: 47
  - LONG: 28 | SHORT: 19
Completed: 43 (4 timeout/open)
  - WIN: 29 (67.4%)
  - LOSS: 14 (32.6%)
Average R:R: 2.3:1
Expected Value per trade: +0.71R
Best day: Tuesday (75% WR)
Worst day: Friday (45% WR)
Best time: 02:00-06:00 UTC (73% WR)
```

## Implementation Steps

1. Tạo `app/Console/Commands/BacktestCommand.php`
2. Thêm `getHistoricalKlines()` vào `BinanceService`
3. Register command trong `app/Console/Kernel.php` (hoặc `routes/console.php`)
4. Chạy: `php artisan backtest BTCUSDT 15m smc --days=30`

## Important Notes

- **Không dùng AI scoring** trong backtest (tốn tiền, slow)
- Binance free API có rate limit — add 200ms delay giữa các pagination requests
- HTF klines cần fetch riêng (giống production code)
- Signal overlap: nếu đang có open position, skip signal mới cho cùng symbol
- Slippage: cộng thêm 0.05% vào entry price (thực tế có spread)
