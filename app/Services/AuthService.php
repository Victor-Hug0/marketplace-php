<?php

namespace App\Services;

use App\Http\Requests\RegisterRequest;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

class AuthService
{

    public function isValidCredentials(array $credentials): bool
    {
        return Auth::attempt($credentials);
    }

    public function register(array $data): User
    {
        $user = User::create($data);

        return $user;
    }

    public function deleteUserTokens(User $user): void
    {
        $user->tokens()->whereIn('name', ['access_token', 'refresh_token'])->delete();
    }

    public function isUserRefreshTokenValid(User $user): bool
    {
        $refresh_token = $user->currentAccessToken();

        if ($refresh_token->name !== 'refresh_token' || !$refresh_token->can('refresh')) {
            return false;
        }

        return true;
    }

    public function issueTokenPair(User $user): array
    {
        $user->tokens()->whereIn('name', ['access_token', 'refresh_token'])->delete();

        $access_token = $user->createToken('access_token', ['access'], now()->addHour())->plainTextToken;
        $refresh_token = $user->createToken('refresh_token', ['refresh'], now()->addDays(30))->plainTextToken;

        return compact('access_token', 'refresh_token');
    }
}
