<?php

use Illuminate\Support\Facades\Schedule;

// withoutOverlapping only saves wasted work, the conditional delete in FileDeletionService
// makes concurrent runs safe. The 5 minute lock expiry stops a killed run from blocking the
// purge for the default 24 hours.
Schedule::command('files:purge-expired')->everyMinute()->withoutOverlapping(5);

// Outbox publisher. skipLocked in the command keeps overlapping runs from double-sending, the flag
// and the 5 minute expiry mirror the purge above.
Schedule::command('notices:publish')->everyMinute()->withoutOverlapping(5);
