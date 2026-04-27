<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BinanceService
{
    protected $baseUrl = 'https://fapi.binance.com/fapi/v1';

    /**
     * Get K-line (Candlestick) data from Binance Futures.
     *
     * @param string $symbol
     * @param string $interval
     * @param int $limit
     * @return array
     */
    public function getKlines(string $symbol = 'BTCUSDT', string $interval = '1h', int $limit = 100, $startTime = null)
    {
        $cacheKey = "binance_klines_{$symbol}_{$interval}_{$limit}_" . ($startTime ?? 'now');
        $cacheDuration = in_array($interval, ['1m', '5m', '15m']) ? 10 : 60;

        return \Illuminate\Support\Facades\Cache::remember($cacheKey, $cacheDuration, function () use ($symbol, $interval, $limit, $startTime) {
            try {
                $params = [
                    'symbol' => strtoupper($symbol),
                    'interval' => $interval,
                    'limit' => $limit,
                ];

                if ($startTime) {
                    $params['startTime'] = $startTime;
                }

                $response = Http::get("{$this->baseUrl}/klines", $params);

                if ($response->successful()) {
                    return $response->json();
                }

                Log::error("Binance API Error: " . $response->body());
                return [];
            } catch (\Exception $e) {
                Log::error("Binance Service Exception: " . $e->getMessage());
                return [];
            }
        });
    }

    /**
     * Get Current Price for a symbol.
     *
     * @param string $symbol
     * @return float|null
     */
    public function getPrice(string $symbol = 'BTCUSDT')
    {
        return \Illuminate\Support\Facades\Cache::remember("price_{$symbol}", 2, function () use ($symbol) {
            try {
                $response = Http::get("{$this->baseUrl}/ticker/price", [
                    'symbol' => strtoupper($symbol),
                ]);

                if ($response->successful()) {
                    return (float) $response->json()['price'];
                }

                return null;
            } catch (\Exception $e) {
                return null;
            }
        });
    }
}
