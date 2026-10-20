<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Product;

final class ProductSubmittedNotification extends TransactionalNotification
{
    public function __construct(public readonly Product $product)
    {
        parent::__construct();
    }

    public function type(): string
    {
        return 'product.submitted';
    }

    protected function title(): string
    {
        return 'Produto aguardando moderação';
    }

    protected function lines(): array
    {
        return ["O produto {$this->product->name} foi enviado para moderação."];
    }

    protected function actionPath(): string
    {
        return "/admin/produtos/{$this->product->id}";
    }

    protected function actionText(): string
    {
        return 'Moderar produto';
    }

    protected function data(): array
    {
        return ['product_id' => $this->product->id];
    }
}
