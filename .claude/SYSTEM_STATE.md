# SYSTEM STATE — Felix Price Action Terminal

> Đọc file này TRƯỚC khi đọc source code. Cập nhật sau mỗi session.
> Format ngày: YYYY-MM-DD HH:MM

---

## Stack Snapshot
- Laravel 12 / PHP 8.2 / MySQL
- Cache/Queue: database driver
- Frontend: Blade + Tailwind 4 + Vite 7 + Lightweight Charts 4.1
- AI: OpenRouter GPT-4o (signal scoring) + GPT-4o-mini (Telegram chat)
- Exchange: Binance Futures (`fapi.binance.com/fapi/v1`)

---

## Architecture (không đổi)
```
TelegramBotCommand ──→ PriceActionService ──→ TradingSignal (DB)
ScanSignalsCommand  ──→ BinanceService    ──→ OpenRouter AI
MonitorSignalsCommand    (klines, price)      (advise, score)
```

---

## Key File Map (line numbers approximate — verify khi đọc)
| File | Trọng tâm |
|---|---|
| `app/Services/PriceActionService.php` | `generateSMCSignal()` L430, `adviseOpenPosition()` L1105, `enrichWithAIScore()` L930 |
| `app/Console/Commands/TelegramBotCommand.php` | `handleFreeText()` L103, `confirmPendingSignal()` L584, `reviewRunningSignal()` L821 |
| `app/Console/Commands/ScanSignalsCommand.php` | `autoReviewRunningSignals()` L254, `scanPair()` L374 |
| `app/Console/Commands/MonitorSignalsCommand.php` | `checkSignal()` L132, `autoDetectFill()` L101 |
| `app/Services/BinanceService.php` | klines cache, price cache |

---

## Current Signal Logic (CRITICAL — đọc trước khi sửa)

### TP/SL Ratios
- LONG TP: `entry + (entry - sl) * 2.0` → R:R = 1:2
- SHORT TP: `entry - (sl - entry) * 2.0` → R:R = 1:2
- SL minimum: LONG ≥ 1.5% dưới entry, SHORT ≥ 1.5% trên entry

### Signal Gates (TelegramBotCommand::runAnalysis)
- AI score < 60 → block, không propose
- R:R < 1.5 → block, không propose
- AI recommendation chứa BỎ QUA/KHÔNG NÊN/TRÁNH → block

### Auto Scan Gates (ScanSignalsCommand::scanPair)
- AI score < 70 → skip
- Không phải SNIPER (OB+CHoCH) → skip
- AI recommendation starts with BỎ QUA → skip
- XAGUSDT: check gold correlation (XAU bearish → block LONG)

### AI Advice Cache (adviseOpenPosition)
- Key: `advise_` + md5(symbol+timeframe+type+entry+sl+tp+priceBucket+pnlSign)
- priceBucket = round(currentPrice / max(entry * 0.005, 0.0001))  ← 0.5% buckets
- pnlSign = '+' hoặc '-' ← invalidate khi P&L đảo chiều
- TTL: 300s (5 phút)
- fresh=true: bypass cache hoàn toàn (dùng từ /l command)

### AI Advice Hard Rules (trong prompt)
- slUsedPct = abs(pnlPct) / slPct * 100 — % quãng đường đến SL đã dùng
- CẮT LỖ NGAY: CHỈ khi slUsedPct ≥ 60% HOẶC CHoCH ngược chiều
- CHỐT LỜI NGAY: CHỈ khi tpUsedPct ≥ 70% HOẶC BOS ngược chiều
- GIỮ LỆNH: khi slUsedPct < 60% (chưa bị đe dọa nghiêm trọng)
- sl_advice/tp_advice default = null (không phải string "null")

---

## Telegram Bot — Command Map
| Input | Handler | Hành động |
|---|---|---|
| `/l` hoặc `/list` | `cmdList()` | List PENDING + evaluate unfilled (no AI) + evaluate running (fresh AI) |
| `/s` hoặc `/status` | `cmdStatus()` | P&L realtime tất cả lệnh đang chạy |
| `/c <id>` hoặc `/cancel <id>` | `cmdCancel()` | Cancel signal |
| `/sig <id>` hoặc `/signal <id>` | `cmdSignal()` | Chi tiết 1 lệnh |
| `/filled <id>` | `cmdFilled()` | Mark as filled manually |
| `ok` | `handleFreeText()` | `confirmPendingSignal(0.0)` — lưu ngay |
| `ok 500` | `handleFreeText()` | `confirmPendingSignal(500.0)` |
| `không` | `handleFreeText()` | Clear pending cache |
| `xag` / `btc` / coin name | `parseSignalRequest()` | Trigger analysis |
| `xag swing` / `btc 1h 200u` | `parseSignalRequest()` | Analysis với TF + vốn |

### Pre-flight: ĐÃ BỎ
Không còn 2-bước confirm. "ok" lưu thẳng.

---

## Auto-review Rules (ScanSignalsCommand)
- Interval: 600s (10 phút)
- Price shift trigger: 1.0% thay đổi
- P&L flip trigger: LUÔN gửi khi dương → âm
- Chỉ gửi Telegram khi: CẮT LỖ / CHỐT LỜI / DI CHUYỂN / ĐIỀU CHỈNH / 50% hoặc P&L flip
- KHÔNG gửi khi verdict = GIỮ LỆNH và không có P&L flip
- KHÔNG gửi "no setup" reminder (đã xóa sendNoSetupReminder)

---

## Known Bugs Status
| ID | Mô tả | Status |
|---|---|---|
| db-import | `config/database.php:4` — `use Pdo\Mysql;` sai | ❓ Chưa fix |
| adx-threshold | ADX threshold 15 quá thấp | ✅ Đã raise (check current value) |
| cache-key | binance_klines collision khi startTime=null | ❓ Chưa fix |
| input-validation | Thiếu whitelist cho symbol/timeframe | ❓ Chưa fix |
| pricebucket-formula | adviseOpenPosition cache always=200 | ✅ Fixed 2026-05-09 |
| null-string-advice | sl_advice/tp_advice hiện "null" string | ✅ Fixed 2026-05-09 |
| rr-gate | Không gate R:R và AI score thấp | ✅ Fixed 2026-05-09 |
| cache-stale | advise cache không invalidate khi P&L flip | ✅ Fixed 2026-05-09 |

---

## Recent Changes
| Date | Change | Files |
|---|---|---|
| 2026-05-09 | Fix priceBucket formula (always 200 bug), giảm TTL 1800→300s, thêm pnlSign vào cache key | PriceActionService.php |
| 2026-05-09 | Fix sl_advice/tp_advice "null" string | PriceActionService.php |
| 2026-05-09 | Add AI score < 60 gate + R:R < 1.5 gate trong runAnalysis | TelegramBotCommand.php |
| 2026-05-09 | R:R thay đổi 3.0 → 2.0 (TP gần hơn, winrate cao hơn) | PriceActionService.php |
| 2026-05-09 | AI review interval 1800→600s, trigger P&L flip, chỉ gửi actionable verdicts | ScanSignalsCommand.php |
| 2026-05-09 | Bỏ pre-flight 2 bước, "ok"/"ok 500" lưu thẳng | TelegramBotCommand.php |
| 2026-05-09 | Add command aliases /l /s /c /sig | TelegramBotCommand.php |
| 2026-05-09 | Xóa sendNoSetupReminder — không spam tin nhắn | ScanSignalsCommand.php |
| 2026-05-09 | /l dùng fresh=true → bypass cache, luôn data mới | TelegramBotCommand.php + PriceActionService.php |
| 2026-05-09 | Fix AI cắt lỗ sớm: thêm slUsedPct calculation + hard rules (CẮT LỖ chỉ khi ≥60% SL, CHỐT LỜI chỉ khi ≥70% TP) | PriceActionService.php |
| 2026-05-10 | Đổi scanner sang M15 cho 6 symbols (SOL/XAG/LINK/ETH/BTC/XAU), cập nhật backtestStats Jan-May 2026, thêm PnL 2% risk vào scan alert | ScanSignalsCommand.php, TelegramService.php |
| 2026-05-11 | Tăng LocalScore weights trong computeConfidenceScore(): HTF 25→30, SIDEWAYS 12→15, ADX mid-range 14→18/20→23, Momentum max 20→22 (div by 4), OB SNIPER 15→18/HIGH 12→15, EMA200 10→12, BOS 5→8. Portfolio backtest $100→$497 (+397%) vs cũ $338 | PriceActionService.php L1239-L1282 |
| 2026-05-11 | [F1] LocalScore fallback khi AI lỗi: scanPair() gọi computeConfidenceScore() sau analyze(), dùng localScore≥85→$8/dưới→$2 khi aiError; log cả AI+LocalScore khi AI OK | ScanSignalsCommand.php L427-L460 |
| 2026-05-11 | [F1] Expose formatCandlesPublic() public wrapper trong PriceActionService | PriceActionService.php L145 |
| 2026-05-11 | [F2] Unfilled expiry 8h: thêm checkUnfilledExpiry() + sendUnfilledExpiry() + migration notified_expiry | ScanSignalsCommand.php L157-L170, MonitorSignalsCommand.php L81-L93, TelegramService.php L154, TradingSignal.php, migration 2026_05_11_150230 |
| 2026-05-12 | [F3] trend:hourly command: dự đoán xu hướng multi-TF (daily EMA20 + 4h RSI/ADX + 1h swing) cho XAGUSDT/XAUUSDT/BTCUSDT, schedule hourly, gửi Telegram qua sendRaw() | TrendHourlyCommand.php (mới), routes/console.php |
| 2026-05-13 | [F4] Exness lot sizing: thêm calculateExnessLots() static method, thay USDT notional/leverage bằng lots display trong sendScanAlert() và sendNewSignal(), DashboardController tính exness_lots song song positionSize | PriceActionService.php L401-L441, TelegramService.php L42-L60+L402-L409, DashboardController.php L79-L96 |

---

## Last Test Run
*Chưa có — chạy /test để cập nhật*

---

## Last BA Analysis
*Chưa có — chạy /ba để cập nhật*

---

## Environment
```env
DB_CONNECTION=mysql
DB_DATABASE=price_action
OPENROUTER_API_KEY=sk-or-v1-...
TELEGRAM_BOT_TOKEN=...
TELEGRAM_CHAT_ID=...
SCAN_SYMBOLS=SOLUSDT:15m,XAGUSDT:15m,LINKUSDT:15m,ETHUSDT:15m,BTCUSDT:15m,XAUUSDT:15m
```

## Dev Commands
```bash
composer run dev    # server + queue + logs + vite
php artisan telegram:bot     # start bot
php artisan signals:scan     # start scanner + monitor + AI review
php artisan signals:monitor  # standalone monitor (legacy)
php artisan test             # run tests
php artisan pail             # stream logs
```
