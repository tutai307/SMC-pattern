# Test — Kiểm tra hệ thống Price Action Terminal

Chạy kiểm tra toàn diện hoặc theo area cụ thể.

## Usage
```
/test [area]
```

**Areas:**
- `syntax` — PHP syntax check tất cả files quan trọng
- `unit` — Chạy PHPUnit test suite
- `signal` — Test signal generation logic (manual via tinker)
- `telegram` — Test Telegram command mapping
- `cache` — Verify cache behavior (priceBucket, TTL, pnlSign)
- `all` — Toàn bộ (default)

## Steps

### 1. Đọc context
Đọc `.claude/SYSTEM_STATE.md` để biết recent changes cần test đặc biệt.

### 2. Syntax check (luôn chạy đầu tiên)
```bash
php -l app/Services/PriceActionService.php
php -l app/Console/Commands/TelegramBotCommand.php
php -l app/Console/Commands/ScanSignalsCommand.php
php -l app/Console/Commands/MonitorSignalsCommand.php
php -l app/Services/BinanceService.php
php -l app/Services/TelegramService.php
php -l app/Http/Controllers/DashboardController.php
```

### 3. Unit tests
```bash
php artisan test
```

### 4. Route validation
```bash
php artisan route:list
```

### 5. Key logic checks (tinker)
Verify trong tinker:
- R:R = 1:2: `entry + (entry - sl) * 2.0`
- priceBucket không = 200 cố định
- Gate logic: AI score < 60 blocked, R:R < 1.5 blocked

### 6. Report
Dùng format:
```
✅ PASS: [test] — [result]
❌ FAIL: [test] — [reason] — [file:line]
⚠️ WARN: [test] — [note]
```

### 7. Cập nhật SYSTEM_STATE.md
Section `## Last Test Run` với timestamp và kết quả tóm tắt.
