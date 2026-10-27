<?php

namespace Tests\Feature;

use App\Enums\DeletionReason;
use App\Models\DeletionNotice;
use App\Models\StoredFile;
use App\Notifications\FileDeletedNotification;
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
 * R8 / R9 / Decisions: the delete writes an outbox row in its transaction and notices:publish
 * sends it later. A broker outage must not affect the delete.
 */
class DeletionOutboxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('files.disk'));
    }

    public function test_delete_commits_and_keeps_the_notice_unpublished_when_the_broker_is_down(): void
    {
        $this->breakTheRabbitmqConnection();

        $path = 'files/broker-down.pdf';
        Storage::disk(config('files.disk'))->put($path, 'content');
        $file = StoredFile::factory()->create(['path' => $path]);

        $this->deleteJson("/files/{$file->id}")->assertOk();

        $this->assertDatabaseMissing('stored_files', ['id' => $file->id]);
        Storage::disk(config('files.disk'))->assertMissing($path);
        $this->assertDatabaseCount('deletion_notices', 1);
        $this->assertNull(DeletionNotice::query()->first()->published_at);
    }

    public function test_a_failed_publish_leaves_the_notice_unpublished_and_a_later_run_sends_it(): void
    {
        $this->breakTheRabbitmqConnection();
        $file = StoredFile::factory()->create();
        app(FileDeletionService::class)->delete($file, DeletionReason::Manual);

        try {
            $this->artisan('notices:publish');
            $this->fail('The publish run should have failed while the broker is down.');
        } catch (RuntimeException $e) {
            $this->assertSame('broker down', $e->getMessage());
        }

        $this->assertNull(DeletionNotice::query()->first()->published_at);

        Notification::fake();
        $this->artisan('notices:publish')->assertSuccessful();

        Notification::assertSentTimes(FileDeletedNotification::class, 1);
        $this->assertNotNull(DeletionNotice::query()->first()->published_at);
    }

    public function test_publish_run_sends_the_notice_values_and_marks_it_published(): void
    {
        Notification::fake();
        $file = StoredFile::factory()->create(['original_name' => 'report.pdf', 'size' => 2048]);
        app(FileDeletionService::class)->delete($file, DeletionReason::Expired);

        $this->artisan('notices:publish')->assertSuccessful();

        Notification::assertSentTimes(FileDeletedNotification::class, 1);
        Notification::assertSentOnDemand(
            FileDeletedNotification::class,
            fn (FileDeletedNotification $n) => $n->originalName === 'report.pdf'
                && $n->size === 2048
                && $n->reason === DeletionReason::Expired
                && $n->connection === 'rabbitmq'
        );
        $this->assertNotNull(DeletionNotice::query()->first()->published_at);
    }

    public function test_running_the_publisher_twice_sends_the_notice_once(): void
    {
        Notification::fake();
        $file = StoredFile::factory()->create();
        app(FileDeletionService::class)->delete($file, DeletionReason::Manual);

        $this->artisan('notices:publish')->assertSuccessful();
        $this->artisan('notices:publish')->assertSuccessful();

        Notification::assertSentTimes(FileDeletedNotification::class, 1);
    }

    public function test_the_notice_goes_to_the_address_captured_at_delete_time(): void
    {
        Notification::fake();
        config(['files.notify_email' => 'before@example.com']);
        app(FileDeletionService::class)->delete(StoredFile::factory()->create(), DeletionReason::Manual);
        $this->assertSame('before@example.com', DeletionNotice::query()->first()->recipient);

        config(['files.notify_email' => 'after@example.com']);
        $this->artisan('notices:publish')->assertSuccessful();

        Notification::assertSentOnDemand(
            FileDeletedNotification::class,
            fn (FileDeletedNotification $n, array $channels, $notifiable) => $notifiable->routes['mail'] === 'before@example.com'
        );
    }

    public function test_a_notice_without_a_recipient_is_not_marked_published(): void
    {
        Notification::fake();
        DeletionNotice::query()->create([
            'original_name' => 'a.pdf',
            'size' => 1,
            'reason' => DeletionReason::Manual,
            'recipient' => '',
            'uploaded_at' => now(),
            'deleted_at' => now(),
        ]);

        try {
            $this->artisan('notices:publish');
            $this->fail('Expected a RuntimeException.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('has no recipient', $e->getMessage());
        }

        Notification::assertNothingSent();
        $this->assertNull(DeletionNotice::query()->first()->published_at);
    }

    public function test_delete_throws_before_touching_the_row_when_notify_email_is_blank(): void
    {
        config(['files.notify_email' => '']);

        $path = 'files/no-recipient.pdf';
        Storage::disk(config('files.disk'))->put($path, 'content');
        $file = StoredFile::factory()->create(['path' => $path]);

        try {
            app(FileDeletionService::class)->delete($file, DeletionReason::Manual);
            $this->fail('Expected a RuntimeException.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('NOTIFY_EMAIL is not configured', $e->getMessage());
        }

        $this->assertDatabaseHas('stored_files', ['id' => $file->id]);
        Storage::disk(config('files.disk'))->assertExists($path);
        $this->assertDatabaseCount('deletion_notices', 0);
    }

    /**
     * Points the `rabbitmq` queue connection at a driver whose push throws, the way a broker
     * outage does, so the real dispatch path in notices:publish fails.
     */
    private function breakTheRabbitmqConnection(): void
    {
        config(['queue.connections.rabbitmq.driver' => 'failing-broker']);

        Queue::extend('failing-broker', fn () => new class implements ConnectorInterface
        {
            public function connect(array $config)
            {
                return new class extends \Illuminate\Queue\Queue implements QueueContract
                {
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
                        throw new RuntimeException('broker down');
                    }

                    public function pushOn($queue, $job, $data = '')
                    {
                        throw new RuntimeException('broker down');
                    }

                    public function pushRaw($payload, $queue = null, array $options = [])
                    {
                        throw new RuntimeException('broker down');
                    }

                    public function later($delay, $job, $data = '', $queue = null)
                    {
                        throw new RuntimeException('broker down');
                    }

                    public function laterOn($queue, $delay, $job, $data = '')
                    {
                        throw new RuntimeException('broker down');
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
