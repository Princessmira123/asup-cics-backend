<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__ .'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
    $middleware->alias([
        'admin.auth' => \App\Http\Middleware\AdminAuth::class,
        'member.active' => \App\Http\Middleware\MemberActive::class,
    ]);
})
    
    ->withExceptions(function (Exceptions $exceptions): void {
        // Force every API error to come back as readable JSON with the
        // actual message/file/line — instead of Laravel's default HTML
        // error page, which is what Postman was showing as a wall of
        // <!DOCTYPE html> instead of a usable error. This is what makes
        // "the real cause of the problem" actually visible going forward,
        // for this bug and any future one, without needing to dig through
        // server logs each time.
        $exceptions->render(function (\Throwable $e, \Illuminate\Http\Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                $status = method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500;
                if ($status < 100 || $status >= 600) $status = 500;
                return response()->json([
                    'success'   => false,
                    'message'   => $e->getMessage() ?: 'Server error',
                    'exception' => get_class($e),
                    'at'        => basename($e->getFile()) . ':' . $e->getLine(),
                ], $status);
            }
        });
    })->create();
