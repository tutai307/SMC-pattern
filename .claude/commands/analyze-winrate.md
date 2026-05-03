# Analyze Winrate — Phân tích hiệu suất tín hiệu

Đọc lịch sử `trading_signals` và tìm pattern để cải thiện win rate thực tế.

## Usage

```
/analyze-winrate [symbol|all] [days]
```

Ví dụ: `/analyze-winrate BTCUSDT 30`, `/analyze-winrate all 7`

## What to Analyze

### 1. Win Rate by Dimension
Query `trading_signals` WHERE `status IN ('WIN','LOSS')`:
- Winrate theo `symbol`
- Winrate theo `timeframe` (M15 vs H1 vs H4)
- Winrate theo `type` (LONG vs SHORT)
- Winrate theo `method` (SMC vs Elliott — nếu lưu trong reason)
- Winrate theo giờ trong ngày (từ `created_at`)
- Average `winrate` score vs actual outcome correlation

### 2. Risk/Reward Analysis
- Average R:R ratio (|tp - entry| / |entry - sl|) cho WIN vs LOSS
- Distribution của signal winrate scores (0-100) cho WIN vs LOSS
- AI score correlation với actual outcome

### 3. Pattern Detection
- Symbols nào có win rate > 60%? Tập trung vào đó
- Timeframe nào tốt nhất cho từng symbol?
- Tín hiệu có ADX cao hơn có win rate tốt hơn không?

## Implementation

```php
// Chạy via artisan tinker hoặc tạo temporary route
$signals = TradingSignal::whereIn('status', ['WIN', 'LOSS'])
    ->when($symbol !== 'all', fn($q) => $q->where('symbol', $symbol))
    ->where('created_at', '>=', now()->subDays($days))
    ->get();

// Group và tính winrate thực
$bySymbol = $signals->groupBy('symbol')->map(fn($g) => [
    'total' => $g->count(),
    'wins' => $g->where('status', 'WIN')->count(),
    'winrate' => round($g->where('status', 'WIN')->count() / $g->count() * 100, 1),
    'avg_predicted_winrate' => round($g->avg('winrate'), 1),
]);
```

## Output Format

Trả về bảng markdown:
```
| Symbol   | Total | Wins | Real WR | Predicted WR | AI Score |
|----------|-------|------|---------|--------------|----------|
| BTCUSDT  | 20    | 14   | 70%     | 65%          | 72       |
```

Kèm theo:
- Top 3 symbols tốt nhất → focus vào đây
- Top 3 symbols kém nhất → tránh hoặc tăng filter
- Recommendations cụ thể để cải thiện (ngắn gọn, actionable)

## Next Steps

Sau khi phân tích, đề xuất:
1. Symbol/timeframe combination tốt nhất → set làm default trong UI
2. Minimum AI score threshold để propose signal (hiện tại không có)
3. Thời điểm trading tốt nhất trong ngày
