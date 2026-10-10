<?php

declare(strict_types=1);

return [

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

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
        ],

        // Comprobantes de pago (`REQ-PAY-002`): privado, sin URL pública y
        // con la organización en la ruta (`{organization_id}/{uuid}.{ext}`).
        // Solo se sirve por la ruta firmada de `ReceiptFileController`.
        'receipts' => [
            'driver' => 'local',
            'root' => storage_path('app/private/receipts'),
            'serve' => false,
            'throw' => true,
            'visibility' => 'private',
        ],

        // `REQ-BOF-043` RB-5 (`T-070`) · las exportaciones a Excel: privado,
        // bajo la carpeta de cada organización, y servido solo por la ruta
        // firmada de descarga.
        //
        // Permisos legibles por otros usuarios del contenedor, y no los 0700 /
        // 0600 de «private»: el archivo lo escribe el worker de la cola, que
        // hoy corre como `root`, y lo sirve PHP-FPM como `www-data`. Con 0700
        // la descarga daba siempre `410` (lo cazó el e2e de `T-070`). Sigue
        // fuera de la raíz web y solo sale por la ruta firmada. Cuando el
        // worker corra como `www-data` (`T-087`), puede volver a 0700.
        'exports' => [
            'driver' => 'local',
            'root' => storage_path('app/private/exports'),
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
