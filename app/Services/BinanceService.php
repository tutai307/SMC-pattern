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
        $cacheKey      = "binance_klines_{$symbol}_{$interval}_{$limit}_" . ($startTime ?? 'now');
        $cacheDuration = in_array($interval, ['1m', '5m', '15m']) ? 10 : 60;

        $cached = \Illuminate\Support\Facades\Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $params = [
            'symbol'   => strtoupper($symbol),
            'interval' => $interval,
            'limit'    => $limit,
        ];
        if ($startTime) {
            $params['startTime'] = $startTime;
        }

        $lastError = '';
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                $response = Http::timeout(6)->get("{$this->baseUrl}/klines", $params);

                if ($response->successful()) {
                    $data = $response->json();
                    \Illuminate\Support\Facades\Cache::put($cacheKey, $data, $cacheDuration);
                    return $data;
                }

                $lastError = "HTTP {$response->status()}: " . substr($response->body(), 0, 200);
            } catch (\Exception $e) {
                $lastError = $e->getMessage();
            }

            if ($attempt < 3) usleep(300000);
        }

        Log::warning("Binance getKlines failed after 3 attempts [{$symbol} {$interval}]: {$lastError}");
        return [];
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

    /**
     * Fetch historical klines từ data.binance.vision (monthly zip files).
     * Cache từng tháng vào storage/app/binance_vision/ để tránh download lại.
     */
    public function getVisionKlines(string $symbol, string $interval, string $startDate, string $endDate): array
    {
        $start    = \Carbon\Carbon::parse($startDate)->startOfMonth();
        $end      = \Carbon\Carbon::parse($endDate);
        $all      = [];
        $current  = $start->copy();

        while ($current->lte($end)) {
            $month   = $current->format('Y-m');
            $batch   = $this->fetchVisionMonth($symbol, $interval, $month);
            $all     = array_merge($all, $batch);
            $current->addMonth();
        }

        // Trim to exact requested range
        $startMs = \Carbon\Carbon::parse($startDate)->startOfDay()->timestamp * 1000;
        $endMs   = \Carbon\Carbon::parse($endDate)->endOfDay()->timestamp * 1000;
        return array_values(array_filter($all, fn($k) => (int)$k[0] >= $startMs && (int)$k[0] <= $endMs));
    }

    private function fetchVisionMonth(string $symbol, string $interval, string $yearMonth): array
    {
        $dir  = storage_path("app/binance_vision/{$symbol}/{$interval}");
        $file = "{$dir}/{$yearMonth}.json";

        if (file_exists($file)) {
            return json_decode(file_get_contents($file), true) ?: [];
        }

        $url = "https://data.binance.vision/data/futures/um/monthly/klines/{$symbol}/{$interval}/{$symbol}-{$interval}-{$yearMonth}.zip";

        try {
            $response = Http::timeout(60)->get($url);
            if (!$response->successful()) {
                Log::warning("BinanceVision 404: {$url}");
                return [];
            }

            $tmpZip = sys_get_temp_dir() . "/bv_{$symbol}_{$interval}_{$yearMonth}.zip";
            file_put_contents($tmpZip, $response->body());

            $zip = new \ZipArchive();
            if ($zip->open($tmpZip) !== true) { unlink($tmpZip); return []; }
            $csv = $zip->getFromIndex(0);
            $zip->close();
            unlink($tmpZip);

            $klines = [];
            foreach (explode("\n", trim($csv)) as $line) {
                if (empty($line)) continue;
                $cols = str_getcsv($line);
                // Skip header row if present
                if (!is_numeric($cols[0] ?? '')) continue;
                if (count($cols) < 6) continue;
                $klines[] = $cols;
            }

            if (!is_dir($dir)) mkdir($dir, 0755, true);
            file_put_contents($file, json_encode($klines));

            return $klines;
        } catch (\Exception $e) {
            Log::warning("BinanceVision error {$symbol}/{$interval}/{$yearMonth}: " . $e->getMessage());
            return [];
        }
    }

    public function getFundingRate(string $symbol): float
    {
        return (float) \Illuminate\Support\Facades\Cache::remember("funding_{$symbol}", 30, function () use ($symbol) {
            try {
                $response = Http::timeout(8)->get("{$this->baseUrl}/premiumIndex", [
                    'symbol' => strtoupper($symbol),
                ]);
                if ($response->successful()) {
                    return (float) ($response->json()['lastFundingRate'] ?? 0);
                }
            } catch (\Exception $e) {
                Log::warning("getFundingRate {$symbol}: " . $e->getMessage());
            }
            return 0.0;
        });
    }

    public function getTopLSRatio(string $symbol, string $period = '1h'): float
    {
        // Returns longAccount ratio (0-1). > 0.5 means more longs, < 0.5 means more shorts.
        return (float) \Illuminate\Support\Facades\Cache::remember("ls_ratio_{$symbol}_{$period}", 60, function () use ($symbol, $period) {
            try {
                $response = Http::timeout(8)->get("{$this->baseUrl}/topLongShortPositionRatio", [
                    'symbol' => strtoupper($symbol),
                    'period' => $period,
                    'limit'  => 1,
                ]);
                if ($response->successful()) {
                    $data = $response->json();
                    return (float) ($data[0]['longAccount'] ?? 0.5);
                }
            } catch (\Exception $e) {
                Log::warning("getTopLSRatio {$symbol}: " . $e->getMessage());
            }
            return 0.5;
        });
    }
}
