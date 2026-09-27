<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Hotel media storage disk
    |--------------------------------------------------------------------------
    |
    | Hotel logo / cover / gallery images are PUBLIC marketing content shown
    | in the guest app and on the public site, so — unlike identity-
    | verification documents — they live on the framework "public" disk and
    | are served by URL. Override with HOTEL_MEDIA_DISK for an S3/CDN setup;
    | the disk is also recorded per media row so a later switch does not
    | orphan existing files.
    |
    */

    'disk' => env('HOTEL_MEDIA_DISK', 'public'),

    /*
    |--------------------------------------------------------------------------
    | Upload constraints
    |--------------------------------------------------------------------------
    */

    'max_file_kb' => (int) env('HOTEL_MEDIA_MAX_FILE_KB', 5120),

    'mimes' => ['jpg', 'jpeg', 'png', 'webp'],

    /*
    |--------------------------------------------------------------------------
    | Collections
    |--------------------------------------------------------------------------
    |
    | `single` collections keep at most one image (a re-upload replaces it);
    | `gallery` is an ordered many.
    |
    */

    'collections' => [
        'logo' => ['multiple' => false],
        'cover' => ['multiple' => false],
        'gallery' => ['multiple' => true, 'max' => 12],
    ],

    /*
    |--------------------------------------------------------------------------
    | Per-minute upload ceiling (named limiter `hotel-media.upload`)
    |--------------------------------------------------------------------------
    */

    'rate_limits' => [
        'upload' => [
            'per_minute' => (int) env('HOTEL_MEDIA_UPLOAD_RATE_LIMIT', 30),
        ],
    ],

];
