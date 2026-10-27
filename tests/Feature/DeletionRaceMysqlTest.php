<?php

namespace Tests\Feature;

use App\Enums\DeletionReason;
use App\Models\StoredFile;
use App\Services\FileDeletionService;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
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

    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.connections.'.config('database.default').'.driver') !== 'mysql') {
            $this->markTestSkipped('Needs MySQL row locks.');
        }
    }

    protected function tearDown(): void
    {
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
        $child = null;
        $pipes = [];

        DB::listen(function (QueryExecuted $query) use (&$child, &$pipes, $observer): void {
            if ($child !== null || ! str_starts_with($query->sql, 'insert into `deletion_notices`')) {
                return;
            }

            // A is inside its transaction and holds the row lock: start B and wait until it is blocked
            $child = proc_open(
                [PHP_BINARY, base_path('artisan'), 'files:purge-expired'],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                base_path(),
            );

            $this->waitUntilBlocked($observer);
        });

        $this->assertTrue(app(FileDeletionService::class)->delete($file, DeletionReason::Manual));

        $this->assertNotNull($child, 'The second process was never started.');
        $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
        $exit = proc_close($child);

        $this->assertSame(0, $exit, $output);
        $this->assertDatabaseCount('deletion_notices', 1);
        $this->assertDatabaseMissing('stored_files', ['id' => $file->id]);
    }

    private function waitUntilBlocked(Connection $observer): void
    {
        $deadline = microtime(true) + 15;

        while (microtime(true) < $deadline) {
            if ($observer->selectOne('select count(*) as c from performance_schema.data_lock_waits')->c > 0) {
                return;
            }

            usleep(100_000);
        }

        $this->fail('The second process never blocked on the row lock.');
    }
}
