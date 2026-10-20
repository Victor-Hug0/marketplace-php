<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\OrderSellerStatus;
use App\Models\AuditLog;
use App\Models\OrderItem;
use App\Models\OrderSeller;
use App\Models\ProductReview;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class ProductReviewService
{
    /**
     * @param  array{rating: int, title?: string|null, body?: string|null}  $data
     */
    public function create(User $author, OrderItem $orderItem, array $data): ProductReview
    {
        try {
            return DB::transaction(function () use ($author, $orderItem, $data): ProductReview {
                $orderSeller = OrderSeller::query()
                    ->with(['order:id,buyer_id', 'store:id,user_id'])
                    ->lockForUpdate()
                    ->findOrFail($orderItem->order_seller_id);

                if ($orderSeller->order->buyer_id !== $author->id) {
                    throw new AccessDeniedHttpException('Apenas quem comprou o item pode avaliá-lo.');
                }

                if ($orderSeller->store->user_id === $author->id) {
                    throw new AccessDeniedHttpException('Vendedores não podem avaliar produtos da própria loja.');
                }

                if ($orderSeller->status !== OrderSellerStatus::DELIVERED) {
                    throw new ConflictHttpException('Só é possível avaliar itens de pedidos entregues.');
                }

                if (ProductReview::query()->where('order_item_id', $orderItem->id)->exists()) {
                    throw new ConflictHttpException('Este item já foi avaliado.');
                }

                $review = ProductReview::query()->create([
                    'order_item_id' => $orderItem->id,
                    'user_id' => $author->id,
                    'product_id' => $orderItem->product_id,
                    'store_id' => $orderItem->store_id,
                    'rating' => $data['rating'],
                    'title' => $data['title'] ?? null,
                    'body' => $data['body'] ?? null,
                ]);

                $this->audit($author, $review);

                return $review->load('author:id,name');
            });
        } catch (UniqueConstraintViolationException) {
            throw new ConflictHttpException('Este item já foi avaliado.');
        }
    }

    /**
     * @return array{average: float|null, count: int}
     */
    public function productSummary(string $productId): array
    {
        $row = ProductReview::query()
            ->where('product_id', $productId)
            ->selectRaw('count(*) as total, avg(rating) as average')
            ->first();

        return self::summary($row?->average, (int) ($row?->total ?? 0));
    }

    /**
     * @return array{average: float|null, count: int}
     */
    public static function summary(mixed $average, int $count): array
    {
        return [
            'average' => $average === null ? null : round((float) $average, 2),
            'count' => $count,
        ];
    }

    private function audit(User $author, ProductReview $review): void
    {
        AuditLog::query()->create([
            'action' => 'product_review.created',
            'actor_id' => $author->id,
            'actor_type' => $author->getMorphClass(),
            'auditable_id' => $review->id,
            'auditable_type' => $review->getMorphClass(),
            'payload' => [
                'order_item_id' => $review->order_item_id,
                'product_id' => $review->product_id,
                'rating' => $review->rating,
            ],
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'created_at' => now(),
        ]);
    }
}
