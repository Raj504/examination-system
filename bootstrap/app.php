<?php

use App\Exceptions\AppException;
use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Middleware\EnsureIdempotency;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'auth.apikey' => AuthenticateApiKey::class,
            'idempotent' => EnsureIdempotency::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Business-rule rejections are expected outcomes, not incidents.
        $exceptions->dontReport(AppException::class);

        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());

        // Uniform error envelope: {"error": {"code", "message", "details"?}}
        $exceptions->render(function (AppException $e, Request $request) {
            if (! $request->is('api/*')) {
                // Web pages: go back and show the message in red.
                return back()->withInput()->with('error', $e->getMessage());
            }

            return response()->json([
                'error' => array_filter([
                    'code' => $e->errorCode,
                    'message' => $e->getMessage(),
                    'details' => $e->details ?: null,
                ]),
            ], $e->status);
        });

        $exceptions->render(function (ValidationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null; // web pages: Laravel's normal "redirect back with errors"
            }

            return response()->json([
                'error' => [
                    'code' => 'validation_failed',
                    'message' => $e->getMessage(),
                    'details' => $e->errors(),
                ],
            ], 422);
        });

        $exceptions->render(function (MethodNotAllowedHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['error' => [
                    'code' => 'method_not_allowed',
                    'message' => "{$request->method()} is not supported for /{$request->path()}.",
                    'details' => ['allowed' => explode(', ', $e->getHeaders()['Allow'] ?? '')],
                ]], 405, $e->getHeaders());
            }
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                $message = $e->getPrevious() instanceof ModelNotFoundException ? 'Resource not found.' : 'Route not found.';

                return response()->json(['error' => ['code' => 'not_found', 'message' => $message]], 404);
            }
        });
    })->create();
