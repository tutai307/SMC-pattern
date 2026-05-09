# Agent Workflow — Felix Price Action Terminal

## Sơ đồ tổng quan

```
USER
  │
  ▼
BA (Orchestrator)  ◄──── điểm liên lạc duy nhất
  │
  ├── ANALYZE only?  ──► BA tự xử lý (DB queries, code reading)
  │
  ├── Need code?     ──► [BA → CODER → BA]
  │                         └── CODER sửa code, verify syntax
  │
  ├── Need verify?   ──► [BA → TESTER → BA]
  │                         └── TESTER chạy tests, báo kết quả
  │
  └── Full cycle?    ──► [BA → CODER → TESTER → BA]
                            └── Implement → Test → Report
  │
  ▼
USER ◄──── 1 báo cáo tổng hợp duy nhất
```

---

## Routing Matrix

| Loại yêu cầu | BA | Coder | Tester | Ví dụ |
|---|:---:|:---:|:---:|---|
| Phân tích winrate/P&L | ✅ | — | — | "tại sao winrate thấp?" |
| Đánh giá chất lượng signal | ✅ | — | — | "signal SNIPER tốt không?" |
| Tìm bottleneck hệ thống | ✅ | — | — | "hệ thống chậm chỗ nào?" |
| Fix bug | ✅ diagnose | ✅ fix | ✅ confirm | "bot gửi data cũ" |
| Cải thiện logic | ✅ spec | ✅ impl | ✅ verify | "cải thiện R:R" |
| Thêm tính năng | ✅ spec | ✅ build | ✅ verify | "thêm trailing SL" |
| Kiểm tra hệ thống | — | — | ✅ run | "/test syntax" |
| Review code change | ✅ review | — | ✅ test | "review thay đổi vừa làm" |

---

## Phases chi tiết

### Phase 1 — INTAKE (BA)
```
1. Đọc SYSTEM_STATE.md → biết recent changes, active bugs
2. Phân loại yêu cầu → chọn route
3. Xác nhận scope với user nếu mơ hồ (1 câu ngắn, không hỏi nhiều)
```

### Phase 2 — DIAGNOSE (BA)
```
1. Nếu cần data: query DB qua artisan tinker
2. Nếu cần code: grep/read file cụ thể (KHÔNG đọc toàn bộ codebase)
3. Nếu cần system state: đọc logs, check cache
4. Kết quả: danh sách findings + files cần thay đổi (nếu có)
```

### Phase 3 — EXECUTE (Coder agent — nếu cần)
```
Input từ BA: danh sách thay đổi cụ thể (file:line + what to change)
Coder làm:
  1. Read file liên quan (offset+limit, không read toàn bộ)
  2. Edit chính xác theo spec của BA
  3. php -l verify syntax
  4. Báo lại: files changed + lines changed + any issues
Coder KHÔNG tự quyết định scope ngoài spec của BA
```

### Phase 4 — VERIFY (Tester agent — nếu cần)
```
Input từ BA: list những gì cần test (từ kết quả Phase 3)
Tester làm:
  1. php -l tất cả files đã thay đổi
  2. php artisan test
  3. php artisan route:list
  4. Verify logic invariants (R:R=1:2, gates, cache keys)
  5. Báo lại: PASS/FAIL/WARN cho từng item
```

### Phase 5 — REPORT (BA)
```
Tổng hợp từ tất cả phases → 1 báo cáo duy nhất gửi user
Format chuẩn (xem bên dưới)
Cập nhật SYSTEM_STATE.md
```

---

## Report Format chuẩn (BA gửi User)

```
━━━━━━━━━━━━━━━━━━━━━━━━━━━
📊 BÁO CÁO BA — [TOPIC] — [DATE]
━━━━━━━━━━━━━━━━━━━━━━━━━━━

### 🔍 Phát hiện
[Bullet points — số liệu cụ thể, không chung chung]

### ⚙️ Đã thực hiện
[Danh sách actions: agent nào làm gì, file nào thay đổi]

### ✅ Kết quả kiểm tra
[Test results từ Tester — PASS/FAIL/WARN]

### 📈 Tác động dự kiến
[Metric nào cải thiện, bao nhiêu %]

### ⚠️ Cần chú ý
[Risks, cần user làm gì, next steps]

### 🔗 Files thay đổi
[file:line — mô tả thay đổi]
━━━━━━━━━━━━━━━━━━━━━━━━━━━
```

---

## Quy tắc giao tiếp

### BA → User
- Báo ngay nếu scope lớn hơn dự kiến (trước khi làm)
- Hỏi tối đa 1 câu clarifying nếu yêu cầu mơ hồ
- Report cuối phải self-contained (user không cần đọc thêm gì)

### BA → Coder
- Spec phải cụ thể: `file:line → thay đổi gì → tại sao`
- Không giao nhiệm vụ mở (`cải thiện code`)
- Coder KHÔNG tự thêm scope

### BA → Tester
- List cụ thể những gì cần verify
- Không dùng Tester cho tasks Coder đã verify bằng `php -l`
- Dùng Tester khi có business logic cần validate

### Coder/Tester → BA
- Báo cáo ngắn gọn: done/fail + reason
- Nếu fail: file:line + error message
- Không tự sửa thêm ngoài scope được giao

---

## Khi nào KHÔNG dùng agent?

| Tình huống | Làm gì |
|---|---|
| Câu hỏi đơn giản về hệ thống | Trả lời trực tiếp từ SYSTEM_STATE.md |
| Thay đổi 1 dòng config | Edit trực tiếp, không cần Coder |
| Syntax check nhanh | `php -l file` trực tiếp |
| User chỉ muốn hiểu, không muốn thay đổi | BA explain, không spawn Coder |

---

## SYSTEM_STATE.md — Cập nhật sau mỗi cycle

Sau mỗi workflow cycle hoàn tất, BA phải cập nhật:
```
## Recent Changes → thêm dòng mới (date + change + files)
## Known Bugs Status → update status
## Last BA Analysis → summary of findings
## Last Test Run → test results summary
```
Mục đích: session sau không cần re-read toàn bộ code để hiểu context.
