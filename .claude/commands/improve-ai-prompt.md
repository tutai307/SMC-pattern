# Improve AI Prompt — Tối ưu prompt gửi OpenRouter

Cải thiện chất lượng AI signal validation để tăng độ chính xác scoring.

## Usage

```
/improve-ai-prompt [focus]
```

Focus options: `scoring`, `risk`, `context`, `all`

## Current Prompt Location

`app/Services/PriceActionService.php` → method `enrichWithAIScore()`

## Problems với Current Prompt

1. **Thiếu context thị trường rộng**: Không mention BTC dominance, market cap, funding rate
2. **Không có historical win context**: AI không biết signal này thuộc loại đã từng thắng hay thua
3. **Output format không strict**: JSON parse có thể fail nếu AI trả lời văn xuôi
4. **Không có chain-of-thought**: AI không được yêu cầu reason trước khi score
5. **Token waste**: Gửi 60 candles full OHLCV nhưng AI chủ yếu cần price levels và patterns

## Improved Prompt Structure

```
System: Expert crypto futures trader. Output ONLY valid JSON. No prose.

Analyze this futures signal and return risk-adjusted score.

MARKET CONTEXT:
- Pair: {symbol}, Timeframe: {timeframe}
- Current price: {price}
- Trend (LTF): {trend_ltf}, Trend (HTF): {trend_htf}
- ADX: {adx} (trend strength), EMA200: {ema200} ({above/below})
- ATR: {atr} (volatility)

SIGNAL:
- Direction: {type}
- Entry: {entry} | TP: {tp} (+{tp_pct}%) | SL: {sl} (-{sl_pct}%)
- R:R Ratio: {rr_ratio}
- Method: {method}
- Rationale: {reason}

RECENT PRICE ACTION (last 20 closes only):
{closes_array}

KEY LEVELS:
- Order Block: {ob_level}
- FVG: {fvg_range}
- Volume POC: {poc}

Respond ONLY with this exact JSON:
{"score":0-100,"confidence":"LOW|MEDIUM|HIGH","analysis":"<2 sentences>","risk":"<1 sentence>","recommendation":"ENTER|WAIT|SKIP","entry_note":"<timing note>"}
```

## Implementation

1. Đọc `enrichWithAIScore()` đầy đủ
2. Replace prompt với improved version trên
3. Thêm JSON validation với fallback nếu parse fail:
   ```php
   $data = json_decode($content, true);
   if (!$data || !isset($data['score'])) {
       // extract bằng regex fallback
       preg_match('/"score"\s*:\s*(\d+)/', $content, $m);
       $data['score'] = (int)($m[1] ?? 50);
   }
   ```
4. Thêm `confidence` field vào return array
5. Update `welcome.blade.php` để hiển thị confidence level

## Token Saving

Current: ~60 candles × 7 fields = ~420 numbers  
Optimized: 20 closes only = ~20 numbers  
**Saving: ~70% tokens per AI call**

## Cache Strategy

Giữ nguyên 1h cache, nhưng thêm `confidence` vào cache key nếu cần differentiation.
