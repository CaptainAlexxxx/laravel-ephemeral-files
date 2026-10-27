<?php

namespace Tests\Feature;

use App\Enums\DeletionReason;
use App\Models\DeletionNotice;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\Support\RunsChildProcess;
use Tests\TestCase;

/**
 * Two notices:publish processes on one outbox row, on real InnoDB locks (SQLite ignores skip locked).
 *
 * Process A claims the row and is inside its push when it starts process B. B must pass over the
 * locked row, so the jobs table, which stands in for the broker in both processes, ends with one job.
 */
class MysqlConcurrentPublisherTest extends TestCase
{
    use DatabaseTruncation;
    use RunsChildProcess;

    private ?int $childExit = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.connections.'.config('database.default').'.driver') !== 'mysql') {
            $this->markTestSkipped('Needs MySQL row locks.');
        }
    }

    protected function tearDown(): void
    {
        $this->terminateChild();

        // committed rows would leak into tests that rely on a rolled back transaction
        if (config('database.connections.'.config('database.default').'.driver') === 'mysql') {
            DB::table('deletion_notices')->delete();
            DB::table('jobs')->delete();
        }

        parent::tearDown();
    }

    public function test_two_publishers_on_one_notice_send_it_once(): void
    {
        // same connection as tests/Support/publish_with_database_queue.php gives the child
        config(['queue.connections.rabbitmq' => [
            'driver' => 'database',
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 90,
            'after_commit' => false,
        ]]);

        $notice = DeletionNotice::query()->create([
            'original_name' => 'once.pdf',
            'size' => 1,
            'reason' => DeletionReason::Manual,
            'recipient' => 'admin@example.com',
            'uploaded_at' => now(),
            'deleted_at' => now(),
        ]);

        DB::listen(function (QueryExecuted $query): void {
            if ($this->child !== null || ! str_starts_with($query->sql, 'insert into `jobs`')) {
                return;
            }

            // A holds the row lock and has just pushed: run B to the end, it has to skip the row
            $this->startChild([PHP_BINARY, base_path('tests/Support/publish_with_database_queue.php')]);
            $this->childExit = $this->finishChild();
        });

        $this->artisan('notices:publish')->assertSuccessful();

        $this->assertNotNull($this->childExit, 'The second publisher was never started.');
        $this->assertSame(0, $this->childExit, $this->childOutput);
        $this->assertStringContainsString('Published 0 deletion notice(s).', $this->childOutput);
        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertNotNull($notice->fresh()->published_at);
    }
}
