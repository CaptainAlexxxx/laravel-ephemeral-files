<?php

namespace App\Console\Commands;

use App\Enums\DeletionReason;
use App\Models\StoredFile;
use App\Services\FileDeletionService;
use Illuminate\Console\Command;

class PurgeExpiredFiles extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'files:purge-expired';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete expired stored files and notify by email.';

    public function handle(FileDeletionService $service): int
    {
        $count = 0;

        // chunkById, not chunk: deleting rows inside chunk() shifts the offset and skips rows.
        StoredFile::query()->expired()->chunkById(100, function ($files) use ($service, &$count): void {
            foreach ($files as $file) {
                // No try/catch: only DB or broker errors reach here, both systemic.
                // Let the exception stop the run, the next scheduled run retries.
                if ($service->delete($file, DeletionReason::Expired)) {
                    $count++;
                }
            }
        });

        $this->info("Purged {$count} expired file(s).");

        return self::SUCCESS;
    }
}
