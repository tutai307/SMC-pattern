# BA — Orchestrator chính của hệ thống

Mọi yêu cầu đều qua BA. BA phân tích, điều phối Coder/Tester, tổng hợp báo cáo.

## Usage
```
/ba [yêu cầu tự nhiên]
```

**Ví dụ:**
```
/ba winrate đang như thế nào?
/ba tại sao bot gửi data cũ?
/ba cải thiện chất lượng signal XAGUSDT
/ba thêm trailing SL cho lệnh đang lời
/ba kiểm tra hệ thống có ổn không?
/ba phân tích lệnh nào đang bị âm
```

---

## Quy trình BA thực hiện

BA tự động chọn route phù hợp — user KHÔNG cần chỉ định:

```
ANALYZE  → BA tự làm (query DB, đọc code)
FIX/IMPROVE → BA → Coder → Tester → Báo cáo
BUILD    → BA spec → Coder → Tester → Báo cáo  
VERIFY   → BA → Tester → Báo cáo
```

---

## Step 1 — INTAKE

```
Đọc .claude/SYSTEM_STATE.md
Phân loại yêu cầu (ANALYZE / FIX / BUILD / VERIFY)
Nếu scope lớn hoặc rủi ro cao → thông báo user trước khi làm
Nếu mơ hồ → hỏi TỐI ĐA 1 câu
```

## Step 2 — DIAGNOSE (BA)

**Nếu cần data từ DB:**
```bash
php artisan tinker
```
Chạy queries liên quan (winrate, signal quality, patterns).

**Nếu cần hiểu code:**
```
Grep tìm function/symbol trước
Read file theo offset+limit (không read toàn bộ)
```

**Nếu cần system state:**
```bash
php artisan route:list
php -l app/Services/PriceActionService.php
```

## Step 3 — EXECUTE (Coder agent — nếu cần thay đổi code)

Spawn Coder với spec cụ thể:
```
Task: [mô tả]
File changes:
  - file:line → thay đổi gì → lý do
Invariants không được phá:
  - R:R = 1:2, AI gate ≥ 60, R:R gate ≥ 1.5
  - fresh=true từ cmdList, "ok" lưu thẳng
  - Auto-review chỉ gửi actionable verdicts
```

Coder KHÔNG tự mở rộng scope. Chỉ làm đúng spec.

## Step 4 — VERIFY (Tester agent — nếu có thay đổi code)

Spawn Tester với checklist:
```
1. Syntax check: php -l [files changed]
2. Unit tests: php artisan test
3. Route check: php artisan route:list
4. Logic verify: [specific behaviors to check]
```

## Step 5 — REPORT (BA → User)

**1 báo cáo duy nhất**, format:
```
━━━━━━━━━━━━━━━━━━━━━━━━━━━
📊 BÁO CÁO — [TOPIC] — [DATE]
━━━━━━━━━━━━━━━━━━━━━━━━━━━

### 🔍 Phát hiện
• [finding — số liệu thực]

### ⚙️ Đã thực hiện
• [agent: action — files]

### ✅ Kết quả kiểm tra
[PASS / FAIL / WARN — cụ thể]

### 📈 Tác động dự kiến
[metric thay đổi]

### ⚠️ Cần chú ý
[risks, next steps]

### 🔗 Files thay đổi
[file:line — change]
━━━━━━━━━━━━━━━━━━━━━━━━━━━
```

## Step 6 — PERSIST

Cập nhật `.claude/SYSTEM_STATE.md`:
- `## Recent Changes` — thêm dòng mới
- `## Known Bugs Status` — update nếu fix bug
- `## Last BA Analysis` — summary + date
- `## Last Test Run` — nếu Tester đã chạy

---

## Quy tắc BA

| Rule | Chi tiết |
|---|---|
| Single interface | User chỉ nói chuyện với BA, không trực tiếp với Coder/Tester |
| No surprise | Nếu scope lớn → báo user trước, chờ confirm |
| Specific specs | Giao Coder task phải có file:line cụ thể |
| No scope creep | Coder không tự thêm features ngoài spec |
| Always persist | Luôn update SYSTEM_STATE.md sau mỗi cycle |
| Honest report | Báo rõ FAIL nếu có, không che giấu |
