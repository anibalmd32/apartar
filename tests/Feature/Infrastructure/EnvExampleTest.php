<?php

declare(strict_types=1);

/**
 * Completitud de `.env.example` (REQ-INF-001 gate, REQ-INF-004 RB-5 y SP-7).
 *
 * Toda variable que `config/` lee con `env()` tiene que estar documentada en
 * `.env.example`, con valor o comentada con su valor por defecto. Quedan fuera
 * los subsistemas que la plataforma no usa: documentarlos sería ruido y
 * escondería las que sí importan. Cada exclusión dice por qué.
 */

/**
 * Prefijos de variables de servicios que Apartar no usa.
 *
 * @var array<string, string>
 */
const UNUSED_PREFIXES = [
    'AWS_' => 'S3 y SES no se usan: FILESYSTEM_DISK=local',
    'SQS_' => 'driver de cola no usado: QUEUE_CONNECTION=redis',
    'DYNAMODB_' => 'driver de caché no usado: CACHE_STORE=redis',
    'BEANSTALKD_' => 'driver de cola no usado',
    'MEMCACHED_' => 'driver de caché no usado',
    'PUSHER_' => 'broadcaster no usado: BROADCAST_CONNECTION=reverb',
    'ABLY_' => 'broadcaster no usado',
    'POSTMARK_' => 'transporte de correo no usado',
    'RESEND_' => 'transporte de correo no usado',
    'SLACK_' => 'canal de log y notificación no usado',
    'LOG_SLACK_' => 'canal de log no usado',
    'LOG_PAPERTRAIL_' => 'canal de log no usado',
    'PAPERTRAIL_' => 'canal de log no usado',
    'FFMPEG_' => 'medialibrary solo maneja imágenes, no vídeo',
    'FFPROBE_' => 'medialibrary solo maneja imágenes, no vídeo',
    'DB_CACHE_' => 'store `database` de caché no usado',
    'DB_QUEUE_' => 'driver `database` de cola no usado',
    'REVERB_PULSE_' => 'Pulse no está instalado',
    'REVERB_TELESCOPE_' => 'Telescope no está instalado',
];

/**
 * Variables sueltas fuera de uso.
 *
 * @var array<string, string>
 */
const UNUSED_VARIABLES = [
    'DB_URL' => 'la conexión se declara por partes',
    'DB_SOCKET' => 'la conexión es por TCP contra el contenedor mysql',
    'DB_FOREIGN_KEYS' => 'opción de sqlite',
    'DB_ENCRYPT' => 'opción de SQL Server',
    'DB_TRUST_SERVER_CERTIFICATE' => 'opción de SQL Server',
    'MYSQL_ATTR_SSL_CA' => 'la BD vive en la red interna, sin TLS',
    'REDIS_URL' => 'la conexión se declara por partes',
    'MAIL_URL' => 'el correo se declara por partes',
    'MAIL_SENDMAIL_PATH' => 'transporte sendmail no usado',
    'LOG_STDERR_FORMATTER' => 'canal stderr con el formato por defecto',
    'LOG_SYSLOG_FACILITY' => 'canal syslog no usado',
    'ENABLE_MEDIA_LIBRARY_VAPOR_UPLOADS' => 'Vapor no se usa',
    'DB_QUEUE' => 'driver `database` de cola no usado',
];

/**
 * @return list<string>
 */
function environmentVariablesUsedInConfig(): array
{
    $found = [];

    foreach (glob(config_path('*.php')) ?: [] as $file) {
        preg_match_all('/env\(\s*[\'"]([A-Z0-9_]+)[\'"]/', (string) file_get_contents($file), $matches);
        $found = [...$found, ...$matches[1]];
    }

    $found = array_unique($found);
    sort($found);

    return array_values($found);
}

/**
 * @return list<string>
 */
function environmentVariablesDocumented(): array
{
    $lines = preg_split('/\R/', (string) file_get_contents(base_path('.env.example'))) ?: [];
    $documented = [];

    foreach ($lines as $line) {
        // Cuenta tanto `KEY=valor` como `# KEY=valor por defecto`.
        if (preg_match('/^\s*#?\s*([A-Z][A-Z0-9_]*)\s*=/', $line, $matches) === 1) {
            $documented[] = $matches[1];
        }
    }

    return array_values(array_unique($documented));
}

function isIntentionallyUndocumented(string $variable): bool
{
    if (array_key_exists($variable, UNUSED_VARIABLES)) {
        return true;
    }

    foreach (array_keys(UNUSED_PREFIXES) as $prefix) {
        if (str_starts_with($variable, $prefix)) {
            return true;
        }
    }

    return false;
}

it('documenta en .env.example toda variable que config/ usa', function (): void {
    $missing = array_values(array_filter(
        array_diff(environmentVariablesUsedInConfig(), environmentVariablesDocumented()),
        fn (string $variable): bool => ! isIntentionallyUndocumented($variable),
    ));

    expect($missing)->toBe([], 'Faltan en .env.example: '.implode(', ', $missing));
});

it('no deja exclusiones que ya nadie usa', function (): void {
    $used = environmentVariablesUsedInConfig();

    $stale = array_values(array_filter(
        array_keys(UNUSED_VARIABLES),
        fn (string $variable): bool => ! in_array($variable, $used, true),
    ));

    expect($stale)->toBe([], 'Exclusiones obsoletas: '.implode(', ', $stale));
});

/**
 * REQ-INF-004 RB-8: `.env.example` solo contiene valores de ejemplo evidentes.
 */
it('no versiona ningún secreto', function (): void {
    $example = (string) file_get_contents(base_path('.env.example'));

    expect($example)
        ->toMatch('/^APP_KEY=\s*$/m')
        ->toMatch('/^GOOGLE_CLIENT_ID=\s*$/m')
        ->toMatch('/^GOOGLE_CLIENT_SECRET=\s*$/m')
        ->toMatch('/^VAPID_PUBLIC_KEY=\s*$/m')
        ->toMatch('/^VAPID_PRIVATE_KEY=\s*$/m')
        ->toMatch('/^PLATFORM_ADMIN_EMAIL=\s*$/m')
        ->toMatch('/^PLATFORM_ADMIN_PASSWORD=\s*$/m');
});

it('mantiene .env fuera del control de versiones', function (): void {
    expect(file_get_contents(base_path('.gitignore')))->toContain('.env');
});
