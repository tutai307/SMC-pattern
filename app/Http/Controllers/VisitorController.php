<?php

namespace App\Http\Controllers;

use App\Models\AccessRequest;
use App\Models\VisitorLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class VisitorController extends Controller
{
    public function index()
    {
        $requests = AccessRequest::orderBy('created_at', 'desc')->get();

        $recentVisitors = VisitorLog::selectRaw('ip, MAX(created_at) as last_seen, COUNT(*) as visits, MAX(user_agent) as user_agent, MAX(location) as location')
            ->groupBy('ip')
            ->orderByDesc('last_seen')
            ->limit(50)
            ->get()
            ->map(function ($v) {
                $v->location = is_string($v->location) ? json_decode($v->location, true) : $v->location;
                return $v;
            });

        return view('visitors', compact('requests', 'recentVisitors'));
    }

    public function submitRequest(Request $request)
    {
        $request->validate([
            'name'    => 'required|string|max:100',
            'message' => 'nullable|string|max:500',
        ]);

        $ip = $request->ip();

        // Check nếu IP đã có request pending hoặc đang được duyệt
        $existing = AccessRequest::where('ip', $ip)
            ->whereIn('status', ['pending', 'approved'])
            ->latest()
            ->first();

        if ($existing && $existing->status === 'approved' && $existing->isActive()) {
            return back()->with('request_sent', 'IP của bạn đã được duyệt. Thử đăng nhập bằng OTP hoặc liên hệ admin.');
        }

        if ($existing && $existing->status === 'pending') {
            return back()->with('request_sent', 'Yêu cầu của bạn đang chờ duyệt. Vui lòng chờ admin xác nhận.');
        }

        $location = $this->geolocate($ip);

        AccessRequest::create([
            'name'       => $request->input('name'),
            'message'    => $request->input('message'),
            'ip'         => $ip,
            'user_agent' => $request->userAgent(),
            'location'   => $location,
            'status'     => 'pending',
        ]);

        return back()->with('request_sent', 'Yêu cầu đã được gửi! Admin sẽ duyệt trong thời gian sớm nhất.');
    }

    public function approve(int $id)
    {
        $req = AccessRequest::findOrFail($id);
        $req->update([
            'status'     => 'approved',
            'expires_at' => now()->addHours(24),
        ]);

        Cache::forget("access_approved_{$req->ip}");

        return back()->with('success', "Đã duyệt — {$req->name} ({$req->ip}) được vào trong 24h.");
    }

    public function reject(int $id)
    {
        $req = AccessRequest::findOrFail($id);
        $req->update(['status' => 'rejected']);

        Cache::forget("access_approved_{$req->ip}");

        return back()->with('success', "Đã từ chối yêu cầu của {$req->name}.");
    }

    public function revoke(int $id)
    {
        $req = AccessRequest::findOrFail($id);
        $req->update(['status' => 'rejected', 'expires_at' => null]);

        Cache::forget("access_approved_{$req->ip}");

        return back()->with('success', "Đã thu hồi quyền truy cập của {$req->name}.");
    }

    private function geolocate(string $ip): ?array
    {
        if (in_array($ip, ['127.0.0.1', '::1']) || str_starts_with($ip, '192.168.') || str_starts_with($ip, '10.')) {
            return ['country' => 'Local', 'city' => 'localhost', 'regionName' => ''];
        }

        $cacheKey = "geo_{$ip}";
        if ($cached = Cache::get($cacheKey)) return $cached;

        try {
            $client = new \GuzzleHttp\Client(['timeout' => 4, 'connect_timeout' => 2]);
            $res    = $client->get("http://ip-api.com/json/{$ip}?fields=status,country,regionName,city,isp,lat,lon");
            $data   = json_decode($res->getBody(), true);

            if (($data['status'] ?? '') === 'success') {
                Cache::put($cacheKey, $data, now()->addDay());
                return $data;
            }
        } catch (\Exception $e) {
            Log::debug("Geolocate {$ip}: " . $e->getMessage());
        }

        return null;
    }
}
