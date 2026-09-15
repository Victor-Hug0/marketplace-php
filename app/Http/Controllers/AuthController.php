<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();
        $key = 'login:' . $request->ip();

        if (RateLimiter::tooManyAttempts($key, 10)) {
            return response()
                ->json([
                    'message' => 'Muitas tentativas de login. Por favor, tente novamente mais tarde.',
                ], 429)
                ->withHeaders([
                    'Retry-After' => RateLimiter::availableIn($key)
                ]);
        }

        if (!Auth::attempt($credentials)) {
            RateLimiter::hit($key, 60);

            return response()
                ->json([
                    'message' => 'Credenciais inválidas',
                ], 401);
        }

        RateLimiter::clear($key);

        $user = Auth::user();

        return response()->json([
            'message' => 'Login realizado com sucesso',
            'user' => new UserResource($user),
            ...$this->issueTokenPair($user),
        ]);
    }

    public function register(RegisterRequest $request): JsonResponse
    {
        $data = $request->validated();
        $key = 'register:' . $request->ip() . ':' . $data['email'];

        if (RateLimiter::tooManyAttempts($key, 10)) {
            return response()
                ->json([
                    'message' => 'Muitas tentativas de cadastro. Por favor, tente novamente mais tarde.',
                ], 429);
        }

        RateLimiter::hit($key, 60);

        $user = User::create($data);

        RateLimiter::clear($key);

        return response()
            ->json([
                'message' => 'Usuário registrado com sucesso',
                'user' => new UserResource($user)
            ], 201);
    }

    public function logout(Request $request): Response
    {
        $request->user()->tokens()->whereIn('name', ['access_token', 'refresh_token'])->delete();

        return response()->noContent();
    }

    public function refresh(Request $request): JsonResponse
    {
        $refresh_token = $request->user()->currentAccessToken();

        if ($refresh_token->name !== 'refresh_token' || !$refresh_token->can('refresh')) {
            return response()
                ->json([
                    'message' => 'Token de atualização inválido',
                ], 401);
        }

        $user = $request->user();

        return response()
            ->json([
                'message' => 'Token de atualização realizado com sucesso',
                ...$this->issueTokenPair($user),
            ]);
    }

    private function issueTokenPair(User $user): array
    {
        $user->tokens()->whereIn('name', ['access_token', 'refresh_token'])->delete();

        $access_token = $user->createToken('access_token', ['access'], now()->addHour())->plainTextToken;
        $refresh_token = $user->createToken('refresh_token', ['refresh'], now()->addDays(30))->plainTextToken;

        return compact('access_token', 'refresh_token');
    }

}
