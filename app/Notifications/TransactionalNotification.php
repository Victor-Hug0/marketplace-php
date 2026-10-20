<?php

declare(strict_types=1);

namespace App\Notifications;

use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Domain notification sent by e-mail and stored in the user's inbox (database channel).
 * Subclasses describe the message once; mail and inbox payloads are derived from it.
 */
abstract class TransactionalNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->afterCommit();
    }

    /**
     * Stable key stored in notifications.type and exposed by the API, e.g. "order.paid".
     */
    abstract public function type(): string;

    abstract protected function title(): string;

    /**
     * @return list<string>
     */
    abstract protected function lines(): array;

    /**
     * Identifiers and amounts for clients; never personal data.
     *
     * @return array<string, mixed>
     */
    abstract protected function data(): array;

    /**
     * Path in the client app (FRONTEND_URL), or null for no button.
     */
    protected function actionPath(): ?string
    {
        return null;
    }

    protected function actionText(): string
    {
        return 'Ver detalhes';
    }

    /**
     * Eager-load what the message needs: lazy loading is disabled outside production.
     */
    protected function prepare(): void {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function databaseType(object $notifiable): string
    {
        return $this->type();
    }

    public function toMail(object $notifiable): MailMessage
    {
        $this->prepare();

        $mail = (new MailMessage)
            ->subject($this->title())
            ->greeting('Olá, '.$notifiable->name.'!');

        foreach ($this->lines() as $line) {
            $mail->line($line);
        }

        if ($this->actionPath() !== null) {
            $mail->action($this->actionText(), $this->frontendUrl($this->actionPath()));
        }

        return $mail->salutation('Equipe '.config('app.name'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $this->prepare();

        return [
            'title' => $this->title(),
            'message' => implode(' ', $this->lines()),
            'action_path' => $this->actionPath(),
            'data' => $this->data(),
        ];
    }

    protected function frontendUrl(string $path): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/'.ltrim($path, '/');
    }

    protected function dateTime(?CarbonInterface $value): string
    {
        return $value?->timezone(config('app.timezone'))->format('d/m/Y \à\s H:i') ?? '-';
    }
}
