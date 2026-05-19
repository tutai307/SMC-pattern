# Felix v5.3 — Phương pháp phân tích & Luồng dữ liệu

> **Phiên bản hiện tại: v5.3** — Pure Math, No AI. Cập nhật lần cuối: 2026-05-19

---

## 1. Kiến trúc tổng quan

```
MT5 EA (Exness)
  │ POST /api/mt5/klines      (mỗi 100s + mỗi bar M15 mới)
  │ POST /api/mt5/bulk-klines (one-shot export lịch sử — dùng cho backtest)
  │ POST /api/mt5/tick        (mỗi 10s)
  ▼
Laravel Railway (MarketDataService → MySQL Cache)
  ▼
ScanSignalsCommand (chạy mỗi 5 phút)
  │ H4 getHTFBias()           ← Top-Down macro filter
  │ detectUnpredictableChannel() — 4 mô hình hình học
  │ calculateATR()
  │ proximity check + dedup
  ▼
Telegram Alert (không có AI)
```

**Thay đổi lớn so với v4/v5.0:**
- ❌ Đã xóa hoàn toàn `scoreWithAI()` / OpenRouter / GPT-4o
- ✅ Thêm Top-Down H4 macro bias filter
- ✅ 4-channel classification với thứ tự ưu tiên chặt
- ✅ TP/SL cố định scalp ngắn (2/3 giá)
- ✅ OLS linear regression thay vì 2-point slope

---

## 2. Lấy dữ liệu thực (Real-time Data)

### 2.1 MT5 EA — FelixDataPusher.mq5

Đính kèm vào chart XAUUSD M15 trên Exness.

**Push Klines** (mỗi ~100 giây + mỗi khi bar M15 mới mở):
- `CopyRates(_Symbol, PERIOD_M15, 0, 150, rates)` — lấy 150 nến M15
- Timestamp convert từ broker time (UTC+3) sang UTC: `brokerOffsetSec = TimeGMT() - TimeCurrent()`
- Gửi qua `POST /api/mt5/klines` với JSON body + `Content-Length` header

**Push Tick** (mỗi 10 giây):
- `SymbolInfoDouble(_Symbol, SYMBOL_BID)` — giá bid hiện tại
- Gửi qua `POST /api/mt5/tick` dưới dạng query params

**Lưu ý quan trọng:**
- `StringToCharArray(body, arr)` dùng count mặc định (không đặt explicit) để tránh mất ký tự cuối JSON
- Secret gửi trong URL query `?secret=...` (không qua JSON body)

### 2.2 MT5 Script — FelixBulkExporter.mq5 (Backtest)

Script one-shot để export lịch sử M15 + H4 lên server. Drag vào chart, điền `StartDate`/`EndDate`, chạy 1 lần.

- Gửi theo chunk 500 bars/request → tích lũy trên server (merge + dedup + sort)
- Key lưu: `mt5_bulk_{SYMBOL}_{TF}`, TTL 7 ngày
- Dùng bởi `backtest:v53` command

### 2.3 Server — MarketDataService

- Klines real-time lưu với key `mt5_klines_XAUUSDT_15m`, TTL **120 phút**
- Giá bid lưu với key `mt5_price_XAUUSDT`, TTL **30 giây**
- Symbol chuẩn hóa: `XAUUSDm / XAUUSD → XAUUSDT`

### 2.4 API Endpoints

| Endpoint | Mô tả |
|---|---|
| `GET /api/mt5/status` | Data freshness (pushed_at, bar_count, bid) |
| `GET /api/mt5/scan` | Channel detection trên data hiện tại (không AI) |
| `POST /api/mt5/bulk-klines` | Nhận klines lịch sử từ FelixBulkExporter |
| `GET /api/mt5/ping-telegram` | Test Telegram |

---

## 3. Top-Down Analysis — H4 Macro Bias

File: `app/Services/PriceActionService::getHTFBias()`

Trước khi phân tích M15, bot xác định xu hướng vĩ mô từ H4:

```
H4 klines (50 nến cuối)
  → detectUnpredictableChannel(lookback=50)
  → direction: 'LONG' | 'SHORT' | null
```

**Filter logic trong ScanSignalsCommand:**
- Nếu H4 bias = `LONG` nhưng M15 channel = `SHORT` → **skip** (counter-trend)
- Nếu H4 bias = null (không rõ / không có data) → **cho qua** (graceful fallback)
- Triangle (direction=null) → **luôn cho qua** bất kể H4 (đánh cả 2 chiều)

---

## 4. Phân loại 4 Kênh — Thứ tự ưu tiên chặt

File: `app/Services/PriceActionService::detectUnpredictableChannel()`
Lookback: **100 nến M15** (~25 giờ)

### 4.1 Swing Points

**Wing = 2**: một điểm là Swing High nếu cao hơn tất cả 2 nến ở mỗi bên (4 nến so sánh).

```
Swing High tại bar[i]: high[i] > high[j] với mọi j ∈ [i-2, i+2], j≠i
Swing Low  tại bar[i]: low[i]  < low[j]  với mọi j ∈ [i-2, i+2], j≠i
```

Lấy tối đa **8 swing points** cuối cùng. Đếm chuỗi liên tiếp từ cuối:
- **LH** (Lower High): đỉnh thấp dần — áp lực bán
- **HL** (Higher Low): đáy cao dần — áp lực mua
- **HH** (Higher High): đỉnh cao dần — momentum tăng
- **LL** (Lower Low): đáy thấp dần — momentum giảm

### 4.2 Bốn mô hình (ưu tiên 1→4)

#### Ưu tiên 1: Kênh Cháy Loại 1 — TUYỆT ĐỐI KHÔNG TRADE
- **Điều kiện:** HH ≥ 1 **VÀ** LL ≥ 1 (đỉnh cao dần + đáy thấp dần đồng thời)
- **Ý nghĩa:** Biên giãn 2 đầu — volatility bùng nổ không kiểm soát
- **Hành động:** Return `is_channel=false`, log "EXPANDING — NGỒI CHƠI"

#### Ưu tiên 2: Kênh Giảm Song Song — CHỈ SELL LIMIT
- **Điều kiện:** LH ≥ 1 **VÀ** LL ≥ 1 **VÀ** HL = 0
- **Ý nghĩa:** Xu hướng giảm thuần, resistance và support cùng nghiêng xuống
- **Proximity filter:** Chỉ kích hoạt khi giá ≤ `upper - 0.5` giá từ trendline trên

#### Ưu tiên 3: Kênh Tăng Song Song — CHỈ BUY LIMIT
- **Điều kiện:** HH ≥ 1 **VÀ** HL ≥ 1 **VÀ** LH = 0
- **Ý nghĩa:** Xu hướng tăng thuần, resistance và support cùng nghiêng lên
- **Proximity filter:** Chỉ kích hoạt khi giá ≥ `lower + 0.5` giá từ trendline dưới

#### Ưu tiên 4: Tam giác nén — BUY STOP + SELL STOP
- **Điều kiện:** LH ≥ 1 **VÀ** HL ≥ 1 (đỉnh thấp dần + đáy cao dần đồng thời)
- **Ý nghĩa:** Giá bị nén, breakout bất kỳ chiều
- **Filter:** `compression ≥ 50%` (biên kênh hiện tại phải nén ≥ 50% so với ban đầu)

### 4.3 Trendline — OLS Linear Regression

Thay vì dùng 2 điểm cuối (dễ bị outlier), dùng **OLS trên toàn chuỗi**:

```
m = [n·Σ(xᵢ·yᵢ) - Σxᵢ·Σyᵢ] / [n·Σ(xᵢ²) - (Σxᵢ)²]
b = (Σy - m·Σx) / n
upper/lower_projected = m × currentIdx + b
```

### 4.4 Filter chiều rộng kênh

Chiều rộng ≥ **0.15%** so với giá mid. Với XAUUSD $4500: tối thiểu ~$6.75.

---

## 5. Entry / TP / SL — Scalp Cố Định

File: `app/Services/SignalFormatterService::buildSignals()`

**Đơn vị:** 1 giá = $1.00 (XAUUSD price move)

### Tất cả bài đánh — TP=2 giá / SL=3 giá (cố định)

```
Buffer entry  = 0.3 giá (tránh fakeout / slippage)

Triangle — BUY STOP:
  Entry = upper + 0.3
  TP    = entry + 2.0
  SL    = entry - 3.0

Triangle — SELL STOP:
  Entry = lower - 0.3
  TP    = entry - 2.0
  SL    = entry + 3.0

Descending — SELL LIMIT:
  Entry = upper - 0.3   (đặt dưới trendline, chờ giá hồi lên)
  TP    = entry - 2.0
  SL    = entry + 3.0

Ascending — BUY LIMIT:
  Entry = lower + 0.3   (đặt trên trendline, chờ giá kéo về)
  TP    = entry + 2.0
  SL    = entry - 3.0
```

**R:R = 2/3 = 0.67** — cần WR ≥ 60% để có lợi nhuận. Phù hợp với scalp ngắn tại vùng key level.

### Lot sizing — Fixed Fractional 2%

```
Lot = (Vốn × 2%) / (SL_giá × 100)
    = (Vốn × 0.02) / (3.0 × 100)
    = Vốn / 15,000
```

| Vốn | Lot | Risk/lệnh |
|---|---|---|
| $150 | 0.01 | $3 (2%) |
| $500 | 0.03 | $9 (1.8%) |
| $1,500 | 0.10 | $30 (2%) |
| $5,000 | 0.33 | $99 (2%) |

> Vốn tối thiểu để lot = 0.01 (đúng 2% risk): **$150**. Dưới mức này bot throw RuntimeException và không trade.

---

## 6. Dedup & Spam Protection

- Mỗi kênh chỉ gửi alert **1 lần trong 2 giờ**
- Cache key: `v5_scan_{symbol}_{tf}_{round(upper)}_{round(lower)}`
- Khi trendline dịch chuyển (OLS cập nhật) → key mới → alert mới

---

## 7. Backtest Infrastructure

### 7.1 Chạy backtest

```bash
# Dùng data từ MT5 (phải chạy FelixBulkExporter.mq5 trước):
php artisan backtest:v53 --capital=500

# Tự seed từ Binance Futures (dev/test):
php artisan backtest:v53 --capital=500 --seed --from=2026-05-01 --to=2026-05-19
```

### 7.2 Kết quả backtest tháng 5/2026 (XAUUSDT M15)

| Loại kênh | Lệnh | Win Rate | P&L |
|---|---|---|---|
| Triangle | 61 | **78.7%** | +$57 (lot 0.01) |
| Descending | 104 | 13.5% | -$239 |
| Ascending | 109 | 25.7% | -$187 |
| **Tổng** | **274** | **32.8%** | **-$369** |

**Kết luận:**
- Triangle scalp 2 giá hoạt động tốt (78.7% WR, R:R 0.67 cần 60%+ → đạt)
- Bounce channel (ascending/descending) WR 13-25% — không đủ cho R:R 0.67
- Nguyên nhân: SELL/BUY LIMIT tại trendline hay bị giá gap qua không quay đầu trong 2 giá

---

## 8. Hạn chế & Cần cải thiện

| Vấn đề | Mức độ | Ghi chú |
|---|---|---|
| Bounce channel WR quá thấp (13-25%) | **HIGH** | Cần confirmation candle hoặc tắt hẳn |
| Dedup 8 bars (2h) quá ngắn → over-trading cùng channel | **HIGH** | Tăng lên 32 bars (8h) |
| EA chỉ push M15, không push H4 | MEDIUM | HTF bias luôn null cho đến khi update EA |
| Lookback 100 nến (~25h) bỏ qua kênh dài 2-3 ngày | LOW | Cần 200+ nến, EA push 150 |
| FelixBulkExporter cần attach thủ công | LOW | Có thể tích hợp vào FelixDataPusher với input date range |
