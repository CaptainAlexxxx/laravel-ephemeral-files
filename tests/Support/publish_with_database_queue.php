<?php

// Child process for MysqlConcurrentPublisherTest: runs notices:publish with the rabbitmq connection
// backed by the jobs table, so the pushes of two processes can be counted in one place.

use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

config(['queue.connections.rabbitmq' => [
    'driver' => 'database',
    'table' => 'jobs',
    'queue' => 'default',
    'retry_after' => 90,
    'after_commit' => false,
]]);

$exit = $kernel->call('notices:publish');
echo $kernel->output();

exit($exit);
