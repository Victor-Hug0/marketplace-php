<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

final class PasswordResetService
{
    /**
     * Unknown e-mails and throttled requests return silently, so the caller cannot tell whether an account exists.
     */
    public function sendResetLink(string $email): void
    {
        $status = Password::sendResetLink(['email' => $email]);

        if ($status === Password::RESET_LINK_SENT) {
            $this->audit(User::query()->where('email', $email)->first(), 'auth.password_reset_requested');
        }
    }

    /**
     * The broker deletes the token after a successful reset, so each link works once.
     *
     * @throws ValidationException when the token is invalid, expired or belongs to another e-mail
     */
    public function reset(string $email, #[\SensitiveParameter] string $token, #[\SensitiveParameter] string $password): void
    {
        $status = Password::reset(
            ['email' => $email, 'token' => $token, 'password' => $password],
            function (User $user, string $password): void {
                DB::transaction(function () use ($user, $password): void {
                    $user->forceFill(['password' => $password])->save();

                    // Following the link proves ownership of the mailbox.
                    if (! $user->hasVerifiedEmail()) {
                        $user->markEmailAsVerified();
                    }

                    $user->tokens()->delete();

                    $this->audit($user, 'auth.password_reset');
                });

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'token' => ['O link de redefinição é inválido ou expirou. Peça um novo.'],
            ]);
        }
    }

    private function audit(?User $user, string $action): void
    {
        AuditLog::query()->create([
            'action' => $action,
            'actor_id' => $user?->id,
            'actor_type' => $user?->getMorphClass(),
            'auditable_id' => $user?->id,
            'auditable_type' => $user?->getMorphClass(),
            'payload' => ['email' => $user?->email],
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'created_at' => now(),
        ]);
    }
}
