# Add Pair — Thêm trading pair mới

Thêm và test một cặp tiền mới vào terminal.

## Usage

```
/add-pair <SYMBOL>
```

Ví dụ: `/add-pair SOLUSDT`, `/add-pair ETHUSDT`, `/add-pair XAUUSDT`

## Steps

1. **Verify pair tồn tại trên Binance Futures:**
   ```bash
   # Test bằng curl hoặc check trong BinanceService
   # Pair phải available ở https://fapi.binance.com/fapi/v1/exchangeInfo
   ```

2. **Đọc** `resources/views/welcome.blade.php` — tìm symbol selector/dropdown

3. **Thêm vào UI** symbol list (nếu có hardcoded list)

4. **Test analysis** bằng cách load `/?symbol=NEWPAIR&timeframe=15m&method=smc`

5. **Verify** không có error trong:
   - `BinanceService::getKlines()` — response format đúng
   - `PriceActionService::analyze()` — đủ data points (≥200 candles cho EMA200)
   - Signal generation — không throw exception

6. **Report** kết quả phân tích đầu tiên cho pair mới

## Common Issues

- Pair tên khác trên Binance Futures vs Spot (dùng `XAGUSDTM` thay `XAGUSDT`)
- Một số pairs có volume thấp → ADX filter sẽ reject tất cả tín hiệu → confirm với user
- Pairs mới listing có thể thiếu historical data cho EMA200 calculation

## Validation

Sau khi thêm, kiểm tra:
- [ ] Page load không error
- [ ] Chart hiển thị đúng candlesticks
- [ ] Analysis trả về trend + structure
- [ ] AI scoring hoạt động
- [ ] Signal proposal có thể save vào DB
