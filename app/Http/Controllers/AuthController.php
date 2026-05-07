<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

class AuthController extends Controller
{
    private function adminEmail(): string
    {
        return (string) env('ADMIN_EMAIL', '');
    }

    public function showLogin()
    {
        if (session('felix_auth')) {
            return redirect()->route('dashboard');
        }

        return view('login');
    }

    public function sendOtp(Request $request)
    {
        $adminEmail = $this->adminEmail();

        if (!$adminEmail) {
            return back()->withErrors(['otp' => 'ADMIN_EMAIL chưa được cấu hình trong .env']);
        }

        $otp      = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $cacheKey = 'felix_otp_' . md5($adminEmail);

        Cache::put($cacheKey, $otp, now()->addMinutes(10));

        try {
            Mail::raw(
                "Felix Terminal — Mã OTP đăng nhập của bạn:\n\n{$otp}\n\nMã có hiệu lực trong 10 phút.\nNếu bạn không yêu cầu, hãy bỏ qua email này.",
                function ($message) use ($adminEmail, $otp) {
                    $message->to($adminEmail)
                            ->subject("[Felix] OTP đăng nhập: {$otp}");
                }
            );
        } catch (\Exception $e) {
            \Log::error('Felix OTP mail error: ' . $e->getMessage());
            return back()->withErrors(['otp' => 'Không gửi được email. Kiểm tra cấu hình MAIL_* trong .env']);
        }

        return back()->with('otp_sent', true);
    }

    public function verifyOtp(Request $request)
    {
        $adminEmail = $this->adminEmail();
        $input      = trim($request->input('otp', ''));
        $cacheKey   = 'felix_otp_' . md5($adminEmail);
        $stored     = Cache::get($cacheKey);

        if (!$stored || $input !== $stored) {
            return back()->withErrors(['otp' => 'OTP không đúng hoặc đã hết hạn.'])->with('otp_sent', true);
        }

        Cache::forget($cacheKey);
        session(['felix_auth' => true]);

        return redirect()->route('dashboard');
    }

    public function logout()
    {
        session()->forget('felix_auth');
        return redirect()->route('login');
    }
}
