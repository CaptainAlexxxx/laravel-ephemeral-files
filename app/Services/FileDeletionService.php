<?php

namespace App\Services;

use App\Enums\DeletionReason;
use App\Models\DeletionNotice;
use App\Models\StoredFile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * The single place a stored file is deleted.
 */
final class FileDeletionService
{
    /**
     * Returns false when the file was already deleted by someone else.
     */
    public function delete(StoredFile $file, DeletionReason $reason): bool
    {
        $recipient = config('files.notify_email');

        if (blank($recipient)) {
            throw new RuntimeException('NOTIFY_EMAIL is not configured, refusing to delete without a notification.');
        }

        $deleted = DB::transaction(function () use ($file, $reason, $recipient): bool {
            // Conditional delete instead of $file->delete(): when a manual delete and the purge race,
            // the loser blocks on the row lock until the winner commits, then sees 0 rows and stays silent.
            $affected = StoredFile::query()->whereKey($file->getKey())->delete();

            if ($affected === 0) {
                return false;
            }

            // Outbox row instead of a broker call: no network I/O under the row lock, and the notice
            // commits or rolls back together with the delete. notices:publish sends it afterwards.
            DeletionNotice::query()->create([
                'original_name' => $file->original_name,
                'size' => $file->size,
                'reason' => $reason,
                'recipient' => $recipient,
                'uploaded_at' => $file->created_at,
                'deleted_at' => CarbonImmutable::now(),
            ]);

            return true;
        });

        if (! $deleted) {
            return false;
        }

        // After commit so a rolled back delete never loses the file. The row and the notice
        // are committed by now, so a leftover file is only worth a log entry.
        if (! Storage::disk(config('files.disk'))->delete($file->path)) {
            Log::error('Failed to delete stored file from disk.', [
                'file_id' => $file->getKey(),
                'path' => $file->path,
            ]);
        }

        return true;
    }
}
