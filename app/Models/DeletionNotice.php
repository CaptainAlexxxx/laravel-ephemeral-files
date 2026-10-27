<?php

namespace App\Models;

use App\Enums\DeletionReason;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * Outbox row: written in the delete transaction, published to RabbitMQ by notices:publish.
 */
#[Fillable(['original_name', 'size', 'reason', 'recipient', 'uploaded_at', 'deleted_at', 'published_at'])]
class DeletionNotice extends Model
{
    use MassPrunable;

    /**
     * Only published rows: an unpublished one still has to be sent.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('published_at', '<=', now()->subDays(config('files.notice_retention_days')));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'reason' => DeletionReason::class,
            'uploaded_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
            'published_at' => 'datetime',
        ];
    }
}
