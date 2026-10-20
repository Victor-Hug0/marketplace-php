<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ListNotificationsRequest;
use App\Http\Resources\NotificationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Knuckles\Scribe\Attributes as Doc;

#[Doc\Group('Notificações')]
final class NotificationController extends Controller
{
    private const EXAMPLE = '{"id":"0b3c7a52-6f0e-4a8e-9a43-5d2f1c9e7b10","type":"order.paid","title":"Pagamento aprovado","message":"Recebemos o pagamento de R$ 1.825,80. As lojas já foram avisadas e você recebe um e-mail a cada envio, com o código de rastreio.","action_path":"/pedidos/01a159cb-1747-7071-882b-d52cc184d218","data":{"order_id":"01a159cb-1747-7071-882b-d52cc184d218","total_cents":182580},"read_at":null,"created_at":"2026-10-20T15:00:00-03:00"}';

    #[Doc\Endpoint('Minhas notificações', 'Caixa de notificações do usuário (as mesmas enviadas por e-mail), mais recentes primeiro. `type` identifica o evento (ex.: `order.paid`, `sale.paid`, `dispute.opened`); `action_path` é a rota sugerida no app cliente.')]
    #[Doc\Response('{"data":['.self::EXAMPLE.'],"links":{"first":null,"last":null,"prev":null,"next":null},"meta":{"path":"http://127.0.0.1:8080/api/v1/notifications","per_page":20,"next_cursor":null,"prev_cursor":null}}', status: 200)]
    public function index(ListNotificationsRequest $request): AnonymousResourceCollection
    {
        $query = $request->user()->notifications()->getQuery()
            ->reorder()
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($request->boolean('unread')) {
            $query->whereNull('read_at');
        }

        return NotificationResource::collection(
            $query->cursorPaginate($request->integer('per_page', 20), ['*'], 'cursor')->withQueryString(),
        );
    }

    #[Doc\Endpoint('Contar não lidas')]
    #[Doc\Response('{"data":{"unread":3}}', status: 200)]
    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json(['data' => ['unread' => $request->user()->unreadNotifications()->count()]]);
    }

    #[Doc\Endpoint('Marcar como lida', 'Idempotente. Notificação de outro usuário responde 404.')]
    #[Doc\UrlParam('notification', description: 'ID da notificação.', example: '0b3c7a52-6f0e-4a8e-9a43-5d2f1c9e7b10')]
    #[Doc\Response('{"data":'.self::EXAMPLE.'}', status: 200)]
    public function markRead(Request $request, string $notification): JsonResponse
    {
        $model = $request->user()->notifications()->whereKey($notification)->firstOrFail();
        $model->markAsRead();

        // Explicit envelope: JsonResource skips wrapping when the payload already has a "data" key.
        return response()->json(['data' => (new NotificationResource($model))->resolve($request)]);
    }

    #[Doc\Endpoint('Marcar todas como lidas')]
    #[Doc\Response('{"data":{"updated":3}}', status: 200)]
    public function markAllRead(Request $request): JsonResponse
    {
        $updated = $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['data' => ['updated' => $updated]]);
    }
}
