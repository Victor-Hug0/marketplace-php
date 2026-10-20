<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\DisputeReason;
use App\Models\Dispute;

final class DisputeOpenedNotification extends TransactionalNotification
{
    public const FOR_SELLER = 'seller';

    public const FOR_ADMIN = 'admin';

    public function __construct(
        public readonly Dispute $dispute,
        public readonly string $audience,
    ) {
        parent::__construct();
    }

    public function type(): string
    {
        return 'dispute.opened';
    }

    protected function prepare(): void
    {
        $this->dispute->loadMissing('orderSeller.store');
    }

    protected function title(): string
    {
        return $this->audience === self::FOR_ADMIN ? 'Nova disputa para mediar' : 'Um comprador abriu uma disputa';
    }

    protected function lines(): array
    {
        $store = $this->dispute->orderSeller->store->store_name;
        $reason = self::reasonLabel($this->dispute->reason);

        return $this->audience === self::FOR_ADMIN
            ? [
                "Disputa aberta contra a loja {$store}. Motivo: {$reason}.",
                'O saldo desse subpedido fica retido até a decisão.',
            ]
            : [
                "Motivo informado: {$reason}.",
                'Responda pelo painel do vendedor; o valor da venda fica retido até a mediação do marketplace.',
            ];
    }

    protected function actionPath(): string
    {
        return $this->audience === self::FOR_ADMIN
            ? "/admin/disputas/{$this->dispute->id}"
            : "/vendedor/disputas/{$this->dispute->id}";
    }

    protected function actionText(): string
    {
        return $this->audience === self::FOR_ADMIN ? 'Mediar disputa' : 'Responder disputa';
    }

    protected function data(): array
    {
        return [
            'dispute_id' => $this->dispute->id,
            'order_seller_id' => $this->dispute->order_seller_id,
            'reason' => $this->dispute->reason->value,
        ];
    }

    public static function reasonLabel(DisputeReason $reason): string
    {
        return match ($reason) {
            DisputeReason::NOT_RECEIVED => 'produto não recebido',
            DisputeReason::NOT_AS_DESCRIBED => 'diferente do anunciado',
            DisputeReason::DAMAGED => 'produto danificado',
            DisputeReason::OTHER => 'outro',
        };
    }
}
