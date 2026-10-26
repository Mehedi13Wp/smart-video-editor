<?php
return ['default' => 'sqlite', 'connections' => ['sqlite' => [
    'driver' => 'sqlite', 'database' => env('DB_DATABASE', database_path('database.sqlite')),
    'prefix' => '', 'foreign_key_constraints' => true, 'busy_timeout' => 5000,
]], 'migrations' => ['table' => 'migrations', 'update_date_on_publish' => true]];
