<?php

return [

    /*
    |--------------------------------------------------------------------------
    | SQL Query Logging
    |--------------------------------------------------------------------------
    |
    | SQL logs use the default Laravel log channel unless a channel is set.
    | The default stack currently writes to storage/logs/laravel.log.
    |
    */

    'enabled' => env('SQL_LOG_ENABLED', true),

    'channel' => env('SQL_LOG_CHANNEL'),

    'slow_threshold_ms' => env('SQL_LOG_SLOW_MS', 500),

    'sensitive_keys' => [
        'password',
        'password_confirmation',
        'current_password',
        'password_hash',
        'passwd',
        'pwd',
        'token',
        'access_token',
        'refresh_token',
        'api_token',
        'remember_token',
        'authorization',
        'secret',
        'client_secret',
        'api_key',
        'apikey',
        'private_key',
        'credential',
        'credentials',
    ],

];
