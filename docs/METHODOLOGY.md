# Felix v5 — Phương pháp phân tích & Luồng dữ liệu

## 1. Kiến trúc tổng quan

```
MT5 EA (Exness)
  │ POST /api/mt5/klines  (mỗi 100s + mỗi bar mới)
  │ POST /api/mt5/tick    (mỗi 10s)
  ▼
Laravel Railway (MarketDataService → MySQL Cache)
  ▼
ScanSignalsCommand (chạy mỗi 5 phút)
  │ detectUnpredictableChannel()
  │ calculateATR()
  │ scoreWithAI() → OpenRouter GPT-4o
  ▼
Telegram Alert
```

---

## 2. Lấy dữ liệu thực (Real-time Data)

### 2.1 MT5 EA — FelixDataPusher.mq5

Đính kèm vào bất kỳ chart nào trên Exness (thường là M15 XAUUSD).

**Push Klines** (mỗi ~100 giây + mỗi khi bar M15 mới mở):
- `CopyRates(_Symbol, PERIOD_M15, 0, 150, rates)` — lấy 150 nến M15
- Timestamp được convert từ broker time (UTC+3) sang UTC: `ts_utc = rates[i].time + (TimeGMT() - TimeCurrent())`
- Gửi qua `POST /api/mt5/klines` với JSON body + `Content-Length` header (bắt buộc để server đọc được body)

**Push Tick** (mỗi 10 giây):
- `SymbolInfoDouble(_Symbol, SYMBOL_BID)` — giá bid hiện tại
- Gửi qua `POST /api/mt5/tick` dưới dạng query params

**Lưu ý quan trọng:**
- `StringToCharArray(body, arr)` — dùng `count = -1` (default) để tránh mất ký tự cuối JSON
- Secret được gửi trong URL query `?secret=...` (không qua JSON body để tránh parsing race condition)

### 2.2 Server — MarketDataService

- Klines lưu vào MySQL cache với key `mt5_klines_XAUUSDT_15m`, TTL **120 phút**
- Giá bid lưu với key `mt5_price_XAUUSDT`, TTL **30 giây**
- Symbol được chuẩn hóa: `XAUUSDm / XAUUSD → XAUUSDT`
- Timeframe: `M15 → 15m`, `H1 → 1h`, v.v.

### 2.3 Debug Endpoints

| Endpoint | Mô tả |
|---|---|
| `GET /api/mt5/status` | Kiểm tra data freshness (pushed_at, bar_count, bid) |
| `GET /api/mt5/scan` | Chạy channel detection + AI scoring trên data hiện tại |
| `GET /api/mt5/ping-telegram` | Test gửi tin nhắn Telegram |

---

## 3. Phương pháp phát hiện kênh (Channel Detection)

File: `app/Services/PriceActionService::detectUnpredictableChannel()`  
Lookback: **100 nến M15** (~25 giờ)

### 3.1 Swing Points

Dùng thuật toán **wing = 2**: một điểm là Swing High nếu nó cao hơn 2 nến liền kề mỗi bên.

```
Swing High tại bar[i] nếu: high[i] > max(high[i-2..i+2]) (bỏ qua bar[i])
Swing Low  tại bar[i] nếu: low[i]  < min(low[i-2..i+2])
```

Lấy tối đa **8 swing points** cuối cùng cho phân tích.

### 3.2 Ba loại kênh được detect

#### A. Tam giác nén (Triangle) — Breakout 2 chiều
- **Điều kiện:** LH ≥ 1 VÀ HL ≥ 1 (Lower Highs + Higher Lows đồng thời)
- **Ý nghĩa:** Giá đang bị nén vào trong một tam giác, breakout bất kỳ chiều nào
- **Tín hiệu:** BUY STOP + SELL STOP (AI quyết định chiều ưu tiên)
- **Filter thêm:** `compression ≥ 20%` (kênh đầu chuỗi so với kênh cuối chuỗi phải nén ≥ 20%)

#### B. Kênh giảm (Descending) — Chỉ SELL LIMIT
- **Điều kiện:** LH ≥ 1 VÀ LL ≥ 1 VÀ HL = 0 (Lower Highs + Lower Lows, không có Higher Low)
- **Ý nghĩa:** Xu hướng giảm có kênh song song, bán khi giá chạm đường trên
- **Trendline:** Slope từ điểm **ĐẦU → CUỐI** chuỗi LH/LL (không chỉ 2 điểm cuối)
  ```
  firstLH = highs[n - 1 - lhCount]  // điểm LH cũ nhất trong chuỗi
  lastLH  = highs[n - 1]             // điểm LH mới nhất
  slope   = (lastLH.price - firstLH.price) / (lastLH.idx - firstLH.idx)
  upper   = lastLH.price + slope × (currentIdx - lastLH.idx)
  ```
- **Tín hiệu:** Chỉ SELL LIMIT (force direction = SHORT, bỏ qua AI direction)

#### C. Kênh tăng (Ascending) — Chỉ BUY LIMIT
- **Điều kiện:** HH ≥ 1 VÀ HL ≥ 1 VÀ LH = 0 (Higher Highs + Higher Lows)
- **Ý nghĩa:** Xu hướng tăng có kênh song song, mua khi giá chạm đường dưới
- **Trendline:** Slope từ điểm ĐẦU → CUỐI chuỗi HH/HL (cùng công thức như descending)
- **Tín hiệu:** Chỉ BUY LIMIT (force direction = LONG)

#### Filter chung cho cả 3 loại
- Chiều rộng kênh ≥ **0.15%** so với giá mid (≈ $6.75 với XAUUSD $4500)
- Nếu kênh quá hẹp → bỏ qua (không đủ room cho TP/SL)

---

## 4. Tính Entry / TP / SL

File: `app/Services/SignalFormatterService::buildSignals()`

**Đơn vị:** 1 pip = **$1.00** (XAUUSD, giá tính theo USD/oz)

### 4.1 Tam giác nén — Breakout STOP

```
BUY STOP:
  Entry = upper + 3 pips
  TP    = Entry + channel_width × 0.8
  SL    = Entry - 1.5 × ATR(14)

SELL STOP:
  Entry = lower - 3 pips
  TP    = Entry - channel_width × 0.8
  SL    = Entry + 1.5 × ATR(14)
```

**Logic:** Chờ giá phá kênh (breakout), vào ngay sau buffer $3 để tránh fakeout

### 4.2 Kênh giảm — Bounce SELL LIMIT

```
SELL LIMIT:
  Entry = upper_trendline - 3 pips  (đặt sẵn chờ giá lên chạm)
  TP    = Entry - channel_width × 0.8
  SL    = Entry + 1.5 × ATR(14)
```

**Logic:** Giá sẽ quay đầu tại đường resistance → đặt LIMIT trước, không chờ confirm

### 4.3 Kênh tăng — Bounce BUY LIMIT

```
BUY LIMIT:
  Entry = lower_trendline + 3 pips  (đặt sẵn chờ giá xuống chạm)
  TP    = Entry + channel_width × 0.8
  SL    = Entry - 1.5 × ATR(14)
```

**Logic:** Giá sẽ bounce tại đường support → đặt LIMIT trước

### 4.4 Thông số động

| Tham số | Công thức | Lý do |
|---|---|---|
| Buffer | 3 pips cố định | Tránh fakeout / slippage |
| SL | 1.5 × ATR(14) | Bám theo noise thực tế của thị trường |
| TP | channel_width × 0.8 | Nhắm 80% biên độ kênh — realistic |
| R:R tối thiểu | 1:1 (TP ≥ SL) | Setup dưới mức này → skip |

> Setup bị loại nếu `channel_width × 0.8 < 1.5 × ATR` (kênh quá hẹp so với volatility)

---

## 5. AI Scoring

File: `app/Services/PriceActionService::scoreWithAI()`

- Model: **GPT-4o** qua OpenRouter
- Cache: **30 phút** per channel fingerprint (hash của upper + lower + compression)
- Input gửi AI: symbol, timeframe, upper/lower trendline, compression, ATR(14), 5 nến gần nhất (bull/bear count), body strength
- Output: `score` (0-100), `breakout_direction` (LONG/SHORT/BOTH/WAIT), `analysis`, `confidence`, `risk_note`

**Ngưỡng kích hoạt:**
- Tam giác: AI score ≥ **70**
- Kênh có hướng (ascending/descending): AI score ≥ **55** (đã có xác nhận cấu trúc)
- Nếu AI trả về `WAIT` → bỏ qua dù score đủ

**Với kênh có hướng:** AI direction bị override bởi channel direction (ascending → LONG, descending → SHORT)

---

## 6. Dedup & Spam Protection

- Mỗi kênh chỉ gửi alert **1 lần trong 2 giờ**
- Cache key: `v4_scan_{symbol}_{tf}_{round(upper)}_{round(lower)}`
- Khi kênh thay đổi (trendline move) → key mới → alert mới

---

## 7. Lot Sizing — Fixed Fractional 2%

```
Lot = (Vốn × 2%) / (SL_pips_động × $100)
    = (Vốn × 0.02) / (1.5 × ATR × 100)
```

Clamp tối thiểu 0.01 lot. Mỗi lệnh rủi ro đúng 2% vốn — không cố định lot.

| Vốn | ATR(14) = 8 pip | ATR(14) = 15 pip | ATR(14) = 25 pip |
|---|---|---|---|
| $500 | 0.01 | 0.01 | 0.01 |
| $2,000 | 0.02 | 0.01 | 0.01 |
| $10,000 | 0.08 | 0.04 | 0.03 |
| $50,000 | 0.42 | 0.22 | 0.13 |

> SL_pips = 1.5 × ATR nên lot tự điều chỉnh theo volatility thực tế.

---

## 8. Hạn chế hiện tại & Cần cải thiện

| Vấn đề | Mức độ | Ghi chú |
|---|---|---|
| TP cố định 15 pips chưa bám theo chiều rộng kênh | MEDIUM | Kênh rộng 70 pip mà TP chỉ 15 pip = bỏ phần lớn lợi nhuận |
| Ascending/Descending chỉ cần HH:1+HL:1 — quá ít xác nhận | MEDIUM | Dễ false positive, cần ≥ 2 |
| Không check giá có gần entry point chưa | MEDIUM | BUY entry 4544 khi giá đang ở 4550 = BUY LIMIT, không phải STOP |
| Lookback 100 nến (~25h) chưa bắt được kênh dài 2-3 ngày | LOW | Cần 200+ nến, EA hiện push 150 |
| AI cache 30 phút — cùng kênh nhưng giá đổi không re-score | LOW | Tăng cache granularity |
