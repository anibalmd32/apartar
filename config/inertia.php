<?php

declare(strict_types=1);

$pageExtensions = ['tsx', 'ts'];

return [

    'page_paths' => [
        resource_path('js/pages'),
    ],

    'page_extensions' => $pageExtensions,

    'testing' => [
        'ensure_pages_exist' => true,

        'page_paths' => [
            resource_path('js/pages'),
        ],

        'page_extensions' => $pageExtensions,
    ],

];
