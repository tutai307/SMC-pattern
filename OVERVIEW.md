# Felix — Price Action Terminal · System Overview

> Cập nhật: 2026-05-18 · Branch: smc_v2

---

## Mục đích

Ứng dụng phân tích kỹ thuật crypto futures thời gian thực.  
Kết hợp **SMC (Smart Money Concepts)** + **AI validation** (GPT-4o) để tạo tín hiệu giao dịch có xác suất thắng cao.  
Giao tiếp chính qua **Telegram bot** — không cần mở web.

---

## Stack

| Layer | Tech |
|---|---|
| Backend | Laravel 12, PHP 8.2+, MySQL |
| Frontend | Blade + Tailwind 4 + Vite 7 + Lightweight Charts 4.1 |
| AI | OpenRouter GPT-4o (signal scoring) · GPT-4o-mini (bot chat) |
| Exchange | Binance Futures `fapi.binance.com/fapi/v1` |
| Cache/Queue | MySQL-backed (database driver) |
| Deploy | Railway (server + queue + telegram + scanner processes) |

---

## Kiến trúc

```
Telegram User
     │
     ▼
TelegramBotCommand         (long-polling, chat tự nhiên)
     │
     ▼
PriceActionService         (SMC engine: OB, FVG, BOS/CHoCH, EMA, ATR, ADX)
     │                ▲
     ▼                │
BinanceService         TradingSignal (MySQL)
(klines, price,              │
 funding, OI)                │
                      ▼
              ScanSignalsCommand   (cron mỗi 5 phút, tự động scan + review)
              MonitorSignalsCommand (check TP/SL hit realtime)
```

---

## Luồng sinh tín hiệu

```
1. Fetch klines LTF (15m) + HTF (1h) từ Binance
2. PriceActionService::analyze()
   ├── detectSMCStructure()  → BOS/CHoCH, trend TĂNG/GIẢM/ĐI NGANG
   ├── detectFVG()           → Bullish/Bearish Fair Value Gaps
   └── findHighQualityOB()   → Order Blocks (displacement ≥1.8x avg body + FVG confirm)
3. generateSMCSignal()
   ├── Tính entry, SL (≥1.5% ATR-based), TP (R:R 1:2)
   ├── Filter: OB width < 0.3% → skip
   └── Filter: TP distance theo TF (15m:0.4%, 1h:0.8%, 4h:1.5%)
4. enrichWithAIScore() → GPT-4o → {score, analysis, risk, recommendation}
5. Gate checks:
   ├── AI score < 60  → block
   ├── R:R < 1.5      → block
   └── recommendation chứa BỎ QUA/KHÔNG NÊN → block
6. Signal saved → DB · TelegramBotCommand gửi thông báo
```

---

## Telegram Bot — Commands

| Lệnh | Tác dụng |
|---|---|
| `/l` hoặc `/list` | List PENDING + đánh giá unfilled/running (fresh AI) |
| `/s` hoặc `/status` | P&L realtime tất cả lệnh đang chạy |
| `/c <id>` | Cancel signal |
| `/sig <id>` | Chi tiết 1 lệnh |
| `/filled <id>` | Mark filled thủ công |
| `xag` · `btc 1h 200u` | Phân tích tín hiệu (SMC + AI) |
| `xag giá` · `btc như nào` | Giá nhanh + xu hướng HTF/LTF |
| `ok` · `ok 500` | Xác nhận lưu lệnh (+ vốn $500) |
| `không` · `thôi` | Huỷ pending |

**Group chat:** bot chỉ trả lời khi được `@mention`.  
**TELEGRAM_CHAT_ID:** comma-separated cho multi-chat (`7165470451,-1003973673692`).

---

## Auto Scanner (ScanSignalsCommand)

- Interval: **5 phút**
- Symbols: `SOLUSDT:15m, XAGUSDT:15m, LINKUSDT:15m, ETHUSDT:15m, BTCUSDT:15m, XAUUSDT:15m`
- HTF mapping: `15m → 1h` · `1h, 4h → 4h`

### Scan Gates (scanPair)
- Không phải SNIPER pattern (OB+CHoCH) → skip
- AI score < 70 → skip
- Dedup: DB check PENDING cùng symbol/TF/type trong 4h
- XAGUSDT: XAU bearish → block LONG

### Volatility Circuit Breaker
- Range > 2.5× ATR(14) → tạm dừng scan symbol đó 3 candles
- Gửi FOMO alert (hướng nến, movePct)
- Gửi emergency alert cho RUNNING signals đang bị ảnh hưởng

### Auto-review (RUNNING signals)
- Trigger: mỗi 10 phút hoặc giá thay đổi ≥ 1.0% hoặc P&L flip dương↔âm
- Gửi Telegram chỉ khi: CẮT LỖ / CHỐT LỜI / DI CHUYỂN / ĐIỀU CHỈNH / 50% TP hoặc P&L flip
- KHÔNG gửi khi verdict = GIỮ LỆNH và không có sự kiện đặc biệt

### AI Advice Hard Rules
```
slUsedPct = abs(pnlPct) / slPct × 100   ← % quãng đường đến SL
CẮT LỖ NGAY:  slUsedPct ≥ 60% HOẶC CHoCH ngược chiều
CHỐT LỜI NGAY: tpUsedPct ≥ 70% HOẶC BOS ngược chiều
GIỮ LỆNH:     slUsedPct < 60%
```

---

## Caching

| Key | TTL | Mục đích |
|---|---|---|
| `binance_klines_*` | 10s (1m/5m/15m) · 60s (1h+) | Klines |
| `price_{symbol}` | 2s | Giá realtime |
| `coin_quality_{symbol}` | 300s | Volume/OI/Funding score |
| `ai_analysis_{md5}` | 3600s | AI signal score |
| `advise_{md5}` | 300s | AI advice mỗi 5 phút · invalidate khi P&L flip |
| `volatility_breaker_*` | 3 × interval | Circuit breaker cooldown |
| `scan_sent_*` | 4h | Signal dedup |

**advise cache key** = `md5(symbol + TF + type + entry + sl + tp + priceBucket + pnlSign)`  
`priceBucket` = round(price / 0.5%step) — invalidate khi giá dịch 0.5%

---

## Database Schema

**`trading_signals`** (bảng chính):
```
symbol, timeframe, type (LONG/SHORT)
entry_price, tp_price, sl_price  (decimal 18,8)
winrate (int, AI confidence %)
status: PENDING → FILLED → WIN/LOSS/CANCELLED
reason (text, analysis + AI explanation)
capital (float, vốn user nhập)
ai_score, ai_analysis, ai_risk, ai_recommendation
```

---

## Backtest (BacktestCommand)

```bash
php artisan backtest {symbol} {timeframe} {--start=} {--end=} [--days=] [--no-ai]
```

- Dữ liệu: `data.binance.vision` monthly ZIP (cache tại `storage/app/binance_vision/`)
- Method: SMC thuần (đã xóa Elliott Wave và crypto EMA method)
- Dynamic risk: `--local-score` mode — localScore ≥ 85 → $8/trade, else → $2/trade
- Slippage: +0.05% vào entry
- Max look-ahead: 20 candles để check TP/SL hit

### Kết quả backtest tốt nhất (XAGUSDT 15m, 2026-01-01→05-14, SMC)
- WR ~46–52% với RR 1:2.5 và AI-risk 8%
- HTF 1h tốt hơn HTF 4h (46.4% vs 38.1% WR)

---

## Bugs đã biết

| ID | Mô tả | Status |
|---|---|---|
| `db-import` | `config/database.php:4` — `use Pdo\Mysql;` sai namespace | ❓ Chưa fix |
| `cache-key` | `binance_klines_..._now` collision khi `startTime=null` | ❓ Chưa fix |
| `input-validation` | Thiếu whitelist cho symbol/timeframe params | ❓ Chưa fix |

---

## Dev Commands

```bash
composer run dev          # server + queue + logs + vite (concurrent)
php artisan telegram:bot  # Telegram long-polling bot
php artisan signals:scan  # Auto scanner + AI review
php artisan signals:monitor  # TP/SL monitor (legacy standalone)
php artisan test          # PHPUnit
php artisan pail          # Stream logs

# Backtest
php artisan backtest XAGUSDT 15m --start=2026-01-01 --end=2026-05-14 --no-ai
```

---

## Key Files

| File | Vai trò |
|---|---|
| `app/Services/PriceActionService.php` | Core SMC engine (~1100 lines) |
| `app/Console/Commands/TelegramBotCommand.php` | Bot long-polling + chat NLP |
| `app/Console/Commands/ScanSignalsCommand.php` | Auto scanner + circuit breaker + auto-review |
| `app/Console/Commands/BacktestCommand.php` | Historical backtest engine |
| `app/Console/Commands/MonitorSignalsCommand.php` | TP/SL hit detection |
| `app/Services/BinanceService.php` | Binance API + caching + Vision downloader |
| `app/Services/TelegramService.php` | Telegram API wrapper (multi-chat) |
| `app/Models/TradingSignal.php` | Eloquent model |
| `.claude/SYSTEM_STATE.md` | Trạng thái hệ thống sống — đọc trước khi code |
