<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\StoreStatus;
use App\Models\Store;

final class StoreDecidedNotification extends TransactionalNotification
{
    public function __construct(public readonly Store $store)
    {
        parent::__construct();
    }

    public function type(): string
    {
        return 'store.'.$this->store->status->value;
    }

    protected function title(): string
    {
        return match ($this->store->status) {
            StoreStatus::ACTIVE => 'Sua loja foi aprovada',
            StoreStatus::REJECTED => 'Sua loja não foi aprovada',
            default => 'Sua loja foi suspensa',
        };
    }

    protected function lines(): array
    {
        return match ($this->store->status) {
            StoreStatus::ACTIVE => [
                "A loja {$this->store->store_name} já pode vender: cadastre produtos e ofertas pelo painel do vendedor.",
            ],
            StoreStatus::REJECTED => [
                "A solicitação da loja {$this->store->store_name} foi recusada.",
                'Motivo: '.$this->store->rejection_reason,
            ],
            default => [
                "A loja {$this->store->store_name} foi suspensa: as ofertas saíram do ar e novos saques estão bloqueados.",
            ],
        };
    }

    protected function actionPath(): string
    {
        return '/vendedor/loja';
    }

    protected function data(): array
    {
        return [
            'store_id' => $this->store->id,
            'status' => $this->store->status->value,
        ];
    }
}
