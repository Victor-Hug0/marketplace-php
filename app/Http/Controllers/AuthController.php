<?php

namespace App\Http\Controllers;

use App\Http\Exceptions\ApiErrorResponse;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\AuditLog;
use App\Services\AuthService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Knuckles\Scribe\Attributes as Doc;

#[Doc\Group('Autenticação')]
class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService $authService
    ) {}

    #[Doc\Endpoint('Entrar', 'Devolve `access_token` (1 hora) e `refresh_token` (30 dias) e invalida os pares anteriores do usuário. 10 falhas por IP bloqueiam o login por 60 segundos (429).')]
    #[Doc\Unauthenticated]
    #[Doc\Response('{"message":"Login realizado com sucesso","user":{"id":"01a14513-4821-7084-bb71-dbc2e1d84d94","name":"Maria Compradora","email":"maria@exemplo.com"},"access_token":"1|aBcD...","refresh_token":"2|eFgH..."}', status: 200)]
    #[Doc\Response('{"message":"Credenciais inválidas","timestamp":"2026-10-20T10:00:00-03:00","errors":[]}', status: 401)]
    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();
        $key = 'login:'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 10)) {
            return ApiErrorResponse::create('Muitas tentativas de login. Por favor, tente novamente mais tarde.', 429)
                ->withHeaders([
                    'Retry-After' => RateLimiter::availableIn($key),
                ]);
        }

        if (! $this->authService->isValidCredentials($credentials)) {
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

    #[Doc\Endpoint('Cadastrar comprador', 'Cria o usuário com o papel `buyer` e envia (em fila) o link de confirmação de e-mail; o checkout fica bloqueado (403) até a confirmação. Senha com 8 a 64 caracteres, maiúscula, minúscula, número e símbolo. Limite de 10 tentativas por IP e e-mail.')]
    #[Doc\Unauthenticated]
    #[Doc\Response('{"message":"Usuário registrado com sucesso. Enviamos um link de confirmação para o seu e-mail.","user":{"id":"01a14513-4821-7084-bb71-dbc2e1d84d94","name":"Maria Compradora","email":"maria@exemplo.com","email_verified_at":null}}', status: 201)]
    public function register(RegisterRequest $request): JsonResponse
    {
        $data = $request->validated();
        $key = 'register:'.$request->ip().':'.$data['email'];

        if (RateLimiter::tooManyAttempts($key, 10)) {
            return ApiErrorResponse::create('Muitas tentativas de cadastro. Por favor, tente novamente mais tarde.', 429)
                ->withHeaders([
                    'Retry-After' => RateLimiter::availableIn($key),
                ]);
        }

        RateLimiter::hit($key, 60);

        $user = $this->authService->register($data);

        event(new Registered($user));

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
                'message' => 'Usuário registrado com sucesso. Enviamos um link de confirmação para o seu e-mail.',
                'user' => new UserResource($user),
            ], 201);
    }

    #[Doc\Endpoint('Sair', 'Revoga o par de tokens do usuário.')]
    #[Doc\Response('', status: 204)]
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

    #[Doc\Endpoint('Renovar tokens', 'Envie o `refresh_token` como Bearer. Devolve um par novo e invalida o anterior; um `access_token` aqui responde 401.')]
    #[Doc\Response('{"message":"Token de atualização realizado com sucesso","access_token":"3|iJkL...","refresh_token":"4|mNoP..."}', status: 200)]
    public function refresh(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $this->authService->isUserRefreshTokenValid($user)) {
            AuditLog::create([
                'action' => 'auth.refresh.invalid',
                'actor_id' => $user->id,
                'actor_type' => $user->getMorphClass(),
                'payload' => ['email' => $user->email, 'msg' => 'Token de atualização inválido'],
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
