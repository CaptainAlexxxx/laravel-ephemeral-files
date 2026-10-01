<?php

namespace Tests\Feature;

use App\Enums\DeletionReason;
use App\Models\StoredFile;
use App\Services\FileDeletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * R8 / Decisions: publishing happens inside the delete transaction, so a broker
 * failure must roll the row delete back. These tests do not use Notification::fake()
 * on purpose: faking would skip the real route()->notify() call the service makes
 * inside the transaction, and the whole point here is to make that call fail.
 */
class FileDeletionRollbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('files.disk'));
    }

    public function test_manual_delete_rolls_back_when_publishing_to_the_broker_fails(): void
    {
        $path = 'files/rollback.pdf';
        Storage::disk(config('files.disk'))->put($path, 'content');
        $file = StoredFile::factory()->create(['path' => $path]);

        Notification::shouldReceive('send')->once()->andThrow(new RuntimeException('broker down'));

        $service = app(FileDeletionService::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('broker down');

        try {
            $service->delete($file, DeletionReason::Manual);
        } finally {
            // if publishing happened after the transaction instead of inside it, the row
            // would already be gone by the time route() throws and these would fail
            $this->assertDatabaseHas('stored_files', ['id' => $file->id]);
            Storage::disk(config('files.disk'))->assertExists($path);
        }
    }

    public function test_http_delete_returns_500_and_leaves_row_and_file_intact_when_publishing_fails(): void
    {
        $path = 'files/rollback-http.pdf';
        Storage::disk(config('files.disk'))->put($path, 'content');
        $file = StoredFile::factory()->create(['path' => $path]);

        Notification::shouldReceive('send')->once()->andThrow(new RuntimeException('broker down'));

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
}
