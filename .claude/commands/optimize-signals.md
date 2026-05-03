# Optimize Signals — Cải thiện chất lượng tín hiệu

Tăng độ chính xác và winrate thực tế của tín hiệu SMC/Elliott Wave.

## Usage

```
/optimize-signals [smc|elliott|ob|fvg|all]
```

## Focus Areas

### `smc` — Cải thiện SMC signal quality
File: `app/Services/PriceActionService.php`

Targets:
1. `generateSMCSignal()`: Nâng ADX filter 15→22, thêm EMA alignment check (price phải bên đúng phía EMA200)
2. `findHighQualityOB()`: Thêm volume spike check (OB candle volume > 1.5x avg), thêm rejection wick ratio
3. `detectSMCStructure()`: Cải thiện CHoCH detection (require 2 consecutive closes qua level)

### `elliott` — Cải thiện Elliott Wave detection
File: `app/Services/PriceActionService.php`

Targets:
1. `detectElliotWaves()`: Adaptive pivot window dựa ATR thay fixed 10-candle
2. Thêm Fibonacci retracement validation: Wave 2 phải retrace 38.2-78.6% of Wave 1
3. Wave 3 phải là longest impulse wave (không được ngắn hơn Wave 1 hoặc Wave 5)
4. `generateElliotSignal()`: Chỉ trade Wave 3 khi volume confirmation exists

### `ob` — Order Block quality filter
Thêm vào `findHighQualityOB()`:
- Require volume > 1.5x 20-period average tại OB candle
- Check upper/lower wick ratio (rejection body ≥ 60% của total range)
- OB không hợp lệ nếu price đã test zone nhiều hơn 3 lần

### `fvg` — Fair Value Gap refinement
Thêm vào `detectFVG()`:
- Chỉ dùng FVG mới (≤ 5 candles cũ)
- FVG size tối thiểu = 0.3x ATR
- Bỏ FVG đã được fill hơn 50%

## Steps

1. Đọc `PriceActionService.php` đầy đủ trước
2. Implement từng improvement theo thứ tự priority
3. Không thay đổi method signatures (controller phụ thuộc vào)
4. Sau mỗi thay đổi, kiểm tra logic flow của toàn bộ `analyze()` method
5. Chạy `php artisan test`

## Success Metrics

- Tín hiệu chỉ xuất hiện khi có multiple confirmations
- ADX + EMA + OB/FVG alignment đồng thời
- AI score trên tín hiệu mới phải ≥ 70 để propose
