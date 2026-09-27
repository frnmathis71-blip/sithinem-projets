<?php

return [
    'force_https' => (bool) env('FORCE_HTTPS', false),
    'operator' => env('LEGAL_OPERATOR'),
    'address' => env('LEGAL_ADDRESS'),
    'privacy_email' => env('LEGAL_PRIVACY_EMAIL'),
    'host' => env('LEGAL_HOST'),
    'retention' => env('LEGAL_RETENTION'),
];
