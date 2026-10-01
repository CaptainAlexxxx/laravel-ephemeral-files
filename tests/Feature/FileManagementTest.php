<?php

namespace Tests\Feature;

use App\Models\StoredFile;
use App\Notifications\FileDeletedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class FileManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('files.disk'));
        Notification::fake();
    }

    public function test_index_lists_stored_files(): void
    {
        StoredFile::factory()->create(['original_name' => 'alpha.pdf']);

        $response = $this->get('/files');

        $response->assertOk();
        $response->assertSee('alpha.pdf');
    }

    public function test_manual_delete_removes_row_and_file(): void
    {
        $path = 'files/'.Str::uuid().'.pdf';
        Storage::disk(config('files.disk'))->put($path, 'content');
        $file = StoredFile::factory()->create(['path' => $path]);

        $response = $this->deleteJson("/files/{$file->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('stored_files', ['id' => $file->id]);
        Storage::disk(config('files.disk'))->assertMissing($path);
    }

    public function test_deleting_an_already_deleted_file_returns_404_and_sends_no_second_notification(): void
    {
        $path = 'files/already-gone.pdf';
        Storage::disk(config('files.disk'))->put($path, 'content');
        $file = StoredFile::factory()->create(['path' => $path]);

        $first = $this->deleteJson("/files/{$file->id}");
        $first->assertOk();

        $second = $this->deleteJson("/files/{$file->id}");
        $second->assertStatus(404);

        Notification::assertSentTimes(FileDeletedNotification::class, 1);
    }

    public function test_expired_file_waiting_for_purge_is_labelled_as_expired(): void
    {
        StoredFile::factory()->expired()->create();

        $response = $this->get('/files');

        $response->assertOk();
        $response->assertSee('expired, pending purge');
        $response->assertDontSee('1 minute ago');
    }

    public function test_page_past_the_last_one_redirects_to_the_last_page(): void
    {
        StoredFile::factory()->count(21)->create();

        $this->get('/files?page=5')->assertRedirect('/files?page=2');
    }

    public function test_page_past_the_end_with_no_files_redirects_to_the_first_page(): void
    {
        $this->get('/files?page=3')->assertRedirect('/files?page=1');
    }

    public function test_original_file_name_is_html_escaped_in_the_listing(): void
    {
        StoredFile::factory()->create(['original_name' => '<script>alert(1)</script>.pdf']);

        $response = $this->get('/files');

        $response->assertOk();
        $response->assertDontSee('<script>alert(1)</script>', false);
        $response->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;.pdf', false);
    }
}
