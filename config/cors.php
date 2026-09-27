<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    // `storage/*` is included alongside the API so the guest app's real,
    // per-entity photos (`cover_url`, `gallery[].url`, `logo_url` — served
    // straight from the public disk symlink) are readable by Flutter Web,
    // whose origin (the Flutter dev server / hosted app) differs from the
    // API's. Note this only takes effect where `/storage/*` requests are
    // actually routed through Laravel; the local `php artisan serve` static
    // passthrough for `storage/*` is handled separately in `server.php`.
    'paths' => ['api/*', 'sanctum/csrf-cookie', 'storage/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
