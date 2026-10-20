<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Exceptions\ApiErrorResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class VerifyMockPaymentWebhookSecret
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('payments.mock.webhook_secret');
        $provided = $request->header((string) config('payments.mock.webhook_header'));

        if (
            ! is_string($secret)
            || $secret === ''
            || ! is_string($provided)
            || ! hash_equals($secret, $provided)
        ) {
            return ApiErrorResponse::create('Webhook não autorizado.', 401);
        }

        return $next($request);
    }
}
