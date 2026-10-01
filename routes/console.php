<?php

use Illuminate\Support\Facades\Schedule;

// withoutOverlapping is just to avoid wasted work: the conditional delete in
// FileDeletionService makes concurrent runs safe either way.
Schedule::command('files:purge-expired')->everyMinute()->withoutOverlapping();
