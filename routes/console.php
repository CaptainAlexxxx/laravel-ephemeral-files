<?php

use Illuminate\Support\Facades\Schedule;

// withoutOverlapping only saves wasted work, the conditional delete in FileDeletionService
// makes concurrent runs safe. The 5 minute lock expiry stops a killed run from blocking the
// purge for the default 24 hours.
Schedule::command('files:purge-expired')->everyMinute()->withoutOverlapping(5);

// Outbox publisher. skip locked in the command keeps overlapping runs from double-sending, the flag
// and the 5 minute expiry mirror the purge above.
Schedule::command('notices:publish')->everyMinute()->withoutOverlapping(5);

// Drops published outbox rows older than files.notice_retention_days. Unpublished rows are never pruned.
Schedule::command('model:prune')->daily();
