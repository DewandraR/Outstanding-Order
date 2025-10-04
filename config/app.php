<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    */
    'name' => env('APP_NAME', 'YPPR079'),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    */
    'env' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    */
    'debug' => (bool) env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    */
    'url' => env('APP_URL', 'http://localhost'),

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    */
    'timezone' => env('APP_TIMEZONE', 'Asia/Jakarta'),

    /*
    |--------------------------------------------------------------------------
    | Application Locale Configuration
    |--------------------------------------------------------------------------
    */
    // pakai 'id' jika ingin teks lokal Indonesia
    'locale' => env('APP_LOCALE', 'id'),
    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),
    // faker untuk seeding/testing
    'faker_locale' => env('APP_FAKER_LOCALE', 'id_ID'),

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    */
    'cipher' => 'AES-256-CBC',
    'key' => env('APP_KEY'),
    'previous_keys' => [
        ...array_filter(explode(',', (string) env('APP_PREVIOUS_KEYS', ''))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode Driver
    |--------------------------------------------------------------------------
    | Supported: "file", "cache"
    */
    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        // Jika driver = "cache", set store ke nama store valid di config/cache.php (mis: redis)
        'store'  => env('APP_MAINTENANCE_STORE', null),
    ],

];
