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
| 2026-05-14 | Thêm --method=crypto vào BacktestCommand: EMA 20/50/200 + ATR 1.5x SL + volume check, bỏ funding/LS ratio. Fix $isLong/$isLongEntry cho LONG/SHORT type. Thêm --crypto-rr option. | BacktestCommand.php |
| 2026-05-14 | Fix generateCryptoSignal() v2: HTF EMA20/50 trend filter + OB/FVG pullback entry (inline detect) + dedup 20 candles. Entry = OB/FVG mid thay currentPrice. | BacktestCommand.php |
| 2026-05-14 | Add ADX(14) >= 20 filter + session filter [07,17) UTC vào generateCryptoSignal() — thêm calcAdxLast() helper. | BacktestCommand.php |
| 2026-05-14 | [v4] generateCryptoSignal() GitHub-research overhaul: OB 2-of-3 quality score (range>=1.5xATR, body>=60%range, vol>=1.5x), Fib61.8 entry, OB mitigation >50% check, rejection wick 15%, RSI(14) gate (<65 LONG/>35 SHORT), EMA9 momentum confirm, ADX 25, bỏ session filter. Thêm calcRsiLast(). | BacktestCommand.php |
| 2026-05-14 | [v5] generateCryptoSignal() 4-layer filter: L1=daily EMA20 bias, L2=mini BOS confirm, L3=OB fresh<=10c+body>=70%+vol>=2.0x, L4=SL 0.5xATR+max2.5%. Truyền $dailyWin vào hàm. WR 40.7%, P&L +$24, signals 64. | BacktestCommand.php |
| 2026-05-14 | Dọn dẹp crypto method: xóa CryptoSignalService.php, xóa generateCryptoSignal/calcAdxLast/calcRsiLast/--crypto-rr khỏi BacktestCommand, xóa METALS/scanCrypto/CryptoSignalService inject khỏi ScanSignalsCommand. Giữ nguyên SMC thuần. | CryptoSignalService.php (deleted), BacktestCommand.php, ScanSignalsCommand.php |
| 2026-05-14 | Xóa toàn bộ Elliott Wave: detectElliotWaves/calculateElliotFibonacci/generateElliotSignal khỏi PriceActionService, bỏ method=elliot khỏi BacktestCommand + DashboardController, xóa $isElliot block khỏi TelegramService, xóa ELLIOT button + fibonacci UI + JS zigzag/fib khỏi welcome.blade.php. SMC-only. | PriceActionService.php, BacktestCommand.php, DashboardController.php, TelegramService.php, welcome.blade.php |
| 2026-05-14 | Fix hardReviewCheck() cắt lỗ sớm: (1) Nâng consecutive LTF 4→5 candles; (2) HTF check yêu cầu đồng thời swing ngược + 3 candles HTF liên tiếp + P&L < -2% (trước: swing ngược + P&L < -1%). | ScanSignalsCommand.php |
| 2026-05-15 | Fix routing "kèo/phân tích [coin]" → luôn gọi PriceActionService thật (không qua AI). Thêm Route 2: "[coin] giá/như nào" → quickPriceReply() trả giá + HTF + LTF trend ngay, không AI. Thêm extractSymbolFromText() helper. | TelegramBotCommand.php |
| 2026-05-15 | Thêm OB width filter (< 0.3% skip) trong findHighQualityOB(). Thêm TP distance filter theo TF (15m:0.4%, 1h:0.8%, 4h:1.5%) trong generateSMCSignal() LONG+SHORT. Thêm TP min filter theo TF trong fireZoneApproach() thay thế flat 1.5%. | PriceActionService.php L439, L598-600, L699-701; ScanSignalsCommand.php L695-704 |
| 2026-05-15 | Thêm volatility circuit breaker: isVolatilityCircuitBreakerActive() L724-757, check trong scanPair() L436-439. Range > 2.5x ATR(14) → pause 3 candles via Cache. Zone alerts tự tắt vì scanPair() return sớm trước checkZones(). | ScanSignalsCommand.php |
| 2026-05-15 | Thêm emergency alert cho RUNNING signals trong circuit breaker (L770-792): khi black swan xảy ra, query RUNNING signals của symbol, tính P&L realtime, gửi sendRaw() cảnh báo khẩn từng lệnh. Ngoài if($cancelled>0), luôn chạy kể cả khi không có PENDING. | ScanSignalsCommand.php |
| 2026-05-15 | Thêm FOMO opportunity alert trong circuit breaker (L771-790): gửi hướng nến (LONG/SHORT), movePct, suggestion TRƯỚC alert lệnh đang chạy — user thấy cơ hội trước rồi mới thấy cảnh báo. | ScanSignalsCommand.php |

---

## Last Test Run
*Chưa có — chạy /test để cập nhật*

---

## Last BA Analysis
2026-05-14 | Backtest SOLUSDT 15m crypto method 2026-01-01→2026-05-14: 363 signals, WR 29.3% (106W/256L), P&L -$88 / capital cuối $12. Fill rate 100%. EMA-only filter tạo quá nhiều noise trên 15m — cần thêm OB/FVG hoặc session filter.
2026-05-14 | Backtest SOLUSDT 4h crypto method 2026-01-01→2026-05-14: 26 signals, WR 26.9%, P&L -$3 (net -3%), EMA alignment-only filter quá weak. Cần thêm OB/FVG confirmation để cải thiện precision.
2026-05-14 | [v2] Backtest SOLUSDT 15m crypto v2 (HTF 1h EMA + OB/FVG pullback) 2026-01-01→2026-05-14: 133 signals (-63%), WR 32.1% (35W/74L), P&L -$8 / capital $92. Fill rate 82%. Tín hiệu giảm mạnh, WR tăng nhẹ, P&L cải thiện từ -$88 → -$8. Vẫn cần cải thiện WR lên >40% để profitable ở R:R 2.5.
2026-05-14 | [v3 ADX+Session] Backtest SOLUSDT 15m crypto v3 (ADX>=20 + session 07-17 UTC) 2026-01-01→2026-05-14: 78 signals, WR 27.1% (16W/43L), P&L -$22 / capital $78. Fill rate 75.6%. ADX/session filter TIDAK membantu — WR turun 32%→27%, P&L memburuk -$8→-$22. Tín hiệu giảm 133→78 nhưng signal quality xấu hơn. ADX 20 threshold có thể quá thấp; cần thử ADX 25-30 hoặc bỏ session filter.
2026-05-14 | [v4 GitHub-research] Backtest SOLUSDT 15m crypto v4 2026-01-01→2026-05-14: 83 signals, WR 36.5% (23W/40L), P&L +$12 / capital $112. Fill rate 75.9%. BREAKTHROUGH.
2026-05-14 | [v5 4-layer] Backtest SOLUSDT 15m crypto v5 2026-01-01→2026-05-14: 64 signals, WR 40.7% (22W/32L), P&L +$24 / capital $124. Fill rate 84.4%. WR tăng 36.5→40.7% (+4.2%), P&L tăng +$12→+$24 (×2). Signals giảm 83→64. Bottleneck còn lại: chuỗi thua nhiều lên tới 3 lệnh liên tiếp (Jan 9: -3L, Feb 4: -2L). WR 40.7% vẫn chưa đủ cho breakeven tại R:R 2.5 (cần 28.6%), nhưng chưa đủ margin an toàn để trade live — target WR >=45%.

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
