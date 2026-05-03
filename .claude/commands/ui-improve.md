# UI Improve — Cải thiện giao diện terminal

Cải thiện UX của trading terminal để ra quyết định nhanh hơn và chính xác hơn.

## Usage

```
/ui-improve [feature]
```

Features: `signal-card`, `risk-calculator`, `alerts`, `multi-chart`, `journal`, `all`

## Feature Specs

### `signal-card` — Cải thiện signal display
File: `resources/views/welcome.blade.php`

Thêm vào signal proposal card:
- **Risk badge** màu: GREEN (R:R > 2.5), YELLOW (1.5-2.5), RED (< 1.5)
- **Confidence meter** visual (thanh ngang 0-100 với màu gradient)
- **Market session indicator**: Asia/London/NY session (dựa vào UTC time)
- **Quick copy button** cho entry/TP/SL prices
- Countdown timer: "Signal valid for ~X candles" (dựa timeframe)

### `risk-calculator` — Calculator nâng cao
Hiện tại chỉ có margin calculator đơn giản. Thêm:
- Kelly Criterion: `f* = (bp - q) / b` → suggest position size
- Max drawdown protection: Nếu thua liên tiếp 3 lần → suggest giảm size 50%
- Running P&L tracker từ `trading_signals` history
- Equity curve mini chart (dùng Lightweight Charts)

### `alerts` — Price alerts
- User set alert tại entry zone
- Browser notification khi giá đến vùng (dùng Notification API)
- Không cần backend — WebSocket giá hiện tại so với user-set levels
- UI: Input field "Alert me when price reaches ___"

### `multi-chart` — Xem nhiều chart cùng lúc
- Split view: LTF (15m) + HTF (1h/4h) side by side
- Sync crosshair giữa 2 charts
- Toggle layout: stack / side-by-side

### `journal` — Trade journal
- Sau khi signal WIN/LOSS, hiện modal "Post-trade notes"
- Save notes vào `trading_signals.reason` column (append)
- Hoặc thêm `notes` text column mới vào migration
- Export to CSV: `GET /signals/export?format=csv`

## Implementation Rules

- Tailwind classes only (không inline style mới)
- JavaScript vanilla (không add new libraries)
- Lightweight Charts đã available cho charting needs
- Mobile responsive: mọi feature phải dùng được trên tablet
- Test trong browser sau khi implement: `npm run dev` + `php artisan serve`

## Priority

1. `signal-card` (ảnh hưởng trực tiếp đến ra quyết định)
2. `alerts` (không miss entry)
3. `risk-calculator` nâng cao
4. `journal`
5. `multi-chart` (tốn công nhất)
