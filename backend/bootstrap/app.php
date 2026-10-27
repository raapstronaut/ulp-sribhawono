<?php

use App\Http\Middleware\EnsureActiveAdmin;
use App\Http\Middleware\EnsureFieldAccount;
use App\Services\Work\WorkflowConflictException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');

        $middleware->redirectGuestsTo(fn (Request $request) => route('login'));
        $middleware->redirectUsersTo(fn (Request $request) => route('admin.dashboard'));
        $middleware->alias([
            'admin' => EnsureActiveAdmin::class,
            'field' => EnsureFieldAccount::class,
        ]);
        // Tolak non-admin sebelum route model binding agar keberadaan data tidak bocor (403, bukan 404).
        $middleware->prependToPriorityList(SubstituteBindings::class, EnsureActiveAdmin::class);
        $middleware->prependToPriorityList(SubstituteBindings::class, EnsureFieldAccount::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Konflik workflow: kesalahan pengguna (bukan galat server); API 409, Web Admin kembali dengan pesan.
        $exceptions->dontReport(WorkflowConflictException::class);
        $exceptions->render(function (WorkflowConflictException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'errors' => ['workflow' => [$e->getMessage()]],
                ], 409);
            }

            return back()->withInput()->withErrors(['workflow' => $e->getMessage()]);
        });
    })->create();
