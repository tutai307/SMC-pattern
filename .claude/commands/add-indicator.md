# Add Indicator — Thêm chỉ báo kỹ thuật mới

Thêm indicator mới vào hệ thống phân tích.

## Usage

```
/add-indicator <indicator-name> [params]
```

Ví dụ: `/add-indicator RSI 14`, `/add-indicator VWAP`, `/add-indicator BB 20,2`

## Supported Indicators

| Indicator | Params | Use Case |
|---|---|---|
| `RSI` | period (default 14) | Overbought/oversold filter |
| `VWAP` | — | Intraday institutional level |
| `BB` | period,stdev (default 20,2) | Volatility squeeze detection |
| `STOCH` | k,d,smooth (default 14,3,3) | Momentum confirmation |
| `OI` | — | Open Interest từ Binance (requires new endpoint) |
| `CVD` | — | Cumulative Volume Delta (buy vs sell volume) |

## Implementation Steps

1. **Đọc** `app/Services/PriceActionService.php` — tìm các `calculate*()` methods hiện tại làm template
2. **Thêm method** `calculateINDICATOR()` theo pattern:
   ```php
   private function calculateRSI(array $klines, int $period = 14): array
   {
       // return array indexed same as $klines
   }
   ```
3. **Tích hợp** vào `analyze()` method — thêm vào `$indicators` array
4. **Dùng** trong signal generation — thêm filter condition vào `generateSMCSignal()` hoặc `generateElliotSignal()`
5. **Pass to view** nếu cần hiển thị — update `DashboardController::index()` data array
6. **Render** trên chart nếu là line/band indicator — update `welcome.blade.php` Lightweight Charts section

## Patterns

```php
// Klines format: [open_time, open, high, low, close, volume, ...]
// Index 4 = close, 2 = high, 3 = low, 5 = volume

// Existing patterns to follow:
// calculateEMA() — exponential moving average
// calculateATR() — average true range  
// calculateADX() — directional index
```

## Rules

- Không break existing `analyze()` return format
- New indicator = optional filter, không replace existing logic
- Nếu indicator cần data Binance mới → thêm method vào `BinanceService` với cache
