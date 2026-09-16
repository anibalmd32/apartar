<?php

declare(strict_types=1);

namespace App\Domain\Auditing;

use JsonException;

/**
 * El `properties` de una entrada de auditoría: el antes y el después, sin nada
 * que no deba estar ahí (`REQ-BOF-005` RB-2, RB-5, SP-2).
 *
 * Tres cosas pasan aquí, y las tres tienen su motivo:
 *
 * ## 1. Solo viaja lo que cambió (RB-2)
 *
 * Guardar el estado entero antes y después convierte la auditoría en una copia
 * de la tabla y la pregunta que se le hace —«¿qué cambió?»— en un ejercicio de
 * comparar dos bloques de JSON a ojo. Se guardan las claves cuyo valor difiere,
 * con su par. Una operación que no cambió nada deja `changed` vacío, y eso
 * también es información.
 *
 * ## 2. Hay cosas que **nunca** entran (RB-5)
 *
 * Contraseñas, huellas, tokens y `account_id`. Los tres primeros son lo
 * evidente; el cuarto es el que engaña, y es el más importante: **`account_id`
 * es la identidad global de un consumidor** y meterla en la auditoría de una
 * organización se la entrega a un negocio que solo debería conocer su ficha de
 * cliente (`REQ-ACC-007` RB-5). Es una fuga entre las dos identidades del
 * diseño (`D-8`), no un descuido de formato, y por eso la lista se aplica por
 * **coincidencia de fragmento** sobre el nombre de la clave: `account_id`,
 * `accountId` y `related_account_id` son la misma fuga escrita de tres formas.
 *
 * Redactar y no omitir: una clave redactada deja ver **que** cambió algo sin
 * decir qué, y omitirla haría indistinguible «no cambió» de «no te lo puedo
 * contar».
 *
 * ## 3. Nada crece sin límite (SP-2)
 *
 * Una reasignación de 36 cuotas o una ficha técnica de 50 filas producen un
 * `properties` que ni se lee ni cabe cómodo. Los textos largos se recortan, las
 * listas se acotan, y si aun así el conjunto se pasa del tope se sustituye por
 * el resumen de qué campos cambiaron — que es lo que de verdad se consulta—.
 */
final readonly class AuditProperties
{
    /** Tope de caracteres de un valor de texto. */
    private const MAX_STRING = 400;

    /** Tope de elementos de una lista. */
    private const MAX_ITEMS = 20;

    /** Tope del JSON completo, en bytes. */
    private const MAX_BYTES = 8192;

    /** Lo que se marca como redactado si aparece en el nombre de una clave. */
    private const REDACTED_FRAGMENTS = [
        'password',
        'secret',
        'token',
        'hash',
        'apikey',
        'api_key',
        'privatekey',
        'private_key',
        'credential',
        'accountid',
        'account_id',
    ];

    /** Lo que se escribe en lugar del valor prohibido. */
    public const REDACTED = '[redactado]';

    /**
     * @param  array<string, array{before: mixed, after: mixed}>  $changed  lo que cambió, por clave
     * @param  bool  $degraded  `true` si el detalle no cupo y esto es el resumen
     */
    private function __construct(
        public array $changed,
        public bool $degraded,
    ) {}

    /**
     * Diferencia entre dos estados, ya saneada y acotada.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public static function between(array $before, array $after): self
    {
        $changed = [];

        foreach (array_keys($before + $after) as $key) {
            $key = (string) $key;

            $wasRaw = $before[$key] ?? null;
            $isRaw = $after[$key] ?? null;

            // **La comparación de una clave redactada va sobre el valor
            // crudo.** Comparando los saneados, dos contraseñas distintas
            // serían las dos `[redactado]`, saldrían iguales y el campo
            // desaparecería del diff: una contraseña cambiada no habría dejado
            // rastro **ninguno**, que es justo lo contrario de lo que la
            // redacción pretende. Se redacta el valor, no el hecho.
            $unchanged = self::isRedacted($key)
                ? $wasRaw === $isRaw
                : self::clean($key, $wasRaw) === self::clean($key, $isRaw);

            if ($unchanged) {
                continue;
            }

            $changed[$key] = [
                'before' => self::clean($key, $wasRaw),
                'after' => self::clean($key, $isRaw),
            ];
        }

        return self::bounded($changed);
    }

    /**
     * Forma con la que se guarda en la columna `properties`.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $properties = ['changed' => $this->changed];

        if ($this->degraded) {
            // SP-2 · se dice que esto es un resumen. Un `properties` recortado
            // que no avisara de estarlo se leería como «solo cambió esto».
            $properties['truncated'] = true;
        }

        return $properties;
    }

    /**
     * Indica si el par de estados no produjo ninguna diferencia.
     */
    public function isEmpty(): bool
    {
        return $this->changed === [];
    }

    /**
     * SP-2 · acota el conjunto, y si aun así no cabe lo cambia por su resumen.
     *
     * El resumen conserva **los nombres de los campos** y tira los valores:
     * ante la pregunta «¿qué tocó esta operación?», saber que fueron el precio
     * y la frecuencia vale mucho más que un JSON de 400 KB que nadie abre.
     *
     * @param  array<string, array{before: mixed, after: mixed}>  $changed
     */
    private static function bounded(array $changed): self
    {
        if (self::fits($changed)) {
            return new self($changed, degraded: false);
        }

        $summary = [];

        foreach (array_keys($changed) as $key) {
            $summary[(string) $key] = ['before' => '…', 'after' => '…'];
        }

        // Si ni la lista de nombres cabe, lo que queda es el número: una
        // entrada sin `properties` sigue diciendo quién, qué y cuándo, que es
        // lo que RB-4 protege por encima del detalle.
        return self::fits($summary)
            ? new self($summary, degraded: true)
            : new self([], degraded: true);
    }

    /**
     * @param  array<string, array{before: mixed, after: mixed}>  $changed
     */
    private static function fits(array $changed): bool
    {
        try {
            return strlen(json_encode($changed, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) <= self::MAX_BYTES;
        } catch (JsonException) {
            // Un valor que no se deja serializar no cabe en ninguna longitud.
            // Devolver `false` lo manda al resumen en lugar de reventar la
            // operación, que es RB-4 aplicado dentro de esta clase.
            return false;
        }
    }

    /**
     * Un valor listo para guardarse: redactado si su clave lo exige, recortado
     * si es largo, y reducido a algo serializable si no lo era.
     */
    private static function clean(string $key, mixed $value): mixed
    {
        if (self::isRedacted($key)) {
            // Distinguir «estaba vacío» de «no te lo cuento»: un nulo redactado
            // seguiría siendo nulo y parecería que el campo no tenía valor.
            return $value === null ? null : self::REDACTED;
        }

        return self::shorten($value);
    }

    private static function shorten(mixed $value): mixed
    {
        if (is_string($value)) {
            return mb_strlen($value) > self::MAX_STRING
                ? mb_substr($value, 0, self::MAX_STRING).'…'
                : $value;
        }

        if (is_array($value)) {
            $items = array_slice($value, 0, self::MAX_ITEMS, preserve_keys: true);
            $shortened = [];

            foreach ($items as $itemKey => $item) {
                $shortened[$itemKey] = self::clean((string) $itemKey, $item);
            }

            if (count($value) > self::MAX_ITEMS) {
                $shortened[] = '…';
            }

            return $shortened;
        }

        if (is_scalar($value) || $value === null) {
            return $value;
        }

        // Fechas, enums y objetos con representación de texto entran por aquí;
        // lo que no la tenga se guarda por su tipo, que sigue diciendo que ese
        // campo cambió.
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        if ($value instanceof \Stringable || (is_object($value) && method_exists($value, '__toString'))) {
            return self::shorten((string) $value);
        }

        return '['.get_debug_type($value).']';
    }

    private static function isRedacted(string $key): bool
    {
        $normalised = mb_strtolower($key);

        foreach (self::REDACTED_FRAGMENTS as $fragment) {
            if (str_contains($normalised, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
