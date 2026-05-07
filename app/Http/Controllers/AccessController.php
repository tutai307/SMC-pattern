<?php

namespace App\Http\Controllers;

use App\Models\AccessRequest;
use App\Services\TelegramService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class AccessController extends Controller
{
    public function __construct(private TelegramService $telegram) {}

    // ── Visitor: submit access request ───────────────────────────────────────

    public function submitRequest(Request $request)
    {
        $ip   = $request->ip();
        $name = trim($request->input('name', ''));

        if (strlen($name) < 2 || strlen($name) > 60) {
            return back()->withErrors(['name' => 'Tên phải từ 2-60 ký tự.']);
        }

        // Already has active approval → grant session now
        if ($approved = AccessRequest::findApprovedByIp($ip)) {
            session(['felix_auth' => true]);
            return redirect()->route('dashboard');
        }

        // Already has pending request
        $existing = AccessRequest::where('ip', $ip)->where('status', 'pending')->first();
        if ($existing) {
            return back()->with('request_pending', true);
        }

        $location = $this->resolveLocation($ip);

        $record = AccessRequest::updateOrCreate(
            ['ip' => $ip, 'status' => 'visiting'],
            [
                'name'       => $name,
                'location'   => $location,
                'user_agent' => substr($request->userAgent() ?? '', 0, 200),
                'status'     => 'pending',
            ]
        );

        // Notify admin via Telegram
        if ($this->telegram->isConfigured()) {
            $this->telegram->sendRaw(
                "🔔 <b>Yêu cầu truy cập mới!</b>\n"
                . "━━━━━━━━━━━━━━━\n"
                . "👤 Tên: <b>{$name}</b>\n"
                . "🌐 IP: <code>{$ip}</code>\n"
                . "📍 Vị trí: {$location}\n"
                . "━━━━━━━━━━━━━━━\n"
                . "→ Duyệt tại: <b>/admin/access</b>"
            );
        }

        return back()->with('request_pending', true);
    }

    // ── Admin: dashboard ─────────────────────────────────────────────────────

    public function dashboard()
    {
        $pending  = AccessRequest::where('status', 'pending')->latest()->get();
        $approved = AccessRequest::where('status', 'approved')
                        ->where('expires_at', '>', now())
                        ->latest()->get();
        $denied   = AccessRequest::where('status', 'denied')->latest()->limit(20)->get();
        $visits   = AccessRequest::where('status', 'visiting')->latest()->limit(50)->get();

        return view('admin.access', compact('pending', 'approved', 'denied', 'visits'));
    }

    public function approve(int $id)
    {
        $rec = AccessRequest::findOrFail($id);
        $rec->update(['status' => 'approved', 'expires_at' => now()->addHours(24)]);

        if ($this->telegram->isConfigured() && $rec->name) {
            $this->telegram->sendRaw(
                "✅ Đã duyệt: <b>{$rec->name}</b> ({$rec->ip}) — hết hạn sau 24h"
            );
        }

        return back()->with('success', "Đã duyệt {$rec->name}.");
    }

    public function deny(int $id)
    {
        $rec = AccessRequest::findOrFail($id);
        $rec->update(['status' => 'denied', 'expires_at' => null]);

        return back()->with('success', "Đã từ chối {$rec->name}.");
    }

    public function revoke(int $id)
    {
        $rec = AccessRequest::findOrFail($id);
        $rec->update(['status' => 'denied', 'expires_at' => null]);

        return back()->with('success', "Đã thu hồi quyền truy cập.");
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function resolveLocation(string $ip): string
    {
        if (in_array($ip, ['127.0.0.1', '::1'])) return 'Localhost';

        $cacheKey = 'ip_location_' . md5($ip);
        return Cache::remember($cacheKey, now()->addHours(24), function () use ($ip) {
            try {
                $client = new \GuzzleHttp\Client(['timeout' => 3]);
                $res    = $client->get("http://ip-api.com/json/{$ip}?fields=city,country,regionName");
                $data   = json_decode($res->getBody(), true);
                $parts  = array_filter([$data['city'] ?? '', $data['regionName'] ?? '', $data['country'] ?? '']);
                return implode(', ', $parts) ?: 'Không rõ';
            } catch (\Exception) {
                return 'Không rõ';
            }
        });
    }

    public static function logVisit(Request $request): void
    {
        $ip = $request->ip();
        if (AccessRequest::where('ip', $ip)->exists()) return;

        $cacheKey = 'visit_logged_' . md5($ip);
        if (Cache::has($cacheKey)) return;
        Cache::put($cacheKey, true, now()->addMinutes(30));

        // No geolocation here — keep request path fast
        AccessRequest::create([
            'ip'         => $ip,
            'location'   => null,
            'user_agent' => substr($request->userAgent() ?? '', 0, 200),
            'status'     => 'visiting',
        ]);
    }
}
