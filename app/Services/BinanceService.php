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

    public function getCoinQuality(string $symbol): array
    {
        return \Illuminate\Support\Facades\Cache::remember("coin_quality_{$symbol}", 300, function () use ($symbol) {
            try {
                $sym = strtoupper($symbol);

                $ticker  = Http::get("{$this->baseUrl}/ticker/24hr", ['symbol' => $sym])->json();
                $oi      = Http::get("{$this->baseUrl}/openInterest", ['symbol' => $sym])->json();
                $funding = Http::get("{$this->baseUrl}/fundingRate", ['symbol' => $sym, 'limit' => 5])->json();

                $volume24h  = (float) ($ticker['quoteVolume'] ?? 0);
                $lastPrice  = (float) ($ticker['lastPrice'] ?? 0);
                $oiUnits    = (float) ($oi['openInterest'] ?? 0);
                $oiUsdt     = $oiUnits * $lastPrice;

                $rates = is_array($funding) ? array_column($funding, 'fundingRate') : [];
                $avgFunding = count($rates) > 0
                    ? array_sum(array_map('floatval', $rates)) / count($rates)
                    : 0;

                $score = 0;
                $flags = [];

                // Volume 24h check
                if ($volume24h >= 50_000_000)      $score += 35;
                elseif ($volume24h >= 10_000_000)  $score += 25;
                elseif ($volume24h >= 1_000_000)   $score += 12;
                else { $flags[] = 'Volume 24h quá thấp — dễ bị slippage'; }

                // Open Interest check
                if ($oiUsdt >= 10_000_000)         $score += 35;
                elseif ($oiUsdt >= 3_000_000)      $score += 22;
                elseif ($oiUsdt >= 500_000)        $score += 10;
                else { $flags[] = 'OI thấp — dễ bị thao túng giá'; }

                // Funding rate check (extreme = manipulation signal)
                $absRate = abs($avgFunding);
                if ($absRate <= 0.0003)      $score += 30;
                elseif ($absRate <= 0.001)   $score += 15;
                else { $score -= 10; $flags[] = 'Funding rate bất thường — có thể đang bị thao túng'; }

                $score  = max(0, min(100, $score));
                $status = $score >= 70 ? 'SAFE' : ($score >= 40 ? 'CAUTION' : 'AVOID');

                return [
                    'score'      => $score,
                    'status'     => $status,
                    'volume24h'  => $volume24h,
                    'oi_usdt'    => $oiUsdt,
                    'funding'    => $avgFunding,
                    'flags'      => $flags,
                ];
            } catch (\Exception $e) {
                Log::error("CoinQuality error: " . $e->getMessage());
                return [
                    'score'     => 50,
                    'status'    => 'CAUTION',
                    'volume24h' => 0,
                    'oi_usdt'   => 0,
                    'funding'   => 0,
                    'flags'     => ['Không lấy được dữ liệu coin'],
                ];
            }
        });
    }
}
