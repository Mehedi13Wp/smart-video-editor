<?php
return [
    'python_path' => env('PYTHON_PATH', 'python'),
    'timeout' => 10800,
    'lock_path' => storage_path('framework/remix-worker.lock'),
];
