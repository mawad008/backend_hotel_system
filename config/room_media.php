<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Room / Room Type media storage disk
    |--------------------------------------------------------------------------
    |
    | Room and Room Type photos are PUBLIC marketing content shown in the
    | guest app and dashboard, so — like hotel media — they live on the
    | framework "public" disk and are served by URL. Override with
    | ROOM_MEDIA_DISK for an S3/CDN setup; the disk is also recorded per
    | media row so a later switch does not orphan existing files.
    |
    */

    'disk' => env('ROOM_MEDIA_DISK', 'public'),

    /*
    |--------------------------------------------------------------------------
    | Upload constraints
    |--------------------------------------------------------------------------
    */

    'max_file_kb' => (int) env('ROOM_MEDIA_MAX_FILE_KB', 5120),

    'mimes' => ['jpg', 'jpeg', 'png', 'webp'],

    /*
    |--------------------------------------------------------------------------
    | Collections
    |--------------------------------------------------------------------------
    |
    | `gallery` is an ordered many — the only collection today.
    |
    */

    'collections' => [
        'gallery' => ['multiple' => true, 'max' => 20],
    ],

    /*
    |--------------------------------------------------------------------------
    | Per-minute upload ceiling (named limiter `room-media.upload`)
    |--------------------------------------------------------------------------
    */

    'rate_limits' => [
        'upload' => [
            'per_minute' => (int) env('ROOM_MEDIA_UPLOAD_RATE_LIMIT', 30),
        ],
    ],

];
