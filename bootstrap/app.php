<?php

use App\Http\Exceptions\ApiErrorResponse;
use App\Http\Middleware\EnsureEmailIsVerified;
use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\VerifyMockPaymentWebhookSecret;
use App\Models\AuditLog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [
            ForceJsonResponse::class,
        ]);

        $middleware->alias([
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
            'role' => EnsureUserHasRole::class,
            'payments.mock-webhook' => VerifyMockPaymentWebhookSecret::class,
            'email.verified' => EnsureEmailIsVerified::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn ($request) => $request->is('api/*') || $request->expectsJson()
        );

        $exceptions->render(function (ValidationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiErrorResponse::create(
                message: 'Os dados enviados são inválidos.',
                statusCode: 422,
                errors: $e->errors(),
            );
        });

        $exceptions->render(function (ModelNotFoundException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiErrorResponse::create('Recurso não encontrado.', 404);
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            AuditLog::create([
                'action' => 'auth.unauthenticated',
                'actor_id' => null,
                'actor_type' => null,
                'payload' => ['ip_address' => $request->ip(), 'user_agent' => $request->userAgent(), 'url' => $request->url(), 'msg' => 'Usuário não autenticado'],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'created_at' => now(),
            ]);

            return ApiErrorResponse::create('Usuário não autenticado.', 401);
        });

        $exceptions->render(function (AuthorizationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            AuditLog::create([
                'action' => 'auth.unauthorized',
                'actor_id' => $request->user()->id,
                'actor_type' => $request->user()->getMorphClass(),
                'payload' => ['email' => $request->user()->email, 'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(), 'url' => $request->url(), 'msg' => 'Usuário não autorizado'],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'created_at' => now(),
            ]);

            return ApiErrorResponse::create('Usuário não autorizado.', 403);
        });

        $exceptions->render(function (InvalidSignatureException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiErrorResponse::create('Link de verificação inválido ou expirado.', 403);
        });

        $exceptions->render(function (HttpException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiErrorResponse::create(
                $e->getMessage() ?: 'Não foi possível processar a solicitação.',
                $e->getStatusCode(),
            );
        });
    })->create();
