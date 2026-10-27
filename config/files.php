<?php

return [

    'ttl_minutes' => (int) env('FILE_TTL_MINUTES', 1440),

    'notify_email' => env('NOTIFY_EMAIL'),

    // published outbox rows hold the original file name, so they are pruned after this many days
    'notice_retention_days' => (int) env('NOTICE_RETENTION_DAYS', 7),

    // docker nginx/php allow 12M, Laravel is the one rejecting above this
    'max_size_kb' => 10240,

    'disk' => 'local',

];
