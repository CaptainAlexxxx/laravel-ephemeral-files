<?php

return [

    'ttl_minutes' => (int) env('FILE_TTL_MINUTES', 1440),

    'notify_email' => env('NOTIFY_EMAIL'),

    // docker nginx/php allow 12M, Laravel is the one rejecting above this
    'max_size_kb' => 10240,

    'disk' => 'local',

];
