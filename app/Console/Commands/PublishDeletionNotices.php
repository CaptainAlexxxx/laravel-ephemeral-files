<?php

namespace App\Console\Commands;

use App\Models\DeletionNotice;
use App\Notifications\FileDeletedNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class PublishDeletionNotices extends Command
{
    protected $signature = 'notices:publish';

    protected $description = 'Publish unpublished deletion notices to RabbitMQ.';

    public function handle(): int
    {
        $recipient = config('files.notify_email');
        $count = 0;

        DeletionNotice::query()->whereNull('published_at')->chunkById(100, function ($notices) use ($recipient, &$count): void {
            foreach ($notices as $notice) {
                // One short transaction per notice. "for update skip locked" makes a concurrent run pass over rows
                // this run holds, and the whereNull recheck skips rows it already finished.
                // Delivery is at-least-once: if the process dies after the push but before commit,
                // the row stays unpublished and the notice is sent again next run.
                // No try/catch: a broker error is systemic, the run stops and the next one retries.
                $count += DB::transaction(function () use ($notice, $recipient): int {
                    $locked = DeletionNotice::query()
                        ->whereKey($notice->getKey())
                        ->whereNull('published_at')
                        ->lock('for update skip locked')
                        ->first();

                    if ($locked === null) {
                        return 0;
                    }

                    Notification::route('mail', $recipient)->notify(new FileDeletedNotification(
                        originalName: $locked->original_name,
                        size: $locked->size,
                        reason: $locked->reason,
                        uploadedAt: $locked->uploaded_at,
                        deletedAt: $locked->deleted_at,
                    ));

                    $locked->update(['published_at' => now()]);

                    return 1;
                });
            }
        });

        $this->info("Published {$count} deletion notice(s).");

        return self::SUCCESS;
    }
}
