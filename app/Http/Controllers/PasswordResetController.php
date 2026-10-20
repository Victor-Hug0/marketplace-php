<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ForgotPasswordRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Services\PasswordResetService;
use Illuminate\Http\JsonResponse;
use Knuckles\Scribe\Attributes as Doc;

#[Doc\Group('Autenticação')]
final class PasswordResetController extends Controller
{
    public function __construct(
        private readonly PasswordResetService $passwordResets,
    ) {}

    #[Doc\Endpoint('Esqueci minha senha', 'Envia por e-mail (em fila) um link para `FRONTEND_URL/redefinir-senha?token=...&email=...`. A resposta é a mesma exista ou não a conta, para não revelar e-mails cadastrados. O link vale 60 minutos e um novo só sai depois de 60 segundos. Limite de 5 requisições por minuto.')]
    #[Doc\Unauthenticated]
    #[Doc\Response('{"message":"Se o e-mail estiver cadastrado, você receberá um link para redefinir a senha."}', status: 200)]
    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        $this->passwordResets->sendResetLink($request->validated('email'));

        return response()->json([
            'message' => 'Se o e-mail estiver cadastrado, você receberá um link para redefinir a senha.',
        ]);
    }

    #[Doc\Endpoint('Redefinir senha', 'Troca a senha com o token do e-mail. O token é de uso único; a troca revoga todos os tokens de acesso do usuário (todas as sessões saem) e confirma o e-mail. Limite de 5 requisições por minuto.')]
    #[Doc\Unauthenticated]
    #[Doc\Response('{"message":"Senha redefinida. Entre novamente com a nova senha."}', status: 200)]
    #[Doc\Response('{"message":"Os dados enviados são inválidos.","timestamp":"2026-10-20T10:00:00-03:00","errors":{"token":["O link de redefinição é inválido ou expirou. Peça um novo."]}}', status: 422)]
    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $this->passwordResets->reset(
            $request->validated('email'),
            $request->validated('token'),
            $request->validated('password'),
        );

        return response()->json([
            'message' => 'Senha redefinida. Entre novamente com a nova senha.',
        ]);
    }
}
