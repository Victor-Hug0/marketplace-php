<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Exceptions\ApiErrorResponse;
use Closure;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureEmailIsVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail()) {
            return ApiErrorResponse::create(
                'Confirme seu e-mail para continuar. Peça um novo link em POST /api/v1/auth/email/verification-notification.',
                403,
            );
        }

        return $next($request);
    }
}
