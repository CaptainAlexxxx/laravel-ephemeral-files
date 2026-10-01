<?php

namespace Tests\Feature;

use App\Enums\DeletionReason;
use App\Models\StoredFile;
use App\Notifications\FileDeletedNotification;
use App\Services\FileDeletionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Markdown;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FileDeletionNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('files.disk'));
        Notification::fake();
    }

    public function test_manual_delete_sends_one_notification_on_the_rabbitmq_connection_to_notify_email(): void
    {
        $path = 'files/one.pdf';
        Storage::disk(config('files.disk'))->put($path, 'content');
        $file = StoredFile::factory()->create(['path' => $path]);

        $this->deleteJson("/files/{$file->id}")->assertOk();

        Notification::assertSentTimes(FileDeletedNotification::class, 1);

        Notification::assertSentTo(
            new AnonymousNotifiable,
            FileDeletedNotification::class,
            function (FileDeletedNotification $notification, array $channels, $notifiable) {
                return $notifiable->routes['mail'] === config('files.notify_email')
                    && $notification->connection === 'rabbitmq';
            }
        );
    }

    public function test_deleting_the_same_model_instance_twice_sends_exactly_one_notification(): void
    {
        $path = 'files/race.pdf';
        Storage::disk(config('files.disk'))->put($path, 'content');
        $file = StoredFile::factory()->create(['path' => $path]);

        $service = app(FileDeletionService::class);

        $firstResult = $service->delete($file, DeletionReason::Manual);
        $secondResult = $service->delete($file, DeletionReason::Manual);

        $this->assertTrue($firstResult);
        $this->assertFalse($secondResult);

        Notification::assertSentTimes(FileDeletedNotification::class, 1);
    }

    public function test_manual_delete_racing_the_purge_command_sends_exactly_one_notification(): void
    {
        $path = 'files/race-with-purge.pdf';
        Storage::disk(config('files.disk'))->put($path, 'content');
        $file = StoredFile::factory()->expired()->create(['path' => $path]);

        // purge wins the race first
        $this->artisan('files:purge-expired')->assertSuccessful();

        // manual delete loses: the row is already gone
        $this->deleteJson("/files/{$file->id}")->assertStatus(404);

        Notification::assertSentTimes(FileDeletedNotification::class, 1);
    }

    public function test_markdown_in_the_file_name_is_not_rendered_as_a_link_in_the_email(): void
    {
        $notification = new FileDeletedNotification(
            originalName: '[click](http:evil.example).pdf',
            size: 1024,
            reason: DeletionReason::Manual,
            uploadedAt: CarbonImmutable::now(),
            deletedAt: CarbonImmutable::now(),
        );

        $html = (string) $notification->toMail(new AnonymousNotifiable)->render();

        $this->assertStringNotContainsString('href="http:evil.example"', $html);
        $this->assertStringContainsString('[click](http:evil.example).pdf', $html);
    }

    public function test_the_text_part_shows_the_file_name_literally_with_no_escape_artifacts(): void
    {
        $notification = new FileDeletedNotification(
            originalName: 'my_report (1).pdf',
            size: 1024,
            reason: DeletionReason::Manual,
            uploadedAt: CarbonImmutable::now(),
            deletedAt: CarbonImmutable::now(),
        );

        $message = $notification->toMail(new AnonymousNotifiable);
        $markdown = app(Markdown::class)->theme($message->theme ?: app(Markdown::class)->getTheme());
        $text = $markdown->renderText($message->markdown, $message->data());

        $this->assertStringContainsString('File: my_report (1).pdf', $text);
        $this->assertStringNotContainsString('\\_', $text);
        $this->assertStringNotContainsString('&#', $text);
    }

    public function test_running_the_purge_command_twice_in_a_row_is_idempotent(): void
    {
        $path = 'files/expired.pdf';
        Storage::disk(config('files.disk'))->put($path, 'content');
        StoredFile::factory()->expired()->create(['path' => $path]);

        $this->artisan('files:purge-expired')->assertSuccessful();
        $this->artisan('files:purge-expired')->assertSuccessful();

        $this->assertDatabaseCount('stored_files', 0);
        Notification::assertSentTimes(FileDeletedNotification::class, 1);
    }
}
