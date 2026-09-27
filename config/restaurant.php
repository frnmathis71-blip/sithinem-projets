<?php

return [
    'featured_limit' => 6,
    'featured_min_units' => 3,
    'demo' => env('RESTAURANT_DEMO', false),
    'printing' => [
        'token' => env('RESTAURANT_PRINT_TOKEN'),
    ],
    'vapid' => [
        'subject' => env('VAPID_SUBJECT'),
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
    ],
];
