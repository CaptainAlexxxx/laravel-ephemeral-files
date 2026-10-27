<?php

namespace App\Models;

use App\Enums\DeletionReason;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Outbox row: written in the delete transaction, published to RabbitMQ by notices:publish.
 */
#[Fillable(['original_name', 'size', 'reason', 'recipient', 'uploaded_at', 'deleted_at', 'published_at'])]
class DeletionNotice extends Model
{
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
