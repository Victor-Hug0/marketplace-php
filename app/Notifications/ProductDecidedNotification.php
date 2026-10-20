<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\ProductStatus;
use App\Models\Product;

final class ProductDecidedNotification extends TransactionalNotification
{
    public function __construct(public readonly Product $product)
    {
        parent::__construct();
    }

    public function type(): string
    {
        return $this->approved() ? 'product.approved' : 'product.rejected';
    }

    protected function title(): string
    {
        return $this->approved() ? 'Produto aprovado' : 'Produto recusado na moderação';
    }

    protected function lines(): array
    {
        return $this->approved()
            ? ["O produto {$this->product->name} foi aprovado e já aparece no catálogo para quem tiver oferta ativa."]
            : [
                "O produto {$this->product->name} foi recusado.",
                'Motivo: '.$this->product->rejection_reason,
                'Corrija e envie de novo para moderação.',
            ];
    }

    protected function actionPath(): string
    {
        return "/vendedor/produtos/{$this->product->id}";
    }

    protected function data(): array
    {
        return [
            'product_id' => $this->product->id,
            'status' => $this->product->status->value,
        ];
    }

    private function approved(): bool
    {
        return $this->product->status === ProductStatus::ACTIVE;
    }
}
