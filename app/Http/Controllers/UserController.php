<?php

namespace App\Http\Controllers;

use App\Http\Resources\CursorPaginatedResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\Pagination\CursorPaginates;
use Illuminate\Http\Request;
use Knuckles\Scribe\Attributes as Doc;

#[Doc\Group('Usuários')]
class UserController extends Controller
{
    use CursorPaginates;

    #[Doc\Endpoint('Usuário autenticado', 'Dados do usuário com papéis e resumo da loja, se houver.')]
    #[Doc\Response('{"user":{"id":"01a14513-4821-7084-bb71-dbc2e1d84d94","name":"Maria Compradora","email":"maria@exemplo.com","cpf":"12345678901","phone_number":"11999999999","roles":["buyer","seller"],"store":{"id":"01a14513-0e23-726f-b299-104a96511ebb","store_name":"Loja Demo","slug":"loja-demo","status":"active"},"created_at":"2026-10-20T10:00:00-03:00","updated_at":"2026-10-20T10:00:00-03:00"}}', status: 200)]
    public function me(Request $request)
    {
        $user = $request->user()->load(['roles', 'store']);

        return response()->json([
            'user' => new UserResource($user),
        ]);
    }

    #[Doc\Subgroup('Admin')]
    #[Doc\Endpoint('Listar usuários', 'Paginação por cursor. Só admin.')]
    #[Doc\ResponseFromApiResource(UserResource::class, User::class, collection: true, cursorPaginate: 20)]
    public function index(Request $request)
    {
        $users = $this->cursorPaginate(User::query(), $request);

        return CursorPaginatedResource::make($users);
    }
}
