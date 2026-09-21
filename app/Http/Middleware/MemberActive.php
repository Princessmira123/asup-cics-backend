<?php
// app/Http/Middleware/MemberActive.php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class MemberActive
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if ($user && $user->status === 'suspended') {
            return response()->json(['success' => false, 'message' => 'Account suspended. Contact admin.'], 403);
        }
        return $next($request);
    }
}
