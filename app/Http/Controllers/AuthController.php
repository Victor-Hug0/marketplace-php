<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;

class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService $authService
    ) {}
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

        if (!$this->authService->isValidCredentials($credentials)) {
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
            ...$this->authService->issueTokenPair($user),
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

        $user = $this->authService->register($data);

        RateLimiter::clear($key);

        return response()
            ->json([
                'message' => 'Usuário registrado com sucesso',
                'user' => new UserResource($user)
            ], 201);
    }

    public function logout(Request $request): Response
    {
        $this->authService->deleteUserTokens($request->user());

        return response()->noContent();
    }

    public function refresh(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$this->authService->isUserRefreshTokenValid($user)) {
            return response()
                ->json([
                    'message' => 'Token de atualização inválido',
                ], 401);
        }

        return response()
            ->json([
                'message' => 'Token de atualização realizado com sucesso',
                ...$this->authService->issueTokenPair($user),
            ]);
    }
}
