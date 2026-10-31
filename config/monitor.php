<?php

declare(strict_types=1);

return [
    'domain' => env('MONITOR_DOMAIN'),

    'token' => env('MONITOR_TOKEN'),

    'telescope_sample_percent' => (int) env('TELESCOPE_SAMPLE_PERCENT', 10),
];
