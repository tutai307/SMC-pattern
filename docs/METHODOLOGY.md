# Felix v5.4 — Phương pháp phân tích & Luồng dữ liệu

> **Phiên bản hiện tại: v5.4** — Pure Math, No AI. Cập nhật lần cuối: 2026-05-19

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
  │ buildH4FromM15()          ← Tổng hợp H4 từ M15 (không cần EA push H4 riêng)
  │ H4 getHTFBias()           ← Top-Down macro filter (adaptive lookback)
  │ detectUnpredictableChannel() — 4 mô hình hình học (chỉ Triangle active v5.4)
  │ calculateATR()
  │ proximity check + dedup
  ▼
Telegram Alert (không có AI)
```

**Thay đổi lớn so với v5.3:**
- TP/SL cố định (2/3 giá) → TP/SL động (ATR x1.5 / channel width x80%)
- Ascending/Descending channels bị DISABLED — chỉ trade Triangle
- buildH4FromM15() thay thế getKlines('4h') — EA chỉ cần push M15
- getHTFBias() adaptive lookback (min 10 bars H4 thay vì 20)

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
- **v5.4**: Không cần push H4 riêng — server tự tổng hợp từ M15

### 2.2 MT5 Script — FelixBulkExporter.mq5 (Backtest)

Script one-shot để export lịch sử M15 lên server. Drag vào chart, điền `StartDate`/`EndDate`, chạy 1 lần.

- Gửi theo chunk 500 bars/request → tích lũy trên server (merge + dedup + sort)
- Key lưu: `mt5_bulk_{SYMBOL}_{TF}`, TTL 7 ngày
- Dùng bởi `backtest:v53` command

### 2.3 Server — MarketDataService

- Klines real-time lưu với key `mt5_klines_XAUUSDT_15m`, TTL **120 phút**
- Giá bid lưu với key `mt5_price_XAUUSDT`, TTL **30 giây**
- Symbol chuẩn hóa: `XAUUSDm / XAUUSD → XAUUSDT`
- **v5.4**: `buildH4FromM15(array $m15Klines): array` — tổng hợp H4 từ M15 (UTC boundary grouping)

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
M15 klines (200 bars)
  → buildH4FromM15()           ← Tổng hợp bởi MarketDataService (v5.4 mới)
  → getHTFBias(h4Klines)
  → detectUnpredictableChannel(lookback = adaptive)
  → direction: 'LONG' | 'SHORT' | null
```

**Adaptive lookback (v5.4):**
- Cần tối thiểu 10 bars H4 (giảm từ 20 để khởi động nhanh hơn)
- `lookback = min(50, count(h4Klines) - 6)`
- Nếu lookback < 2 → return null

**buildH4FromM15 logic:**
- Nhóm các bar M15 theo UTC 4-hour boundary (timestamp chia hết cho 4×3600×1000)
- Open = bar M15 đầu tiên trong nhóm; Close = bar M15 cuối cùng; High/Low = max/min toàn nhóm; Volume = tổng

**Filter logic trong ScanSignalsCommand:**
- Nếu H4 bias = `LONG` nhưng M15 channel = `SHORT` → **skip** (counter-trend)
- Nếu H4 bias = null (không rõ / không đủ data) → **cho qua** (graceful fallback)
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

#### Ưu tiên 2: Kênh Giảm Song Song — **DISABLED v5.4**
- **Điều kiện:** LH ≥ 1 **VÀ** LL ≥ 1 **VÀ** HL = 0
- **Ý nghĩa:** Xu hướng giảm thuần, resistance và support cùng nghiêng xuống
- **v5.4 status:** Return `is_channel=false` — bị lọc ở ScanSignalsCommand trước khi buildSignals
- **Lý do disable:** Backtest tháng 5/2026 WR chỉ 13.5% — không đủ lợi nhuận ở R:R < 1

#### Ưu tiên 3: Kênh Tăng Song Song — **DISABLED v5.4**
- **Điều kiện:** HH ≥ 1 **VÀ** HL ≥ 1 **VÀ** LH = 0
- **Ý nghĩa:** Xu hướng tăng thuần, resistance và support cùng nghiêng lên
- **v5.4 status:** Return `is_channel=false` — bị lọc ở ScanSignalsCommand trước khi buildSignals
- **Lý do disable:** Backtest tháng 5/2026 WR chỉ 25.7% — không đủ lợi nhuận ở R:R < 1

#### Ưu tiên 4: Tam giác nén — BUY STOP + SELL STOP (ACTIVE)
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

## 5. Entry / TP / SL — Động theo ATR và Channel Width

File: `app/Services/SignalFormatterService::buildSignals()`

**Đơn vị:** 1 giá = $1.00 (XAUUSD price move)

### Triangle — BUY STOP + SELL STOP (bài đánh duy nhất active)

```
Buffer entry  = 0.3 giá (tránh fakeout / slippage)

SL = 1.5 × ATR(14)                   (SL động theo biến động thực)
TP = (upper - lower) × 80%           (TP = 80% chiều rộng kênh hiện tại)

Bộ lọc sống còn: SL <= 0 hoặc TP < SL → null (không trade)

Triangle — BUY STOP:
  Entry = upper + 0.3
  TP    = entry + TP_giá
  SL    = entry - SL_giá

Triangle — SELL STOP:
  Entry = lower - 0.3
  TP    = entry - TP_giá
  SL    = entry + SL_giá
```

**R:R = TP / SL** — biến động theo từng lần quét. Ví dụ với ATR=5.0, channel width=10.0:
- SL = 1.5 × 5.0 = 7.5 giá
- TP = 10.0 × 0.8 = 8.0 giá
- R:R = 8.0 / 7.5 = 1.07 (tối thiểu cần R:R ≥ 1.0 để survival filter pass)

### Lot sizing — Fixed Fractional 2%

```
Lot = (Vốn × 2%) / (SL_giá × 100)
```

| Vốn | SL 7.5 giá | Lot | Risk/lệnh |
|---|---|---|---|
| $500 | 7.5 | 0.01 | $7.5 (~1.5%) |
| $1,500 | 7.5 | 0.04 | $30 (~2%) |
| $5,000 | 7.5 | 0.13 | $97.5 (~2%) |

> Vốn tối thiểu phụ thuộc vào SL động: với SL=7.5 giá cần ≥ $375. Bot throw RuntimeException nếu lot < 0.01.

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

### 7.2 Kết quả backtest tháng 5/2026 (XAUUSDT M15) — nền tảng quyết định v5.4

| Loại kênh | Lệnh | Win Rate | P&L | Quyết định |
|---|---|---|---|---|
| Triangle | 61 | **78.7%** | +$57 (lot 0.01) | ACTIVE |
| Descending | 104 | 13.5% | -$239 | DISABLED |
| Ascending | 109 | 25.7% | -$187 | DISABLED |
| **Tổng** | **274** | **32.8%** | **-$369** | Triangle only |

**Lý do disable Bounce channels:**
- R:R cố định cũ 2/3 = 0.67 → cần WR ≥ 60% để breakeven
- Descending WR 13.5% — không đủ (thiếu 46.5 điểm phần trăm)
- Ascending WR 25.7% — không đủ (thiếu 34.3 điểm phần trăm)
- Nguyên nhân kỹ thuật: SELL/BUY LIMIT tại trendline hay bị giá gap qua không quay đầu
- Chờ đủ dữ liệu backtest với SL/TP động để đánh giá lại

**Triangle với TP/SL động (v5.4 dự kiến):**
- TP = channel_width × 80% → tận dụng đà bùng nổ sau phá vỡ tam giác
- SL = 1.5 × ATR → thích nghi với biến động thực tế, tránh SL cứng quá sát
- Backtest với công thức mới đang chờ dữ liệu tích lũy

---

## 8. Hạn chế & Cần cải thiện

| Vấn đề | Mức độ | Ghi chú |
|---|---|---|
| Bounce channels disabled | MEDIUM | Chờ backtest thêm với SL/TP động trước khi bật lại |
| Dedup 2h có thể quá ngắn → over-trading cùng channel | HIGH | Cân nhắc tăng lên 8h |
| Triangle TP/SL động chưa có backtest dài hạn | HIGH | Cần chạy backtest:v53 với công thức v5.4 |
| EA chỉ push 150 bars M15 (~37.5h) | LOW | 200 bars sẽ capture được kênh 2 ngày tốt hơn |
| buildH4FromM15 chỉ dùng UTC boundary — không align với session trading | LOW | Xem xét dùng session offset nếu cần |
| FelixBulkExporter cần attach thủ công | LOW | Có thể tích hợp vào FelixDataPusher với input date range |
