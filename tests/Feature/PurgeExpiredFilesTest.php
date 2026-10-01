<?php

namespace Tests\Feature;

use App\Models\StoredFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PurgeExpiredFilesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('files.disk'));
        Notification::fake();
    }

    public function test_file_exactly_at_its_ttl_boundary_is_purged(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00'));

        $path = 'files/boundary.pdf';
        Storage::disk(config('files.disk'))->put($path, 'content');
        $file = StoredFile::factory()->create([
            'path' => $path,
            'expires_at' => now()->addMinutes(config('files.ttl_minutes')),
        ]);

        // travel to the exact expiry instant, not a second past it
        Carbon::setTestNow($file->expires_at);

        $this->artisan('files:purge-expired')->assertSuccessful();

        $this->assertDatabaseMissing('stored_files', ['id' => $file->id]);
        Storage::disk(config('files.disk'))->assertMissing($path);

        Carbon::setTestNow();
    }

    public function test_file_one_minute_before_ttl_is_not_purged(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00'));

        $path = 'files/not-yet.pdf';
        Storage::disk(config('files.disk'))->put($path, 'content');
        $file = StoredFile::factory()->create([
            'path' => $path,
            'expires_at' => now()->addMinutes(config('files.ttl_minutes')),
        ]);

        Carbon::setTestNow($file->expires_at->copy()->subMinute());

        $this->artisan('files:purge-expired')->assertSuccessful();

        $this->assertDatabaseHas('stored_files', ['id' => $file->id]);
        Storage::disk(config('files.disk'))->assertExists($path);

        Carbon::setTestNow();
    }

    public function test_purge_leaves_non_expired_files_untouched(): void
    {
        $expired = StoredFile::factory()->expired()->create(['path' => 'files/expired.pdf']);
        $fresh = StoredFile::factory()->create(['path' => 'files/fresh.pdf']);

        Storage::disk(config('files.disk'))->put($expired->path, 'content');
        Storage::disk(config('files.disk'))->put($fresh->path, 'content');

        $this->artisan('files:purge-expired')->assertSuccessful();

        $this->assertDatabaseMissing('stored_files', ['id' => $expired->id]);
        $this->assertDatabaseHas('stored_files', ['id' => $fresh->id]);
        Storage::disk(config('files.disk'))->assertExists($fresh->path);
    }
}
