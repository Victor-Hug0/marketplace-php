<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\DisputeReason;
use App\Enums\OfferStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PaymentWebhookEventType;
use App\Enums\ProductStatus;
use App\Enums\RoleName;
use App\Models\Address;
use App\Models\Offer;
use App\Models\Order;
use App\Models\OrderSeller;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\AddressService;
use App\Services\CartService;
use App\Services\CheckoutService;
use App\Services\DisputeService;
use App\Services\FulfillmentService;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\PayoutService;
use App\Services\ProductReviewService;
use App\Services\ProductService;
use App\Services\ReturnRequestService;
use App\Services\StoreService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Demo data for local use: admin, buyer, a pending store and orders in every
 * stage of the journey, built through the real services so stock, ledger and
 * status history stay consistent. Never runs in production.
 */
final class DemoSeeder extends Seeder
{
    public const PASSWORD = 'Password1!';

    private const BUYER_CEP = '01311000';

    public function __construct(
        private readonly AddressService $addresses,
        private readonly CartService $carts,
        private readonly CheckoutService $checkout,
        private readonly PaymentService $payments,
        private readonly FulfillmentService $fulfillment,
        private readonly OrderService $orders,
        private readonly ProductReviewService $reviews,
        private readonly DisputeService $disputes,
        private readonly ReturnRequestService $returns,
        private readonly StoreService $stores,
        private readonly ProductService $products,
        private readonly PayoutService $payouts,
    ) {}

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('DemoSeeder ignorado: não roda em produção.');

            return;
        }

        $this->call([
            PlatformFeeRuleSeeder::class,
            ShippingRateRuleSeeder::class,
            CategorySeeder::class,
            ProductSeeder::class,
            OfferSeeder::class,
        ]);

        // Payment webhooks must be applied now, not left for the queue worker.
        $queue = config('queue.default');
        config(['queue.default' => 'sync']);

        try {
            $this->seed();
        } finally {
            config(['queue.default' => $queue]);
        }
    }

    private function seed(): void
    {
        $this->user('admin@marketplace.test', 'Admin Demo', '86288366757', [RoleName::BUYER, RoleName::ADMIN]);
        $buyer = $this->user('comprador@marketplace.test', 'Comprador Demo', '71428793860', [RoleName::BUYER]);

        $demoStore = Store::query()->where('slug', 'loja-demo')->firstOrFail();
        $rivalStore = Store::query()->where('slug', 'loja-rival-demo')->firstOrFail();
        $demoStore->update(['origin_cep' => $demoStore->origin_cep ?? '01310100']);
        $rivalStore->update(['origin_cep' => $rivalStore->origin_cep ?? '30130010']);

        $this->pendingStoreRequest();
        $this->productAwaitingModeration($demoStore);

        $smartphoneDemo = $this->offer('smartphone-demo-128gb', $demoStore);
        $smartphoneRival = $this->offer('smartphone-demo-128gb', $rivalStore);
        $notebookDemo = $this->notebookOffer($demoStore);

        $address = $this->buyerAddress($buyer);

        $this->scenario($buyer, $address, 'demo-aguardando-pagamento', [$smartphoneRival->id => 1], function (Order $order) use ($buyer): void {
            $this->payments->createIntent($buyer, $order, PaymentMethod::BOLETO, 'demo-pagamento-boleto');
        });

        $this->scenario($buyer, $address, 'demo-pago', [$smartphoneRival->id => 1, $notebookDemo->id => 1], function (Order $order) use ($buyer): void {
            $this->pay($buyer, $order);
        });

        $this->scenario($buyer, $address, 'demo-enviado', [$notebookDemo->id => 1], function (Order $order) use ($buyer): void {
            $this->pay($buyer, $order);
            $this->shipAll($order);
        });

        $this->scenario($buyer, $address, 'demo-entregue-avaliado', [$smartphoneDemo->id => 1], function (Order $order) use ($buyer): void {
            $this->pay($buyer, $order);
            $this->shipAll($order, deliver: true);

            $item = $this->subOrders($order)->firstOrFail()->items()->firstOrFail();
            $this->reviews->create($buyer, $item, [
                'rating' => 5,
                'title' => 'Ótimo custo-benefício',
                'body' => 'Chegou antes do prazo e bem embalado.',
            ]);
        });

        $this->scenario($buyer, $address, 'demo-em-disputa', [$smartphoneRival->id => 1], function (Order $order) use ($buyer): void {
            $this->pay($buyer, $order);
            $this->shipAll($order, deliver: true);

            $this->disputes->open(
                $buyer,
                $this->subOrders($order)->firstOrFail(),
                DisputeReason::NOT_AS_DESCRIBED,
                'A cor do aparelho é diferente da anunciada.',
            );
        });

        $this->scenario($buyer, $address, 'demo-devolucao-pendente', [$notebookDemo->id => 1], function (Order $order) use ($buyer): void {
            $this->pay($buyer, $order);
            $this->shipAll($order, deliver: true);

            $this->returns->request(
                $buyer,
                $this->subOrders($order)->firstOrFail(),
                'O notebook não atendeu ao que eu precisava.',
                null,
            );
        });

        $this->scenario($buyer, $address, 'demo-cancelado', [$smartphoneRival->id => 1], function (Order $order) use ($buyer): void {
            $this->orders->cancelByBuyer($buyer, $order, 'Comprei por engano.');
        });

        $this->payouts->request($rivalStore->owner, 50000, 'demo-saque-loja-rival');
    }

    /**
     * @param  list<RoleName>  $roles
     */
    private function user(string $email, string $name, string $cpf, array $roles): User
    {
        $user = User::query()->firstOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make(self::PASSWORD),
                'cpf' => $cpf,
                'phone_number' => '11955554444',
            ],
        );

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        $user->roles()->syncWithoutDetaching(
            collect($roles)->map(fn (RoleName $role): int => Role::query()->firstOrCreate(['name' => $role])->id)->all(),
        );

        return $user;
    }

    private function pendingStoreRequest(): void
    {
        $applicant = $this->user('candidato@marketplace.test', 'Candidato Demo', '15350946056', [RoleName::BUYER]);

        if ($applicant->store()->withTrashed()->exists()) {
            return;
        }

        $this->stores->requestStore($applicant, [
            'store_name' => 'Loja Candidata Demo',
            'description' => 'Loja aguardando aprovação do admin.',
            'commercial_email' => 'contato@lojacandidata.test',
            'commercial_phone' => '11944443333',
            'origin_cep' => '80010-000',
            'document_type' => 'cnpj',
            'document' => '45723174000110',
        ]);
    }

    private function productAwaitingModeration(Store $store): void
    {
        $product = Product::query()->where('slug', 'fone-bluetooth-demo')->first();

        if ($product !== null && $product->status === ProductStatus::DRAFT) {
            $this->products->submitForModeration($store->owner, $product);
        }
    }

    private function offer(string $productSlug, Store $store): Offer
    {
        return Offer::query()
            ->where('store_id', $store->id)
            ->whereHas('product', fn ($query) => $query->where('slug', $productSlug))
            ->firstOrFail();
    }

    private function notebookOffer(Store $store): Offer
    {
        $notebook = Product::query()->where('slug', 'notebook-demo-16gb')->firstOrFail();

        $notebook->update([
            'weight_grams' => $notebook->weight_grams ?? 2200,
            'length_cm' => $notebook->length_cm ?? 40,
            'width_cm' => $notebook->width_cm ?? 30,
            'height_cm' => $notebook->height_cm ?? 6,
        ]);

        return Offer::query()->firstOrCreate(
            ['product_id' => $notebook->id, 'store_id' => $store->id],
            [
                'price' => '4599.00',
                'stock' => 15,
                'reserved_stock' => 0,
                'status' => OfferStatus::ACTIVE,
            ],
        );
    }

    private function buyerAddress(User $buyer): Address
    {
        return Address::query()->where('user_id', $buyer->id)->first()
            ?? $this->addresses->create($buyer, [
                'label' => 'Casa',
                'recipient_name' => 'Comprador Demo',
                'phone' => '11955554444',
                'cep' => self::BUYER_CEP,
                'street' => 'Avenida Paulista',
                'number' => '1000',
                'district' => 'Bela Vista',
                'city' => 'São Paulo',
                'state' => 'SP',
                'is_default' => true,
            ]);
    }

    /**
     * Builds one order from an empty cart and advances it with $then.
     * Skipped when an order with this idempotency key already exists.
     *
     * @param  array<string, int>  $items  offer id => quantity
     */
    private function scenario(User $buyer, Address $address, string $key, array $items, callable $then): void
    {
        $exists = Order::query()
            ->where('buyer_id', $buyer->id)
            ->where('idempotency_key', $key)
            ->exists();

        if ($exists) {
            return;
        }

        $this->carts->getOrCreateCart($buyer);
        $this->carts->clear($buyer);

        foreach ($items as $offerId => $quantity) {
            $this->carts->addItem($buyer, $offerId, $quantity);
        }

        $order = $this->checkout->checkout($buyer, $address->id, $key)['order'];

        $then($order);
    }

    private function pay(User $buyer, Order $order): void
    {
        $payment = $this->payments->createIntent($buyer, $order, PaymentMethod::PIX, "{$order->idempotency_key}-pix")['payment'];

        $event = $this->payments->receiveMockWebhook(
            "evt_{$order->idempotency_key}",
            PaymentWebhookEventType::PAYMENT_PAID,
            $payment->external_id,
            ['source' => 'DemoSeeder'],
        )['event'];

        $this->payments->processWebhookEvent($event->id);

        if (Payment::query()->findOrFail($payment->id)->status !== PaymentStatus::PAID) {
            throw new \RuntimeException("Pagamento do pedido {$order->idempotency_key} não foi confirmado.");
        }
    }

    private function shipAll(Order $order, bool $deliver = false): void
    {
        foreach ($this->subOrders($order)->with('store.owner')->get() as $subOrder) {
            $seller = $subOrder->store->owner;
            $this->fulfillment->ship($seller, $subOrder);

            if ($deliver) {
                $this->fulfillment->markDelivered($seller, $subOrder);
            }
        }
    }

    private function subOrders(Order $order): Builder
    {
        return OrderSeller::query()->where('order_id', $order->id)->orderBy('id');
    }
}
