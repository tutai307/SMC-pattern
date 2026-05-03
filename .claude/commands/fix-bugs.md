# Fix Bugs — Price Action Terminal

Fix known bugs in the project. Đọc CLAUDE.md section "Known Issues" trước.

## Instructions

Nhận argument: bug ID hoặc "all" để fix tất cả.

```
/fix-bugs [bug-id|all]
```

**Bug IDs:**
- `db-import` — Sửa `config/database.php:4` xóa `use Pdo\Mysql;`
- `adx-threshold` — Nâng ADX threshold từ 15 → 22 trong `PriceActionService.php`
- `cache-key` — Fix collision trong `BinanceService::getKlines()` khi `startTime=null`
- `input-validation` — Thêm whitelist validation cho `symbol`, `timeframe` trong `DashboardController`
- `signal-status` — Fix race condition trong `updateSignalStatuses()`
- `all` — Fix tất cả theo priority order từ CLAUDE.md

## Steps

1. Đọc file liên quan trước khi sửa
2. Fix chính xác vấn đề được mô tả, không refactor thêm
3. Verify fix không break route list: `php artisan route:list`
4. Chạy tests: `php artisan test`
5. Report: file đã sửa + line number + change summary

## Context

- `app/Services/PriceActionService.php` — ADX ở `generateSMCSignal()`, tìm `$lastAdx < 15`
- `app/Services/BinanceService.php` — cache key collision ở `getKlines()`
- `app/Http/Controllers/DashboardController.php` — validation ở `index()`, status logic ở `updateSignalStatuses()`
- `config/database.php` — sai import line 4
