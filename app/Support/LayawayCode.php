<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * El código público de un apartado: `AP-` y ocho caracteres (`REQ-LAY-001`
 * RB-9).
 *
 * Es la prueba de acceso al portal junto con el documento (`REQ-IDN-008`
 * RB-3), y de él se resuelve la organización **sin tenant previo**: por eso es
 * único en toda la plataforma y es una de las tres excepciones de
 * `GlobalUniqueExceptions`.
 *
 * ## Legible, porque alguien lo va a dictar
 *
 * Base32 **sin `0`, `O`, `1` ni `I`**: veinticuatro letras y ocho cifras. Un
 * cliente lo lee por teléfono y un empleado lo copia a mano de una captura, y
 * los cuatro que faltan son los que se confunden entre sí. En mayúsculas; la
 * colación de la columna hace que `ap-7k3m…` encuentre lo mismo.
 *
 * ## No adivinable, porque es media credencial
 *
 * `random_int()`, que es el generador criptográfico: ocho caracteres de un
 * alfabeto de 32 son 2⁴⁰ códigos, y el portal limita los intentos
 * (`REQ-IDN-008`). Un código secuencial o derivado del `id` diría cuántos
 * apartados tiene la plataforma y cuál es el siguiente.
 *
 * ## El reintento por colisión es de aquí
 *
 * Con 2⁴⁰ códigos una colisión es rarísima, pero no imposible, y el índice
 * único es quien lo sabe. `claim()` inserta dentro de su propio *savepoint*,
 * y si **el índice del código** rechaza la fila, prueba con otro. Una
 * colisión en cualquier otro índice no es asunto suyo y sube tal cual: la de
 * la clave de idempotencia la trata `CreateLayawayAction`.
 *
 * No se comprueba antes con una lectura: el código es global, y leerlo exigiría
 * una consulta sin el filtro del tenant fuera de las rutas que `REQ-QAX-008`
 * permite. Que choque el índice es la comprobación, y es la única que no tiene
 * carrera.
 */
final class LayawayCode
{
    public const PREFIX = 'AP-';

    /** Base32 sin `0`, `O`, `1` ni `I`: 24 letras y 8 cifras. */
    public const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public const LENGTH = 8;

    /** Intentos antes de rendirse; con 2⁴⁰ códigos, el segundo ya es anecdótico. */
    public const MAX_ATTEMPTS = 5;

    /** El nombre que Laravel da al índice de la columna (`T-039`). */
    private const UNIQUE_INDEX = 'layaways_code_unique';

    /** @var (Closure(): string)|null */
    private static ?Closure $generator = null;

    /**
     * Un código nuevo, sin comprobar que esté libre: eso lo hace `claim()`.
     */
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
     * Inserta con un código libre, reintentando si el índice del código choca.
     *
     * @template TResult
     *
     * @param  Closure(string): TResult  $insert  escribe la fila con el código que recibe
     * @return TResult
     *
     * @throws UniqueConstraintViolationException si choca otro índice que no es el del código
     * @throws RuntimeException si no encuentra un código libre en `MAX_ATTEMPTS` intentos
     */
    public static function claim(Closure $insert): mixed
    {
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $code = self::generate();

            try {
                // El *savepoint* es lo que permite reintentar: la fila que
                // chocó se deshace sola y la transacción exterior sigue viva.
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

    /**
     * Si la cadena tiene la forma canónica: `AP-` y ocho caracteres del alfabeto.
     */
    public static function isWellFormed(string $code): bool
    {
        return preg_match('/^'.preg_quote(self::PREFIX, '/').'['.self::ALPHABET.']{'.self::LENGTH.'}$/', $code) === 1;
    }

    /**
     * Sustituye el generador, para que un test provoque una colisión. `null`
     * devuelve el de verdad.
     *
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
