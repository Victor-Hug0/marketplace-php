<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\Dispute;
use App\Models\Order;
use App\Models\OrderSeller;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\Product;
use App\Models\ReturnRequest;
use App\Models\Store;
use App\Models\User;
use App\Notifications\DisputeOpenedNotification;
use App\Notifications\DisputeResolvedNotification;
use App\Notifications\NewSaleNotification;
use App\Notifications\OrderCancelledNotification;
use App\Notifications\OrderPaidNotification;
use App\Notifications\OrderPlacedNotification;
use App\Notifications\OrderSellerCancelledNotification;
use App\Notifications\OrderSellerDeliveredNotification;
use App\Notifications\OrderSellerShippedNotification;
use App\Notifications\PaymentInstructionsNotification;
use App\Notifications\PayoutProcessedNotification;
use App\Notifications\ProductDecidedNotification;
use App\Notifications\ProductSubmittedNotification;
use App\Notifications\ReturnRequestDecidedNotification;
use App\Notifications\ReturnRequestedNotification;
use App\Notifications\StoreDecidedNotification;
use App\Notifications\StoreRequestedNotification;
use App\Notifications\TransactionalNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use ReflectionClass;
use Symfony\Component\Mailer\SentMessage;
use Tests\TestCase;

final class NotificationContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_transactional_notification_is_queued_after_commit_on_mail_and_database(): void
    {
        $instances = [
            new OrderPlacedNotification(new Order),
            new PaymentInstructionsNotification(new Payment),
            new OrderPaidNotification(new Order),
            new NewSaleNotification(new OrderSeller),
            new OrderSellerShippedNotification(new OrderSeller),
            new OrderSellerDeliveredNotification(new OrderSeller),
            new OrderCancelledNotification(new Order, OrderCancelledNotification::CAUSE_BUYER),
            new OrderSellerCancelledNotification(new OrderSeller),
            new DisputeOpenedNotification(new Dispute, DisputeOpenedNotification::FOR_SELLER),
            new DisputeResolvedNotification(new Dispute, DisputeResolvedNotification::FOR_BUYER),
            new ReturnRequestedNotification(new ReturnRequest),
            new ReturnRequestDecidedNotification(new ReturnRequest),
            new PayoutProcessedNotification(new Payout),
            new StoreRequestedNotification(new Store),
            new StoreDecidedNotification(new Store),
            new ProductSubmittedNotification(new Product),
            new ProductDecidedNotification(new Product),
        ];

        $covered = array_map(fn (TransactionalNotification $n): string => $n::class, $instances);
        $this->assertEqualsCanonicalizing($this->transactionalNotificationClasses(), $covered, 'Add new notifications to this contract test.');

        foreach ($instances as $notification) {
            $this->assertInstanceOf(ShouldQueue::class, $notification, $notification::class);
            $this->assertTrue($notification->afterCommit, $notification::class.' must be queued after commit.');
            $this->assertSame(['mail', 'database'], $notification->via(new User), $notification::class);
        }
    }

    public function test_mail_renders_in_portuguese_including_the_layout(): void
    {
        config(['mail.default' => 'array']);
        $order = Order::factory()->create();

        $order->buyer->notify(new OrderPaidNotification($order));

        /** @var SentMessage $sent */
        $sent = Mail::mailer('array')->getSymfonyTransport()->messages()->last();
        $html = $sent->getOriginalMessage()->getHtmlBody();

        $this->assertStringContainsString('Pagamento aprovado', $sent->getOriginalMessage()->getSubject());
        $this->assertStringContainsString('Todos os direitos reservados.', $html);
        $this->assertStringContainsString('Se o botão', $html);
        $this->assertStringNotContainsString('All rights reserved', $html);
        $this->assertStringNotContainsString('having trouble', $html);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $order->buyer_id, 'type' => 'order.paid']);
    }

    /**
     * @return list<class-string>
     */
    private function transactionalNotificationClasses(): array
    {
        $classes = [];

        foreach (glob(app_path('Notifications/*.php')) as $file) {
            $class = 'App\\Notifications\\'.basename($file, '.php');
            $reflection = new ReflectionClass($class);

            if (! $reflection->isAbstract() && $reflection->isSubclassOf(TransactionalNotification::class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }
}
