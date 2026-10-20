<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

final class GoogleAuthService
{
    public function __construct(
        private readonly AuthService $authService,
    ) {}

    public function redirectUrl(): string
    {
        $this->ensureConfigured();

        return Socialite::driver('google')->stateless()->redirect()->getTargetUrl();
    }

    /**
     * Exchanges the authorization code from the current request for the Google profile.
     */
    public function userFromCallback(): SocialiteUser
    {
        $this->ensureConfigured();

        try {
            return Socialite::driver('google')->stateless()->user();
        } catch (GuzzleException|InvalidStateException) {
            throw new UnauthorizedHttpException('Bearer', 'Não foi possível autenticar com o Google. Tente entrar novamente.');
        }
    }

    /**
     * Finds the user by Google ID, links an existing account with the same e-mail, or creates a buyer.
     *
     * @return array{user: User, created: bool}
     */
    public function authenticate(SocialiteUser $googleUser): array
    {
        $googleId = (string) $googleUser->getId();
        $email = Str::lower(trim((string) $googleUser->getEmail()));

        if ($googleId === '' || $email === '' || ($googleUser->getRaw()['email_verified'] ?? false) !== true) {
            throw new UnprocessableEntityHttpException('A conta Google precisa ter um e-mail verificado.');
        }

        try {
            return DB::transaction(fn (): array => $this->findOrCreate($googleUser, $googleId, $email));
        } catch (UniqueConstraintViolationException) {
            // A concurrent first login created the account; this attempt just logs in.
            return DB::transaction(fn (): array => $this->findOrCreate($googleUser, $googleId, $email));
        }
    }

    /**
     * @return array{user: User, created: bool}
     */
    private function findOrCreate(SocialiteUser $googleUser, string $googleId, string $email): array
    {
        $user = User::withTrashed()->where('google_id', $googleId)->first()
            ?? User::withTrashed()->where('email', $email)->lockForUpdate()->first();

        if ($user?->trashed()) {
            throw new ConflictHttpException('Esta conta está desativada.');
        }

        if ($user !== null && $user->google_id === $googleId) {
            $this->audit($user, 'auth.google_login');

            return ['user' => $user, 'created' => false];
        }

        if ($user !== null) {
            if ($user->google_id !== null) {
                throw new ConflictHttpException('Este e-mail já está vinculado a outra conta Google.');
            }

            // Google vouches for the e-mail, so linking also counts as verifying it.
            $user->forceFill([
                'google_id' => $googleId,
                'email_verified_at' => $user->email_verified_at ?? now(),
            ])->save();

            $this->audit($user, 'auth.google_linked');

            return ['user' => $user, 'created' => false];
        }

        $user = $this->authService->register([
            'name' => $googleUser->getName() ?: Str::before($email, '@'),
            'email' => $email,
            // Unusable random password; the user can set one through "forgot password".
            'password' => Str::password(40),
        ]);

        $user->forceFill([
            'google_id' => $googleId,
            'email_verified_at' => now(),
        ])->save();

        $this->audit($user, 'auth.google_register');

        return ['user' => $user, 'created' => true];
    }

    private function ensureConfigured(): void
    {
        if (blank(config('services.google.client_id')) || blank(config('services.google.client_secret'))) {
            throw new ServiceUnavailableHttpException(null, 'Login com Google não está configurado neste ambiente.');
        }
    }

    private function audit(User $user, string $action): void
    {
        AuditLog::query()->create([
            'action' => $action,
            'actor_id' => $user->id,
            'actor_type' => $user->getMorphClass(),
            'auditable_id' => $user->id,
            'auditable_type' => $user->getMorphClass(),
            'payload' => ['email' => $user->email],
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'created_at' => now(),
        ]);
    }
}
