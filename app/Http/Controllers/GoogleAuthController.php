<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Exceptions\ApiErrorResponse;
use App\Http\Requests\GoogleCallbackRequest;
use App\Http\Resources\UserResource;
use App\Services\AuthService;
use App\Services\GoogleAuthService;
use Illuminate\Http\JsonResponse;
use Knuckles\Scribe\Attributes as Doc;

#[Doc\Group('Autenticação')]
#[Doc\Subgroup('Login com Google')]
final class GoogleAuthController extends Controller
{
    public function __construct(
        private readonly GoogleAuthService $google,
        private readonly AuthService $authService,
    ) {}

    #[Doc\Endpoint('URL de login com Google', 'Devolve a URL de consentimento do Google. O cliente redireciona o usuário para ela; ao final, o Google volta para `GOOGLE_REDIRECT_URI` com `code`. 503 se `GOOGLE_CLIENT_ID`/`GOOGLE_CLIENT_SECRET` não estiverem configurados.')]
    #[Doc\Unauthenticated]
    #[Doc\Response('{"data":{"url":"https://accounts.google.com/o/oauth2/auth?client_id=...&redirect_uri=...&scope=openid+profile+email&response_type=code"}}', status: 200)]
    public function redirect(): JsonResponse
    {
        return response()->json(['data' => ['url' => $this->google->redirectUrl()]]);
    }

    #[Doc\Endpoint('Callback do Google', 'Troca o `code` pelo perfil do Google e devolve o par de tokens, como no login. Conta nova (201) nasce como `buyer` com e-mail confirmado; conta existente com o mesmo e-mail é vinculada (200). O e-mail precisa estar verificado no Google (422). Limite de 10 requisições por minuto.')]
    #[Doc\Unauthenticated]
    #[Doc\Response('{"message":"Login com Google realizado com sucesso","user":{"id":"01a14513-4821-7084-bb71-dbc2e1d84d94","name":"Maria Compradora","email":"maria@gmail.com","email_verified_at":"2026-10-20T10:00:00-03:00"},"access_token":"1|aBcD...","refresh_token":"2|eFgH..."}', status: 200)]
    #[Doc\Response('{"message":"Não foi possível autenticar com o Google. Tente entrar novamente.","timestamp":"2026-10-20T10:00:00-03:00","errors":[]}', status: 401)]
    public function callback(GoogleCallbackRequest $request): JsonResponse
    {
        if ($request->filled('error')) {
            return ApiErrorResponse::create('Login com Google cancelado ou negado.', 401);
        }

        ['user' => $user, 'created' => $created] = $this->google->authenticate($this->google->userFromCallback());

        return response()->json([
            'message' => 'Login com Google realizado com sucesso',
            'user' => new UserResource($user),
            ...$this->authService->issueTokenPair($user),
        ], $created ? 201 : 200);
    }
}
