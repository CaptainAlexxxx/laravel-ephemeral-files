<?php

namespace App\Services;

use App\Enums\DeletionReason;
use App\Models\StoredFile;
use App\Notifications\FileDeletedNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
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

            // Published inside the transaction on purpose: if the broker is down this throws and the
            // delete rolls back, so it can be retried. Publishing after commit would lose the notice.
            Notification::route('mail', $recipient)->notify(new FileDeletedNotification(
                originalName: $file->original_name,
                size: $file->size,
                reason: $reason,
                uploadedAt: $file->created_at->toImmutable(),
                deletedAt: CarbonImmutable::now(),
            ));

            return true;
        });

        if (! $deleted) {
            return false;
        }

        // After commit so a rolled back delete never loses the file. The row is gone and the
        // notice is sent by now, so a leftover file is only worth a log entry.
        if (! Storage::disk(config('files.disk'))->delete($file->path)) {
            Log::error('Failed to delete stored file from disk.', [
                'file_id' => $file->getKey(),
                'path' => $file->path,
            ]);
        }

        return true;
    }
}
