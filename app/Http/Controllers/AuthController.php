<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
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

        // ── Pre-send diagnostics ──────────────────────────────────────────────
        $mailer     = config('mail.default');
        $smtpHost   = config('mail.mailers.smtp.host');
        $smtpPort   = config('mail.mailers.smtp.port');
        $smtpScheme = config('mail.mailers.smtp.scheme');
        $smtpUser   = config('mail.mailers.smtp.username');
        $fromAddr   = config('mail.from.address');
        $timeout    = config('mail.mailers.smtp.timeout');

        Log::info('Felix OTP mail: attempting send', [
            'mailer'      => $mailer,
            'smtp_host'   => $smtpHost,
            'smtp_port'   => $smtpPort,
            'smtp_scheme' => $smtpScheme,
            'smtp_user'   => $smtpUser,
            'from'        => $fromAddr,
            'to'          => $adminEmail,
            'timeout'     => $timeout,
            'php_version' => PHP_VERSION,
            'timestamp'   => now()->toIso8601String(),
        ]);

        $startTime = microtime(true);

        try {
            Mail::raw(
                "Felix Terminal — Mã OTP đăng nhập của bạn:\n\n{$otp}\n\nMã có hiệu lực trong 10 phút.\nNếu bạn không yêu cầu, hãy bỏ qua email này.",
                function ($message) use ($adminEmail, $otp) {
                    $message->to($adminEmail)
                            ->subject("[Felix] OTP đăng nhập: {$otp}");
                }
            );

            $elapsed = round((microtime(true) - $startTime) * 1000);

            Log::info('Felix OTP mail: sent successfully', [
                'to'           => $adminEmail,
                'elapsed_ms'   => $elapsed,
                'timestamp'    => now()->toIso8601String(),
            ]);
        } catch (\Exception $e) {
            $elapsed = round((microtime(true) - $startTime) * 1000);

            Log::error('Felix OTP mail: send failed', [
                'to'            => $adminEmail,
                'elapsed_ms'    => $elapsed,
                'error_class'   => get_class($e),
                'error_code'    => $e->getCode(),
                'error_message' => $e->getMessage(),
                'stack_trace'   => $e->getTraceAsString(),
                // Surface the root cause when the exception wraps another
                'previous_error' => $e->getPrevious()
                    ? '[' . get_class($e->getPrevious()) . '] ' . $e->getPrevious()->getMessage()
                    : null,
                'smtp_host'     => $smtpHost,
                'smtp_port'     => $smtpPort,
                'smtp_scheme'   => $smtpScheme,
                'smtp_user'     => $smtpUser,
                'timeout'       => $timeout,
                'timestamp'     => now()->toIso8601String(),
            ]);

            $displayError = config('app.debug')
                ? '[DEBUG] ' . get_class($e) . ': ' . $e->getMessage()
                : 'Không gửi được email. Kiểm tra cấu hình MAIL_* trong .env';

            return back()->withErrors(['otp' => $displayError]);
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
