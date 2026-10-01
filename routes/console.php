<?php

use Illuminate\Support\Facades\Schedule;

// withoutOverlapping only saves wasted work, the conditional delete in FileDeletionService
// makes concurrent runs safe. The 5 minute lock expiry stops a killed run from blocking the
// purge for the default 24 hours.
Schedule::command('files:purge-expired')->everyMinute()->withoutOverlapping(5);
