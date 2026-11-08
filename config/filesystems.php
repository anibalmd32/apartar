<?php

declare(strict_types=1);

$uploads = [
    'public' => storage_path('app/public'),
    'receipts' => storage_path('app/private/receipts'),
    'mail' => storage_path('app/private/mail'),
    'exports' => storage_path('app/private/exports'),
];

$onR2 = env('UPLOADS_STORAGE', 'local') === 'r2';

$r2 = static fn (string $bucket, string $root, bool $throw, ?string $url = null): array => [
    'driver' => 's3',
    'key' => env('R2_ACCESS_KEY_ID'),
    'secret' => env('R2_SECRET_ACCESS_KEY'),
    'region' => 'auto',
    'endpoint' => env('R2_ENDPOINT'),
    'use_path_style_endpoint' => true,
    'bucket' => $bucket,
    'root' => $root,
    'url' => $url,
    'retain_visibility' => false,
    'throw' => $throw,
];

return [

    'uploads' => $uploads,

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
        ],

        'public' => $onR2 ? $r2((string) env('R2_PUBLIC_BUCKET'), '', false, env('R2_PUBLIC_URL')) : [
            'driver' => 'local',
            'root' => $uploads['public'],
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
        ],

        'receipts' => $onR2 ? $r2((string) env('R2_PRIVATE_BUCKET'), 'receipts', true) : [
            'driver' => 'local',
            'root' => $uploads['receipts'],
            'serve' => false,
            'throw' => true,
            'visibility' => 'private',
        ],

        'mail' => $onR2 ? $r2((string) env('R2_PRIVATE_BUCKET'), 'mail', true) : [
            'driver' => 'local',
            'root' => $uploads['mail'],
            'serve' => false,
            'throw' => true,
            'visibility' => 'private',
        ],

        'exports' => $onR2 ? $r2((string) env('R2_PRIVATE_BUCKET'), 'exports', true) : [
            'driver' => 'local',
            'root' => $uploads['exports'],
            'serve' => false,
            'throw' => true,
            'visibility' => 'private',
            'permissions' => [
                'file' => ['public' => 0644, 'private' => 0644],
                'dir' => ['public' => 0755, 'private' => 0755],
            ],
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
