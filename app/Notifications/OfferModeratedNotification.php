<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Offer;

final class OfferModeratedNotification extends TransactionalNotification
{
    public function __construct(public readonly Offer $offer)
    {
        parent::__construct();
    }

    public function type(): string
    {
        return $this->paused() ? 'offer.admin_paused' : 'offer.admin_resumed';
    }

    protected function prepare(): void
    {
        $this->offer->loadMissing('product:id,name');
    }

    protected function title(): string
    {
        return $this->paused() ? 'Oferta pausada pela moderação' : 'Oferta reativada pela moderação';
    }

    protected function lines(): array
    {
        $name = $this->offer->product->name;

        return $this->paused()
            ? [
                "Sua oferta do produto {$name} foi pausada pela moderação e saiu da venda.",
                'Motivo: '.$this->offer->admin_pause_reason,
                'Só um administrador pode reativá-la.',
            ]
            : ["Sua oferta do produto {$name} foi reativada e voltou a ser vendida."];
    }

    protected function actionPath(): string
    {
        return "/vendedor/ofertas/{$this->offer->id}";
    }

    protected function data(): array
    {
        return [
            'offer_id' => $this->offer->id,
            'product_id' => $this->offer->product_id,
            'status' => $this->offer->status->value,
        ];
    }

    private function paused(): bool
    {
        return $this->offer->isAdminPaused();
    }
}
