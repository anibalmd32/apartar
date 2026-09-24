<?php

declare(strict_types=1);

namespace App\Support;

use BackedEnum;

/**
 * Qué órdenes acepta una lista pública desde la query string (`REQ-CAT-006`
 * RB-5, SP-3).
 *
 * El `sort` de la URL lo escribe cualquiera, y lo que se hace con él es
 * decidir un `ORDER BY`. La regla es que **ningún texto de la petición llega a
 * la consulta**: llega un caso de un enum, elegido de una lista cerrada, y es
 * ese caso —no la cadena— el que sabe qué columnas ordenan. Un
 * `sort=price_asc; DROP` no es un orden raro, es un orden que no existe, y se
 * ignora: `resolve()` devuelve `null` y quien llama aplica su defecto, que no
 * es un error ni un aviso, porque quien abrió el enlace no puede hacer nada
 * con un mensaje.
 *
 * ## Por qué no basta `tryFrom()`
 *
 * Porque la lista blanca no es el enum entero. El del catálogo tiene un orden
 * —`relevance`— que **no se pide**, se deduce de que haya término
 * (`REQ-CAT-002` RB-4); aceptarlo de la URL dejaría ordenar por relevancia una
 * consulta sin `MATCH`. Y porque `tryFrom()` recibe lo que venga: un array de
 * la query string es un `TypeError`, y un texto de diez kilobytes se compara
 * igual. Aquí se mira primero que sea una cadena corta.
 *
 * @template-covariant TSort of BackedEnum
 */
final readonly class SortWhitelist
{
    /**
     * Más largo que cualquier valor legítimo; lo que pase ni se compara.
     */
    private const MAX_LENGTH = 32;

    /**
     * @param  non-empty-list<TSort>  $allowed  los órdenes que se aceptan de la URL
     */
    public function __construct(private array $allowed) {}

    /**
     * El orden pedido si está en la lista, o `null` si no se pidió ninguno o
     * se pidió uno que no existe.
     *
     * La comparación es **exacta**: sin recortar espacios ni ignorar
     * mayúsculas. Una URL con `sort=Name` no la generó esta página, y
     * aceptarla sería tener dos URL para el mismo conjunto, que es lo contrario
     * de lo que RB-6 pide al estado de la query string.
     *
     * @return TSort|null
     */
    public function resolve(mixed $requested): ?BackedEnum
    {
        if (! is_string($requested) || $requested === '' || strlen($requested) > self::MAX_LENGTH) {
            return null;
        }

        foreach ($this->allowed as $sort) {
            if ($sort->value === $requested) {
                return $sort;
            }
        }

        return null;
    }
}
