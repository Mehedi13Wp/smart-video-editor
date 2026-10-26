<?php
return [
    'name' => env('APP_NAME', 'AI Video Studio'), 'env' => env('APP_ENV', 'local'),
    'debug' => (bool) env('APP_DEBUG', false), 'url' => env('APP_URL', 'http://127.0.0.1:8000'),
    'timezone' => 'UTC', 'locale' => 'en', 'fallback_locale' => 'en',
    'key' => env('APP_KEY'), 'cipher' => 'AES-256-CBC',
];
