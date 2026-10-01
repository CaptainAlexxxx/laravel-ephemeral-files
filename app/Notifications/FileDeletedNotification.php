<?php

namespace App\Notifications;

use App\Enums\DeletionReason;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Number;

/**
 * Carries plain values, not the model: the row is gone by the time the
 * worker runs, and SerializesModels would fail re-fetching it.
 */
class FileDeletedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $originalName,
        public readonly int $size,
        public readonly DeletionReason $reason,
        public readonly CarbonImmutable $uploadedAt,
        public readonly CarbonImmutable $deletedAt,
    ) {
        // explicit so the RabbitMQ requirement does not depend on QUEUE_CONNECTION
        $this->onConnection('rabbitmq');
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $format = 'Y-m-d H:i:s \U\T\C';

        return (new MailMessage)
            ->subject('File deleted: '.$this->originalName)
            ->line('File: '.$this->originalName)
            ->line('Size: '.Number::fileSize($this->size, 1))
            ->line('Reason: '.match ($this->reason) {
                DeletionReason::Manual => 'Deleted manually',
                DeletionReason::Expired => 'Expired and deleted automatically',
            })
            ->line('Uploaded at: '.$this->uploadedAt->utc()->format($format))
            ->line('Deleted at: '.$this->deletedAt->utc()->format($format));
    }
}
