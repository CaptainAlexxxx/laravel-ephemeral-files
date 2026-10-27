<?php

namespace Tests\Feature;

use App\Enums\DeletionReason;
use App\Models\StoredFile;
use App\Services\FileDeletionService;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\Support\RunsChildProcess;
use Tests\TestCase;

/**
 * R9 on real InnoDB row locks. SQLite has no row locks, so this only runs on MySQL.
 *
 * Process A deletes the row and inserts the notice, then holds its transaction open until
 * process B (a real `files:purge-expired` run) is blocked on the same row. A commits, B sees
 * 0 affected rows. A second connection from a cloned config watches the lock queue.
 */
class DeletionRaceMysqlTest extends TestCase
{
    use DatabaseTruncation;
    use RunsChildProcess;

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
            DB::table('stored_files')->delete();
        }

        parent::tearDown();
    }

    public function test_manual_delete_and_purge_racing_on_one_row_produce_one_notice(): void
    {
        config(['database.connections.mysql_observer' => config('database.connections.'.config('database.default'))]);

        $file = StoredFile::factory()->expired()->create();
        $observer = DB::connection('mysql_observer');
        $lockHolder = $this->performanceSchemaThreadId();

        DB::listen(function (QueryExecuted $query) use ($observer, $lockHolder): void {
            if ($this->child !== null || ! str_starts_with($query->sql, 'insert into `deletion_notices`')) {
                return;
            }

            // A is inside its transaction and holds the row lock: start B and wait until it is blocked
            $this->startChild([PHP_BINARY, base_path('artisan'), 'files:purge-expired']);

            $this->waitUntilBlockedBy($lockHolder, $observer);
        });

        $this->assertTrue(app(FileDeletionService::class)->delete($file, DeletionReason::Manual));

        $this->assertNotNull($this->child, 'The second process was never started.');
        $exit = $this->finishChild();

        $this->assertSame(0, $exit, $this->childOutput);
        $this->assertDatabaseCount('deletion_notices', 1);
        $this->assertDatabaseMissing('stored_files', ['id' => $file->id]);
    }

    /**
     * The performance_schema id of this test's own connection, the one that holds the row lock.
     */
    private function performanceSchemaThreadId(): int
    {
        return (int) DB::selectOne(
            'select thread_id as id from performance_schema.threads where processlist_id = connection_id()'
        )->id;
    }

    /**
     * Only waits blocked by this test's connection count, so an unrelated lock wait on a
     * shared server cannot end the wait early.
     */
    private function waitUntilBlockedBy(int $lockHolder, Connection $observer): void
    {
        $deadline = microtime(true) + 15;

        while (microtime(true) < $deadline) {
            $blocked = $observer->selectOne(
                'select count(*) as c from performance_schema.data_lock_waits where blocking_thread_id = ?',
                [$lockHolder]
            )->c;

            if ($blocked > 0) {
                return;
            }

            if (! $this->childIsRunning()) {
                $this->drainChild();
                $this->fail('The second process exited before it blocked on the row lock. Output: '.$this->childOutput);
            }

            usleep(100_000);
        }

        $this->terminateChild();
        $this->fail('The second process never blocked on the row lock. Output: '.$this->childOutput);
    }
}
