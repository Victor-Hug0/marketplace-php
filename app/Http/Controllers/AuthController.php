<?php

namespace App\Http\Controllers;

use App\Http\Exceptions\ApiErrorResponse;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\AuditLog;
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
    ) {
    }
    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();
        $key = 'login:' . $request->ip();

        if (RateLimiter::tooManyAttempts($key, 10)) {
            return ApiErrorResponse::create('Muitas tentativas de login. Por favor, tente novamente mais tarde.', 429)
                ->withHeaders([
                    'Retry-After' => RateLimiter::availableIn($key)
                ]);
        }

        if (!$this->authService->isValidCredentials($credentials)) {
            RateLimiter::hit($key, 60);

            return ApiErrorResponse::create('Credenciais inválidas', 401);
        }

        RateLimiter::clear($key);

        $user = Auth::user();

        AuditLog::create([
            'action' => 'auth.login',
            'actor_id' => $user->id,
            'actor_type' => $user->getMorphClass(),
            'payload' => ['email' => $user->email],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);

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
            return ApiErrorResponse::create('Muitas tentativas de cadastro. Por favor, tente novamente mais tarde.', 429)
                ->withHeaders([
                    'Retry-After' => RateLimiter::availableIn($key)
                ]);
        }

        RateLimiter::hit($key, 60);

        $user = $this->authService->register($data);

        RateLimiter::clear($key);

        AuditLog::create([
            'action' => 'auth.register',
            'actor_id' => $user->id,
            'actor_type' => $user->getMorphClass(),
            'payload' => ['email' => $user->email],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);

        return response()
            ->json([
                'message' => 'Usuário registrado com sucesso',
                'user' => new UserResource($user)
            ], 201);
    }

    public function logout(Request $request): Response
    {
        $this->authService->deleteUserTokens($request->user());

        AuditLog::create([
            'action' => 'auth.logout',
            'actor_id' => $request->user()->id,
            'actor_type' => $request->user()->getMorphClass(),
            'payload' => ['email' => $request->user()->email],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);

        return response()->noContent();
    }

    public function refresh(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$this->authService->isUserRefreshTokenValid($user)) {
            AuditLog::create([
                'action' => 'auth.refresh.invalid',
                'actor_id' => $user->id,
                'actor_type' => $user->getMorphClass(),
                'payload' => ['email' => $user->email, 'token' => $request->bearerToken(), 'msg' => 'Token de atualização inválido'],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'created_at' => now(),
            ]);
            return ApiErrorResponse::create('Token de atualização inválido', 401);
        }

        AuditLog::create([
            'action' => 'auth.refresh',
            'actor_id' => $user->id,
            'actor_type' => $user->getMorphClass(),
            'payload' => ['email' => $user->email],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);

        return response()
            ->json([
                'message' => 'Token de atualização realizado com sucesso',
                ...$this->authService->issueTokenPair($user),
            ]);
    }
}
