<?php

namespace App\Http\Middleware;

use App\Http\Controllers\AccessController;
use App\Models\AccessRequest;
use Closure;
use Illuminate\Http\Request;

class RequireAuth
{
    public function handle(Request $request, Closure $next)
    {
        // 1. Admin OTP session
        if (session('felix_auth')) {
            return $next($request);
        }

        // 2. IP-based approval (guests approved by admin)
        $approved = AccessRequest::findApprovedByIp($request->ip());
        if ($approved) {
            session(['felix_auth' => true, 'felix_guest' => true]);
            return $next($request);
        }

        // 3. Log the visit and redirect
        AccessController::logVisit($request);

        return redirect()->route('login');
    }
}
