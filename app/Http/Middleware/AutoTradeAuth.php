<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AutoTradeAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = env('AUTO_TRADE_TOKEN', '');

        if (empty($token)) {
            return response()->json(['error' => 'AUTO_TRADE_TOKEN not configured on server'], 500);
        }

        $provided = $request->header('X-Auto-Trade-Token', '');

        if (empty($provided) || !hash_equals($token, $provided)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
