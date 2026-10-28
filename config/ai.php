<?php

declare(strict_types=1);

return [

    'default' => 'deepseek',

    'daily_limit_per_organization' => (int) env('AI_DAILY_LIMIT_PER_ORGANIZATION', 30),

    'providers' => [
        'deepseek' => [
            'driver' => 'deepseek',
            'key' => env('DEEPSEEK_API_KEY'),
            'models' => [
                'text' => [
                    'default' => env('AI_TEXT_MODEL', 'deepseek-flash'),
                ],
            ],
        ],
    ],

];
