<?php
// app/Http/Middleware/AdminAuth.php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class AdminAuth
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (!$user || !$user->currentAccessToken()->can('admin')) {
            return response()->json(['success' => false, 'message' => 'Admin access required.'], 403);
        }
        return $next($request);
    }
}
