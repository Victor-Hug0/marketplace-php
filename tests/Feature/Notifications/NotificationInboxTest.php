<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\Order;
use App\Models\User;
use App\Notifications\OrderPaidNotification;
use App\Notifications\OrderPlacedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class NotificationInboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_lists_own_notifications_newest_first(): void
    {
        [$user, $order] = $this->userWithOrder();
        $user->notify(new OrderPlacedNotification($order));
        $this->travel(1)->minute();
        $user->notify(new OrderPaidNotification($order));
        [$other, $otherOrder] = $this->userWithOrder();
        $other->notify(new OrderPaidNotification($otherOrder));

        $this->withToken($this->accessToken($user))
            ->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.type', 'order.paid')
            ->assertJsonPath('data.0.title', 'Pagamento aprovado')
            ->assertJsonPath('data.0.data.order_id', $order->id)
            ->assertJsonPath('data.0.action_path', "/pedidos/{$order->id}")
            ->assertJsonPath('data.0.read_at', null)
            ->assertJsonPath('data.1.type', 'order.placed');
    }

    public function test_unread_filter_count_and_mark_as_read(): void
    {
        [$user, $order] = $this->userWithOrder();
        $user->notify(new OrderPlacedNotification($order));
        $user->notify(new OrderPaidNotification($order));
        $token = $this->accessToken($user);
        $first = $user->notifications()->firstOrFail();

        $this->withToken($token)->getJson('/api/v1/notifications/unread-count')->assertOk()->assertJsonPath('data.unread', 2);

        foreach ([1, 2] as $attempt) {
            $this->withToken($token)
                ->postJson("/api/v1/notifications/{$first->id}/read")
                ->assertOk()
                ->assertJsonPath('data.id', $first->id);
        }
        $this->assertNotNull($first->fresh()->read_at);

        $this->withToken($token)->getJson('/api/v1/notifications?unread=1')->assertOk()->assertJsonCount(1, 'data');

        $this->withToken($token)->postJson('/api/v1/notifications/read-all')->assertOk()->assertJsonPath('data.updated', 1);
        $this->withToken($token)->getJson('/api/v1/notifications/unread-count')->assertOk()->assertJsonPath('data.unread', 0);
    }

    public function test_cannot_read_another_users_notification(): void
    {
        [$owner, $order] = $this->userWithOrder();
        $owner->notify(new OrderPaidNotification($order));
        $notification = $owner->notifications()->firstOrFail();
        [$intruder] = $this->userWithOrder();

        $this->withToken($this->accessToken($intruder))
            ->postJson("/api/v1/notifications/{$notification->id}/read")
            ->assertNotFound();
        $this->withToken($this->accessToken($intruder))
            ->postJson('/api/v1/notifications/'.Str::uuid().'/read')
            ->assertNotFound();

        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_inbox_requires_authentication(): void
    {
        $this->getJson('/api/v1/notifications')->assertUnauthorized();
        $this->getJson('/api/v1/notifications/unread-count')->assertUnauthorized();
    }

    /**
     * @return array{User, Order}
     */
    private function userWithOrder(): array
    {
        $order = Order::factory()->create();

        return [$order->buyer, $order];
    }

    private function accessToken(User $user): string
    {
        $this->app['auth']->forgetGuards();

        return $user->createToken('access_token', ['access'], now()->addHour())->plainTextToken;
    }
}
