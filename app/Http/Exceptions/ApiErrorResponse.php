<?php

declare(strict_types=1);

namespace App\Http\Exceptions;

use Illuminate\Http\JsonResponse;

final class ApiErrorResponse
{
    public static function create(string $message, int $statusCode, array $errors = []): JsonResponse
    {
        return new JsonResponse([
            'message' => $message,
            'timestamp' => now()->toIso8601String(),
            'errors' => $errors,
        ], $statusCode);
    }
}
