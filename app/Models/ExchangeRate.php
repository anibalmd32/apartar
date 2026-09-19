<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Exchange\RateSource;
use Carbon\CarbonImmutable;
use Database\Factories\ExchangeRateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Una lectura de la tasa de cambio (`REQ-FX-002`).
 *
 * ## Tabla de plataforma: no lleva el trait, y es una decisión, no un olvido
 *
 * La tasa del BCV y la del paralelo son las mismas para todo el mundo: no son
 * un dato de ninguna organización (`D-6`, `REQ-FX-002` RB-0). Con
 * `organization_id`, cada negocio guardaría su copia de la misma cifra y la
 * ingesta tendría que escribir N filas por lectura —justo lo que la regla de
 * **una sola petición HTTP para toda la plataforma** existe para impedir—.
 *
 * Lo que sí es del tenant es **qué fuente usa cada organización** y su tasa
 * manual, y eso vive en `organization_settings` desde `T-014`. Una tasa
 * `manual` **nunca** llega a esta tabla (`REQ-FX-003` RB-4): su histórico está
 * en la auditoría de la organización, que es de quien es el dato.
 *
 * Por eso `exchange_rates` está declarada en `TenancyAudit::NON_TENANT_TABLES`
 * con su motivo escrito: la presunción de `REQ-QAX-008` RB-4.4 es la contraria
 * —toda tabla es del tenant salvo que se justifique—, así que quedarse fuera
 * exige decir por qué y el gate lo lee.
 *
 * ## Histórico inmutable
 *
 * Solo `INSERT` (RB-2). Ninguna operación actualiza ni borra una fila: es lo
 * que permite justificar ante un cliente con qué tasa se le procesó un pago
 * meses después. El modelo es anémico (`REQ-QAX-001` RB-3); quién inserta y
 * con qué validación de plausibilidad es de `FetchExchangeRate` (`T-020`).
 *
 * @property int $id
 * @property RateSource $source fuente pública de la cotización: nunca `manual`
 * @property string $rate decimal en string, escala 8: jamás coma flotante
 * @property CarbonImmutable $published_at cuándo la publicó la fuente
 * @property CarbonImmutable $fetched_at cuándo la leímos nosotros
 */
class ExchangeRate extends Model
{
    /** @use HasFactory<ExchangeRateFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'source',
        'rate',
        'published_at',
        'fetched_at',
    ];

    /**
     * `decimal:8` y no `float`: el cast devuelve **una cadena** con la escala
     * de la columna, calculada con aritmética exacta.
     *
     * Además de impedir la coma flotante, normaliza la escala, y eso es lo que
     * hace que la deduplicación de `REQ-FX-002` RB-3 —«si la tasa es idéntica
     * a la última, no insertes»— pueda ser una comparación de cadenas sin
     * depender de cómo viniera escrito el número en el JSON de la fuente.
     *
     * Las dos fechas se castean a `CarbonImmutable` para que lo que sale del
     * modelo encaje con `RateSnapshot` sin conversiones por el camino.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => RateSource::class,
            'rate' => 'decimal:8',
            'published_at' => 'immutable_datetime',
            'fetched_at' => 'immutable_datetime',
        ];
    }
}
