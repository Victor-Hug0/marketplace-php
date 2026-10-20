<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class EmailVerificationService
{
    /**
     * Verifying twice is a no-op, so reopening the e-mail link never fails.
     */
    public function verify(User $user, string $hash): void
    {
        if (! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            throw new AccessDeniedHttpException('Link de verificação inválido ou expirado.');
        }

        if ($user->hasVerifiedEmail()) {
            return;
        }

        $user->markEmailAsVerified();
        event(new Verified($user));

        $this->audit($user, 'auth.email_verified');
    }

    public function resend(User $user): void
    {
        if ($user->hasVerifiedEmail()) {
            throw new ConflictHttpException('O e-mail já foi confirmado.');
        }

        $user->sendEmailVerificationNotification();

        $this->audit($user, 'auth.email_verification_resent');
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
