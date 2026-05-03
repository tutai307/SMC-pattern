# TOM AI — Price Action Terminal

Ứng dụng phân tích kỹ thuật crypto futures thời gian thực, kết hợp SMC + Elliott Wave + AI validation để tạo tín hiệu giao dịch có xác suất thắng cao.

## Stack

| Layer | Tech |
|---|---|
| Backend | Laravel 12, PHP 8.2+, MySQL |
| Frontend | Blade, Tailwind 4, Vite 7, Lightweight Charts 4.1 |
| APIs | Binance Futures (`fapi.binance.com/fapi/v1`), OpenRouter GPT-4o |
| Cache/Queue | MySQL-backed (database driver) |

## Dev Commands

```bash
composer run dev          # server + queue + logs + vite (concurrent)
composer run setup        # full init: install → migrate → npm build
php artisan migrate       # run migrations
npm run build             # build assets
php artisan test          # run PHPUnit
php artisan pail          # stream logs
```

## Architecture

```
DashboardController  →  PriceActionService  →  TradingSignal (DB)
        ↓                      ↓
  BinanceService         OpenRouter AI
  (klines, price)       (signal scoring)
```

**Routes** (`routes/web.php`):
- `GET /` — main terminal (symbol, timeframe, method, propose params)
- `GET /academy` — SMC/Elliott education
- `GET /planner` — trade planning wizard
- `POST /signals/bulk-delete`, `POST /clear-all-signals`, `DELETE /signals/{id}`

## Key Files

| File | Purpose |
|---|---|
| `app/Http/Controllers/DashboardController.php` | Routing, signal CRUD, WIN/LOSS detection |
| `app/Services/PriceActionService.php` | Core analysis engine (646 lines) |
| `app/Services/BinanceService.php` | Binance API + caching |
| `app/Models/TradingSignal.php` | DB model |
| `resources/views/welcome.blade.php` | Main terminal UI (645 lines) |
| `resources/views/academy.blade.php` | Education content |
| `resources/views/planner.blade.php` | Trade planner wizard |
| `public/css/dashboard.css` | Dark theme |
| `database/migrations/` | 3 custom tables |

## Database Schema

**`trading_signals`** (primary table):
- `symbol`, `timeframe`, `type` (LONG/SHORT)
- `entry_price`, `tp_price`, `sl_price` (decimal 18,8)
- `winrate` (int, confidence %), `status` (PENDING/WIN/LOSS/CANCELLED)
- `reason` (text, analysis explanation)

**`market_data`** — OHLCV store (unused, planned)  
**`news_articles`** — sentiment (unused, planned)

## Signal Generation Flow

```
1. Fetch klines LTF (15m/1h/4h) + HTF (4h/1d) from Binance
2. PriceActionService::analyze() dispatches:
   - SMC: detectSMCStructure → detectFVG → findHighQualityOB → generateSMCSignal
   - Elliott: detectElliotWaves → generateElliotSignal
3. Indicators: EMA(200), ATR(14), ADX(14)
4. enrichWithAIScore() → OpenRouter GPT-4o → JSON {score, analysis, risk, recommendation}
5. User clicks "ĐỀ XUẤT LỆNH" → POST ?propose=1 → TradingSignal saved
6. updateSignalStatuses() checks new klines for TP/SL hits
```

## Analysis Algorithms

**SMC** (`PriceActionService`):
- `detectSMCStructure()`: BOS/CHoCH detection, trend TĂNG/GIẢM/ĐI NGANG
- `detectFVG()`: Bullish/Bearish Fair Value Gaps
- `findHighQualityOB()`: Order Blocks (displacement ≥1.8x avg body + FVG confirmation)
- ADX threshold: 15 (range-bound filter) — consider raising to 20-25

**Elliott Wave**:
- Pivot extraction with 10-candle fixed window
- Wave labeling: 1-2-3-4-5 impulse + A-B-C correction
- Signal triggers: Wave 3 entry, Wave C completion

**AI Scoring** (cache 1h per unique signal):
- Sends 60 candles OHLCV + signal params → GPT-4o
- Returns: `ai_score` (0-100), `ai_analysis`, `ai_risk`, `ai_recommendation`
- Cache key: `ai_analysis_{md5(type+entry+method+trend)}`

## Environment Variables

```env
DB_CONNECTION=mysql
DB_DATABASE=price_action
OPENROUTER_API_KEY=sk-or-v1-...   # Rotate if committed to git!
```

## Known Issues (Priority Order)

1. **[CRITICAL]** `config/database.php:4` — `use Pdo\Mysql;` sai namespace, xóa đi
2. **[HIGH]** ADX threshold 15 quá thấp → nhiều tín hiệu sai; nâng lên 20-25
3. **[HIGH]** `updateSignalStatuses()` cache conflict: klines 10-60s cache có thể miss TP/SL crosses
4. **[MEDIUM]** Cache key `binance_klines_...now` collision khi `startTime=null`
5. **[MEDIUM]** Order Block confirmation yếu (không check volume, không check rejection wick)
6. **[MEDIUM]** Elliott pivot window cứng 10 candles, không adapt theo volatility
7. **[LOW]** Input validation thiếu cho `symbol`, `timeframe` params
8. **[LOW]** No Binance rate limit handling (1200 req/min limit)

## Performance Notes

- Binance klines cache: 10s (1m/5m/15m), 60s (1h+)
- Binance price cache: 2s
- AI signal cache: 3600s per unique signal hash
- Signal history queries: `limit(10)` — tăng khi cần historical analysis

## Testing

```bash
php artisan test                          # unit tests
php artisan tinker                        # REPL for manual testing
php artisan route:list                    # verify routes
php artisan config:clear && php artisan cache:clear  # reset state
```

## Coding Conventions

- Service classes cho business logic, Controllers chỉ handle HTTP
- Blade templates không chứa business logic
- Cache keys format: `{service}_{entity}_{params}`
- Signals dùng Vietnamese text cho UI labels (TĂNG/GIẢM/ĐI NGANG, LONG/SHORT)
- AI responses luôn parse JSON, handle parse failure gracefully
