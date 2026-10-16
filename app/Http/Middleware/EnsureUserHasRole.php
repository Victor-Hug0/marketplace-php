<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\RoleName;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureUserHasRole
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $roles): Response
    {
        $user = $request->user();

        abort_if($user === null, 401, 'Usuário não autenticado.');

        $allowed = collect(explode('|', $roles))
            ->map(fn (string $role): ?RoleName => RoleName::tryFrom($role))
            ->filter()
            ->contains(fn (RoleName $role): bool => $user->hasRole($role));

        abort_unless($allowed, 403, 'Usuário não autorizado.');

        return $next($request);
    }
}
