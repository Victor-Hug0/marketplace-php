<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Store;

final class StoreRequestedNotification extends TransactionalNotification
{
    public function __construct(public readonly Store $store)
    {
        parent::__construct();
    }

    public function type(): string
    {
        return 'store.requested';
    }

    protected function title(): string
    {
        return 'Nova loja aguardando aprovação';
    }

    protected function lines(): array
    {
        return ["A loja {$this->store->store_name} pediu para vender no marketplace."];
    }

    protected function actionPath(): string
    {
        return "/admin/lojas/{$this->store->id}";
    }

    protected function actionText(): string
    {
        return 'Analisar loja';
    }

    protected function data(): array
    {
        return ['store_id' => $this->store->id];
    }
}
