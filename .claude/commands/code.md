# Code — Implement feature / fix bug

Dùng khi cần implement tính năng mới hoặc fix bug phức tạp cần nhiều context.

## Usage
```
/code <mô tả task>
```

## Steps

### 1. Đọc SYSTEM_STATE.md
Bắt buộc — đọc để biết:
- Recent changes (tránh conflict)
- Known bugs status (tránh re-introduce)
- Current signal logic (tránh break invariants)
- Key file map (biết đọc file nào)

### 2. Tìm hiểu phạm vi
- Grep tìm symbol/function liên quan
- Read chỉ phần file cần thiết (dùng offset+limit)
- Không đọc file không liên quan

### 3. Implement
Theo rules:
- Không đổi public method signatures
- Không thêm comments không cần thiết  
- Verify syntax sau mỗi file: `php -l <file>`
- Không tạo file mới trừ khi cần thiết

### 4. Verify
```bash
php -l <files-changed>
php artisan test
php artisan route:list
```

### 5. Cập nhật SYSTEM_STATE.md
Bắt buộc — thêm vào:
- `## Recent Changes`: date, change description, files
- `## Known Bugs Status`: update nếu fix bug
- `## Key File Map`: update line numbers nếu shift nhiều

### Invariants KHÔNG được phá vỡ
- R:R = 1:2 trong generateSMCSignal
- AI score gate ≥ 60 trong TelegramBotCommand::runAnalysis
- R:R gate ≥ 1.5 trong TelegramBotCommand::runAnalysis
- fresh=true khi gọi từ cmdList
- Auto-review chỉ gửi actionable verdicts
- "ok" → confirmPendingSignal không qua pre-flight
