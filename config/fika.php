<?php

return [
    'api_key' => env('FIKA_API_KEY'),
    // Includes the API version prefix; do not append /transactional-emails.
    'base_url' => env('FIKA_API_URL', 'https://api.fikamessaging.com/api/v1'),
    'timeout' => (int) env('FIKA_TIMEOUT', 45),
    'connect_timeout' => (int) env('FIKA_CONNECT_TIMEOUT', 10),
];
