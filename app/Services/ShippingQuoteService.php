<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\ShippingOption;
use App\DTOs\StoreShippingQuote;
use App\Enums\ShippingZone;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\ShippingRateRule;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class ShippingQuoteService
{
    /**
     * Quotes the user's current cart, one quote per seller.
     *
     * @return Collection<int, StoreShippingQuote>
     */
    public function quoteCart(User $user, string $destinationCep): Collection
    {
        $cart = Cart::query()->where('user_id', $user->id)->first();

        $items = $cart === null
            ? collect()
            : CartItem::query()
                ->where('cart_id', $cart->id)
                ->with(['offer.store', 'offer.product'])
                ->get();

        if ($items->isEmpty()) {
            throw new ConflictHttpException('Carrinho vazio. Adicione itens antes de cotar o frete.');
        }

        return $items
            ->groupBy(static fn (CartItem $item): string => (string) $item->offer->store_id)
            ->map(fn (Collection $storeItems): StoreShippingQuote => $this->quoteStore(
                $storeItems->first()->offer->store,
                $storeItems->map(static fn (CartItem $item): array => [
                    'product' => $item->offer->product,
                    'quantity' => $item->quantity,
                ]),
                $destinationCep,
            ))
            ->values();
    }

    /**
     * @param  iterable<array{product: Product, quantity: int}>  $lines
     */
    public function quoteStore(Store $store, iterable $lines, string $destinationCep): StoreShippingQuote
    {
        $originCep = $this->digits($store->origin_cep ?? (string) config('shipping.default_origin_cep'));
        $destinationCep = $this->digits($destinationCep);
        $zone = ShippingZone::between($originCep, $destinationCep);

        $billableWeightGrams = 0;
        foreach ($lines as $line) {
            $billableWeightGrams += $this->billableWeightGrams($line['product']) * $line['quantity'];
        }

        $options = ShippingRateRule::query()
            ->where('zone', $zone)
            ->where('is_active', true)
            ->where('max_weight_grams', '>=', $billableWeightGrams)
            ->orderBy('max_weight_grams')
            ->get()
            ->unique('service_code')
            ->sortBy('price_cents')
            ->map(static fn (ShippingRateRule $rule): ShippingOption => new ShippingOption(
                serviceCode: $rule->service_code,
                serviceName: $rule->service_name,
                priceCents: $rule->price_cents,
                etaDays: $rule->eta_days,
            ))
            ->values()
            ->all();

        return new StoreShippingQuote(
            store: $store,
            zone: $zone,
            originCep: $originCep,
            destinationCep: $destinationCep,
            billableWeightGrams: $billableWeightGrams,
            options: $options,
        );
    }

    /**
     * The heavier of the real weight and the volumetric weight, per unit.
     */
    public function billableWeightGrams(Product $product): int
    {
        $default = config('shipping.default_package');

        $weightGrams = $product->weight_grams ?? $default['weight_grams'];
        $volumeCm3 = ($product->length_cm ?? $default['length_cm'])
            * ($product->width_cm ?? $default['width_cm'])
            * ($product->height_cm ?? $default['height_cm']);

        $volumetricGrams = (int) ceil($volumeCm3 * 1000 / config('shipping.volumetric_divisor'));

        return max($weightGrams, $volumetricGrams);
    }

    private function digits(string $cep): string
    {
        return preg_replace('/\D+/', '', $cep) ?? '';
    }
}
