<?php

namespace App\Console\Commands;

use App\Models\DeletionNotice;
use App\Notifications\FileDeletedNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use PDOException;
use PhpAmqpLib\Exception\AMQPExceptionInterface;
use RuntimeException;
use Throwable;

class PublishDeletionNotices extends Command
{
    protected $signature = 'notices:publish';

    protected $description = 'Publish unpublished deletion notices to RabbitMQ.';

    public function handle(): int
    {
        $count = 0;
        $maxAttempts = (int) config('files.notice_max_attempts');

        DeletionNotice::query()
            ->whereNull('published_at')
            ->where('attempts', '<', $maxAttempts)
            ->chunkById(100, function ($notices) use (&$count): void {
                foreach ($notices as $notice) {
                    try {
                        $count += $this->publish($notice);
                    } catch (Throwable $e) {
                        // A broker or DB outage hits every row: stop and let the next run retry.
                        if ($e instanceof AMQPExceptionInterface || $e instanceof PDOException) {
                            throw $e;
                        }

                        // Anything else is about this row, so it must not block the rows behind it.
                        DeletionNotice::query()->whereKey($notice->getKey())->increment('attempts');

                        Log::error('Deletion notice could not be published.', [
                            'notice_id' => $notice->getKey(),
                            'attempts' => $notice->attempts + 1,
                            'exception' => $e,
                        ]);
                    }
                }
            });

        $this->info("Published {$count} deletion notice(s).");

        return self::SUCCESS;
    }

    /**
     * One short transaction per notice. "for update skip locked" makes a concurrent run pass over rows
     * this run holds, and the whereNull recheck skips rows it already finished. The broker push happens
     * under the lock of this outbox row only: no user request waits on it.
     * Delivery is at-least-once: if the process dies after the push but before commit,
     * the row stays unpublished and the notice is sent again next run.
     */
    private function publish(DeletionNotice $notice): int
    {
        return DB::transaction(function () use ($notice): int {
            $locked = DeletionNotice::query()
                ->whereKey($notice->getKey())
                ->whereNull('published_at')
                ->lock('for update skip locked')
                ->first();

            if ($locked === null) {
                return 0;
            }

            // The address was captured at delete time: a config change or a blank value now
            // must not drop a notice that then gets marked published.
            if (blank($locked->recipient)) {
                throw new RuntimeException("Deletion notice {$locked->id} has no recipient.");
            }

            Notification::route('mail', $locked->recipient)->notify(new FileDeletedNotification(
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
}
