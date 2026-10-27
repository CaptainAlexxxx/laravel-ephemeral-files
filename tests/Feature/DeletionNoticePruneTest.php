<?php

namespace Tests\Feature;

use App\Enums\DeletionReason;
use App\Models\DeletionNotice;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeletionNoticePruneTest extends TestCase
{
    use RefreshDatabase;

    public function test_prune_removes_only_published_notices_older_than_the_retention(): void
    {
        config(['files.notice_retention_days' => 7]);

        $old = $this->notice('old.pdf', now()->subDays(8));
        $recent = $this->notice('recent.pdf', now()->subDays(6));
        $unpublished = $this->notice('waiting.pdf', null);

        $this->artisan('model:prune')->assertSuccessful();

        $this->assertModelMissing($old);
        $this->assertModelExists($recent);
        $this->assertModelExists($unpublished);
    }

    public function test_retention_defaults_to_seven_days(): void
    {
        $this->assertSame(7, config('files.notice_retention_days'));
    }

    public function test_prune_is_scheduled(): void
    {
        $commands = collect(app(Schedule::class)->events())->pluck('command');

        $this->assertTrue($commands->contains(fn ($command) => str_contains($command, 'model:prune')));
    }

    private function notice(string $name, $publishedAt): DeletionNotice
    {
        return DeletionNotice::query()->create([
            'original_name' => $name,
            'size' => 1,
            'reason' => DeletionReason::Expired,
            'recipient' => 'admin@example.com',
            'uploaded_at' => now()->subDays(10),
            'deleted_at' => now()->subDays(9),
            'published_at' => $publishedAt,
        ]);
    }
}
