<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RequireAdminAuth
{
    public function handle(Request $request, Closure $next)
    {
        if (!session('felix_auth')) {
            abort(403, 'Chỉ admin mới có quyền thực hiện thao tác này.');
        }

        return $next($request);
    }
}
