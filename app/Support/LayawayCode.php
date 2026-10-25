<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class LayawayCode
{
    public const PREFIX = 'AP-';

    public const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public const LENGTH = 8;

    public const MAX_ATTEMPTS = 5;

    private const UNIQUE_INDEX = 'layaways_code_unique';

    /** @var (Closure(): string)|null */
    private static ?Closure $generator = null;

    public static function generate(): string
    {
        if (self::$generator instanceof Closure) {
            return (self::$generator)();
        }

        $code = '';
        $last = strlen(self::ALPHABET) - 1;

        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, $last)];
        }

        return self::PREFIX.$code;
    }

    /**
     * @template TResult
     *
     * @param  Closure(string): TResult  $insert
     * @return TResult
     *
     * @throws UniqueConstraintViolationException
     * @throws RuntimeException
     */
    public static function claim(Closure $insert): mixed
    {
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $code = self::generate();

            try {
                return DB::transaction(fn (): mixed => $insert($code));
            } catch (UniqueConstraintViolationException $exception) {
                if (! self::isCodeCollision($exception)) {
                    throw $exception;
                }
            }
        }

        throw new RuntimeException(sprintf(
            'No se encontró un código de apartado libre en %d intentos. Con 2⁴⁰ códigos eso no es mala suerte: '
            .'revisa el generador.',
            self::MAX_ATTEMPTS,
        ));
    }

    public static function isWellFormed(string $code): bool
    {
        return preg_match('/^'.preg_quote(self::PREFIX, '/').'['.self::ALPHABET.']{'.self::LENGTH.'}$/', $code) === 1;
    }

    public static function normalize(string $raw): string
    {
        $compact = (string) preg_replace('/[^A-Z0-9]/', '', mb_strtoupper($raw));
        $prefix = rtrim(self::PREFIX, '-');

        if (strlen($compact) === self::LENGTH + strlen($prefix) && str_starts_with($compact, $prefix)) {
            $compact = substr($compact, strlen($prefix));
        }

        return self::PREFIX.$compact;
    }

    /**
     * @param  (Closure(): string)|null  $generator
     */
    public static function generateUsing(?Closure $generator): void
    {
        self::$generator = $generator;
    }

    private static function isCodeCollision(UniqueConstraintViolationException $exception): bool
    {
        return str_contains($exception->getMessage(), self::UNIQUE_INDEX);
    }
}
