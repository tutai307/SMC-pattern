<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Bridge Laravel → MT5 EA qua HTTP Webhook.
 *
 * Flow:
 *   Felix (Laravel) → POST JSON payload → MT5 EA endpoint (MQL5 WebRequest)
 *   MT5 EA nhận payload, thực thi lệnh trên Exness, trả về ticket.
 *
 * Cấu hình .env:
 *   EXNESS_WEBHOOK_URL=http://your-vps-ip:8080/felix
 *   EXNESS_WEBHOOK_SECRET=your_hmac_secret
 *   EXNESS_MAGIC=20260513
 */
class ExnessService
{
    // XAUUSD: 1 pip = $0.10 (2 decimal digits)
    private const PIP_SIZE = ['XAUUSD' => 0.10, 'XAGUSD' => 0.001];

    private string $webhookUrl;
    private string $secret;
    private int    $magic;

    public function __construct()
    {
        $this->webhookUrl = config('services.exness.webhook_url', '');
        $this->secret     = config('services.exness.webhook_secret', '');
        $this->magic      = (int) config('services.exness.magic', 20260513);
    }

    public function isConfigured(): bool
    {
        return !empty($this->webhookUrl);
    }

    // ──────────────────────────────────────────────────────────────
    // STOP ORDERS — Đón đầu breakout (Displacement Entry)
    // ──────────────────────────────────────────────────────────────

    /**
     * Đặt lệnh chờ BUY_STOP / SELL_STOP trên MT5.
     *
     * @param string $symbol         Ký hiệu (XAUUSD, XAGUSD)
     * @param string $type           'BUY_STOP' | 'SELL_STOP'
     * @param float  $entry          Giá kích hoạt lệnh
     * @param float  $sl             Stop Loss
     * @param float  $tp             Take Profit (tight: 10-20 pips cho gold)
     * @param float  $lots           Volume (probe lot hoặc main lot)
     * @param int    $expireMinutes  Hủy tự động sau N phút nếu chưa khớp
     * @param string $comment        Nhãn nhận dạng trong MT5
     */
    public function placeStopOrder(
        string $symbol,
        string $type,
        float  $entry,
        float  $sl,
        float  $tp,
        float  $lots,
        int    $expireMinutes = 480,
        string $comment = 'Felix_v4'
    ): array {
        return $this->send([
            'action'   => 'place_stop_order',
            'symbol'   => $this->normalizeSymbol($symbol),
            'type'     => strtoupper($type), // BUY_STOP | SELL_STOP
            'price'    => $this->roundPrice($symbol, $entry),
            'sl'       => $this->roundPrice($symbol, $sl),
            'tp'       => $this->roundPrice($symbol, $tp),
            'lots'     => round($lots, 2),
            'magic'    => $this->magic,
            'expiry'   => now()->addMinutes($expireMinutes)->utc()->format('Y-m-d H:i:s'),
            'comment'  => $comment,
        ]);
    }

    /**
     * Đặt lệnh Market ngay lập tức (dùng khi không có kênh nén — vào OB trực tiếp).
     */
    public function placeMarketOrder(
        string $symbol,
        string $type,   // 'BUY' | 'SELL'
        float  $sl,
        float  $tp,
        float  $lots,
        string $comment = 'Felix_v4'
    ): array {
        return $this->send([
            'action'  => 'place_market_order',
            'symbol'  => $this->normalizeSymbol($symbol),
            'type'    => strtoupper($type),
            'sl'      => $this->roundPrice($symbol, $sl),
            'tp'      => $this->roundPrice($symbol, $tp),
            'lots'    => round($lots, 2),
            'magic'   => $this->magic,
            'comment' => $comment,
        ]);
    }

    // ──────────────────────────────────────────────────────────────
    // ORDER MANAGEMENT
    // ──────────────────────────────────────────────────────────────

    /** Hủy lệnh chờ theo ticket MT5 */
    public function cancelOrder(int $ticket): array
    {
        return $this->send(['action' => 'cancel_order', 'ticket' => $ticket]);
    }

    /** Hủy toàn bộ lệnh chờ của Felix (dùng khi đạt mục tiêu ngày) */
    public function cancelAllPending(): array
    {
        return $this->send(['action' => 'cancel_all_pending', 'magic' => $this->magic]);
    }

    /**
     * Dời SL / TP — dùng cho breakeven và partial TP.
     * Truyền 0 cho sl hoặc tp để giữ nguyên giá trị cũ.
     */
    public function modifyOrder(int $ticket, float $sl = 0, float $tp = 0): array
    {
        return $this->send([
            'action' => 'modify_order',
            'ticket' => $ticket,
            'sl'     => $sl,
            'tp'     => $tp,
        ]);
    }

    // ──────────────────────────────────────────────────────────────
    // BREAKEVEN — Kéo SL về hòa vốn khi giá chạy đủ N pips
    // ──────────────────────────────────────────────────────────────

    /**
     * Tính và gửi lệnh kéo SL về breakeven.
     *
     * Quy tắc ông Quyết: "kéo SL về hòa vốn ngay khi giá chạy được 0.5 giá vàng (5 pips)"
     *
     * @param int    $ticket          Ticket MT5
     * @param float  $entry           Giá vào lệnh
     * @param float  $currentPrice    Giá hiện tại
     * @param bool   $isLong          LONG = true, SHORT = false
     * @param string $symbol          Để tính pip size
     * @param float  $triggerPips     Số pip thuận chiều để kích hoạt BE (default: 5)
     */
    public function moveToBreakeven(
        int    $ticket,
        float  $entry,
        float  $currentPrice,
        bool   $isLong,
        string $symbol = 'XAUUSD',
        float  $triggerPips = 5.0
    ): array {
        $pipSize   = self::PIP_SIZE[$this->normalizeSymbol($symbol)] ?? 0.10;
        $pipsMoved = $isLong
            ? ($currentPrice - $entry) / $pipSize
            : ($entry - $currentPrice) / $pipSize;

        if ($pipsMoved < $triggerPips) {
            return ['skipped' => true, 'pips_moved' => round($pipsMoved, 1), 'needed' => $triggerPips];
        }

        // BE = entry + 1 pip buffer (không BE tại đúng entry để tránh slippage kick)
        $beSl = $isLong
            ? round($entry + $pipSize, 2)
            : round($entry - $pipSize, 2);

        return $this->modifyOrder($ticket, $beSl, 0);
    }

    // ──────────────────────────────────────────────────────────────
    // LOT SIZING — Tính volume theo % rủi ro
    // ──────────────────────────────────────────────────────────────

    /**
     * Tính probe lot (lô thăm dò) dựa trên % vốn rủi ro.
     *
     * @param float  $capital      Vốn tài khoản (USD)
     * @param float  $riskPct      % rủi ro trên vốn (e.g. 0.5 = 0.5%)
     * @param float  $slPips       Khoảng cách SL tính bằng pip
     * @param float  $pipValueUsd  Giá trị 1 pip của 1 lot chuẩn (XAUUSD = $10)
     * @return float Số lot (làm tròn 0.01)
     */
    public function calcProbeLot(
        float $capital,
        float $riskPct,
        float $slPips,
        float $pipValueUsd = 10.0
    ): float {
        if ($slPips <= 0 || $pipValueUsd <= 0) return 0.01;
        $riskAmount = $capital * ($riskPct / 100);
        $lot = $riskAmount / ($slPips * $pipValueUsd);
        return max(0.01, round($lot, 2));
    }

    /**
     * Tính main lot = probe * multiplier (kích hoạt khi Stop khớp / Breakout xác nhận).
     * Mặc định nhân 7x — "vụt to ăn ngắn" theo phương pháp ông Quyết.
     */
    public function calcMainLot(float $probeLot, int $multiplier = 7): float
    {
        return round($probeLot * $multiplier, 2);
    }

    // ──────────────────────────────────────────────────────────────
    // HELPERS
    // ──────────────────────────────────────────────────────────────

    private function normalizeSymbol(string $symbol): string
    {
        return match (strtoupper($symbol)) {
            'XAUUSDT', 'XAUUSD' => 'XAUUSD',
            'XAGUSDT', 'XAGUSD' => 'XAGUSD',
            default              => strtoupper($symbol),
        };
    }

    private function roundPrice(string $symbol, float $price): float
    {
        // XAUUSD: 2 decimal places | XAGUSD: 3 decimal places
        $decimals = str_contains(strtoupper($symbol), 'XAU') ? 2 : 3;
        return round($price, $decimals);
    }

    private function send(array $payload): array
    {
        if (!$this->isConfigured()) {
            Log::warning('ExnessService: EXNESS_WEBHOOK_URL chưa cấu hình');
            return ['success' => false, 'error' => 'not_configured'];
        }

        // HMAC signature để MT5 EA verify nguồn gốc
        $payload['ts']        = now()->timestamp;
        $payload['signature'] = hash_hmac(
            'sha256',
            json_encode($payload, JSON_UNESCAPED_UNICODE),
            $this->secret
        );

        try {
            $response = Http::timeout(6)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post($this->webhookUrl, $payload);

            if ($response->successful()) {
                $data = $response->json() ?? [];
                Log::info('ExnessService OK', ['action' => $payload['action'], 'resp' => $data]);
                return ['success' => true, 'data' => $data];
            }

            Log::error('ExnessService HTTP error', [
                'status'  => $response->status(),
                'body'    => $response->body(),
                'action'  => $payload['action'],
            ]);
            return ['success' => false, 'status' => $response->status(), 'error' => $response->body()];

        } catch (\Exception $e) {
            Log::error('ExnessService exception: ' . $e->getMessage(), ['action' => $payload['action'] ?? '?']);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
