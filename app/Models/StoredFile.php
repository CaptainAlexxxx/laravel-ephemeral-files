<?php

namespace App\Models;

use Database\Factories\StoredFileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['original_name', 'path', 'mime_type', 'size', 'expires_at'])]
class StoredFile extends Model
{
    /** @use HasFactory<StoredFileFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'size' => 'integer',
        ];
    }

    /**
     * Scope a query to files whose expiry has passed.
     */
    public function scopeExpired(Builder $query): Builder
    {
        // a file exactly at its TTL counts as expired
        return $query->where('expires_at', '<=', now());
    }
}
