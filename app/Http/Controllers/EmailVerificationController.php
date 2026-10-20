<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\EmailVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Knuckles\Scribe\Attributes as Doc;

#[Doc\Group('Autenticação')]
final class EmailVerificationController extends Controller
{
    public function __construct(
        private readonly EmailVerificationService $verifications,
    ) {}

    #[Doc\Endpoint('Confirmar e-mail', 'Destino do link enviado no cadastro. A URL é assinada e expira em 60 minutos; assinatura inválida ou vencida responde 403. Não exige token de acesso, e abrir o link de novo não dá erro.')]
    #[Doc\Unauthenticated]
    #[Doc\UrlParam('id', description: 'ID do usuário (vem no link).', example: '01a159cb-1b3e-73da-b5a5-f225c2d77ad9')]
    #[Doc\UrlParam('hash', description: 'SHA-1 do e-mail (vem no link).', example: 'a94a8fe5ccb19ba61c4c0873d391e987982fbbd3')]
    #[Doc\QueryParam('expires', 'integer', 'Timestamp de expiração (vem no link).', example: 1792512000)]
    #[Doc\QueryParam('signature', 'string', 'Assinatura HMAC da URL (vem no link).', example: 'c0ffee')]
    #[Doc\Response('{"message":"E-mail confirmado."}', status: 200)]
    #[Doc\Response('{"message":"Link de verificação inválido ou expirado.","timestamp":"2026-10-20T10:00:00-03:00","errors":[]}', status: 403)]
    public function verify(string $id, string $hash): JsonResponse
    {
        $this->verifications->verify(User::query()->findOrFail($id), $hash);

        return response()->json(['message' => 'E-mail confirmado.']);
    }

    #[Doc\Endpoint('Reenviar confirmação de e-mail', 'Envia (em fila) um novo link de confirmação. 409 se o e-mail já foi confirmado. Limite de 6 requisições por minuto.')]
    #[Doc\Response('{"message":"Enviamos um novo link de confirmação para o seu e-mail."}', status: 202)]
    #[Doc\Response('{"message":"O e-mail já foi confirmado.","timestamp":"2026-10-20T10:00:00-03:00","errors":[]}', status: 409)]
    public function resend(Request $request): JsonResponse
    {
        $this->verifications->resend($request->user());

        return response()->json(['message' => 'Enviamos um novo link de confirmação para o seu e-mail.'], 202);
    }
}
