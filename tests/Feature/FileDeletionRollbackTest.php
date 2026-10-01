<?php

namespace Tests\Feature;

use App\Enums\DeletionReason;
use App\Models\StoredFile;
use App\Services\FileDeletionService;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Connectors\ConnectorInterface;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * R8 / Decisions: publishing happens inside the delete transaction, so a broker
 * failure must roll the row delete back. These tests do not mock the Notification
 * facade: a mock would also swallow a regression where the push is deferred until
 * after commit (after_commit config, ->afterCommit() on the notification), which
 * would still make the mock throw and the test pass while production silently
 * breaks the rollback guarantee. Instead the real `rabbitmq` queue connection is
 * replaced with one that throws on push, so the real synchronous dispatch fails.
 */
class FileDeletionRollbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('files.disk'));
        $this->breakTheRabbitmqConnection();
    }

    public function test_manual_delete_rolls_back_when_publishing_to_the_broker_fails(): void
    {
        $path = 'files/rollback.pdf';
        Storage::disk(config('files.disk'))->put($path, 'content');
        $file = StoredFile::factory()->create(['path' => $path]);

        $service = app(FileDeletionService::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('broker down');

        try {
            $service->delete($file, DeletionReason::Manual);
        } finally {
            // if publishing happened after the transaction instead of inside it, the row
            // would already be gone by the time the broker push throws and these would fail
            $this->assertDatabaseHas('stored_files', ['id' => $file->id]);
            Storage::disk(config('files.disk'))->assertExists($path);
        }
    }

    public function test_http_delete_returns_500_and_leaves_row_and_file_intact_when_publishing_fails(): void
    {
        $path = 'files/rollback-http.pdf';
        Storage::disk(config('files.disk'))->put($path, 'content');
        $file = StoredFile::factory()->create(['path' => $path]);

        $response = $this->deleteJson("/files/{$file->id}");

        $response->assertStatus(500);
        $this->assertDatabaseHas('stored_files', ['id' => $file->id]);
        Storage::disk(config('files.disk'))->assertExists($path);
    }

    public function test_delete_throws_before_touching_the_row_when_notify_email_is_blank(): void
    {
        config(['files.notify_email' => '']);

        $path = 'files/no-recipient.pdf';
        Storage::disk(config('files.disk'))->put($path, 'content');
        $file = StoredFile::factory()->create(['path' => $path]);

        Notification::fake();

        $service = app(FileDeletionService::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('NOTIFY_EMAIL is not configured, refusing to delete without a notification.');

        try {
            $service->delete($file, DeletionReason::Manual);
        } finally {
            $this->assertDatabaseHas('stored_files', ['id' => $file->id]);
            Storage::disk(config('files.disk'))->assertExists($path);
            Notification::assertNothingSent();
        }
    }

    /**
     * Points the `rabbitmq` queue connection at a driver whose push/later/bulk calls
     * throw instead of talking to a broker. The notification dispatch in
     * FileDeletionService is synchronous (ShouldQueue jobs are pushed inline by the
     * bus, not deferred), so this makes the real publish attempt fail the same way a
     * broker outage would.
     */
    private function breakTheRabbitmqConnection(): void
    {
        config(['queue.connections.rabbitmq.driver' => 'failing-broker']);

        Queue::extend('failing-broker', fn () => new class implements ConnectorInterface
        {
            public function connect(array $config)
            {
                // Extends the real base Queue class instead of implementing the
                // contract from scratch, so enqueueUsing() honours after_commit /
                // ->afterCommit() exactly like a real connector would: a deferred
                // push fails after commit, not inside the transaction.
                return new class($config['after_commit'] ?? false) extends \Illuminate\Queue\Queue implements QueueContract
                {
                    public function __construct(bool $dispatchAfterCommit)
                    {
                        $this->dispatchAfterCommit = $dispatchAfterCommit;
                    }

                    public function size($queue = null)
                    {
                        return 0;
                    }

                    public function pendingSize($queue = null)
                    {
                        return 0;
                    }

                    public function delayedSize($queue = null)
                    {
                        return 0;
                    }

                    public function reservedSize($queue = null)
                    {
                        return 0;
                    }

                    public function creationTimeOfOldestPendingJob($queue = null)
                    {
                        return null;
                    }

                    public function push($job, $data = '', $queue = null)
                    {
                        return $this->enqueueUsing($job, $this->createPayload($job, $queue, $data), $queue, null, function () {
                            throw new RuntimeException('broker down');
                        });
                    }

                    public function pushOn($queue, $job, $data = '')
                    {
                        return $this->push($job, $data, $queue);
                    }

                    public function pushRaw($payload, $queue = null, array $options = [])
                    {
                        throw new RuntimeException('broker down');
                    }

                    public function later($delay, $job, $data = '', $queue = null)
                    {
                        return $this->enqueueUsing($job, $this->createPayload($job, $queue, $data), $queue, $delay, function () {
                            throw new RuntimeException('broker down');
                        });
                    }

                    public function laterOn($queue, $delay, $job, $data = '')
                    {
                        return $this->later($delay, $job, $data, $queue);
                    }

                    public function bulk($jobs, $data = '', $queue = null)
                    {
                        throw new RuntimeException('broker down');
                    }

                    public function pop($queue = null)
                    {
                        return null;
                    }
                };
            }
        });
    }
}
