<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Exchange\RateSource;
use Carbon\CarbonImmutable;
use Database\Factories\ExchangeRateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property RateSource $source
 * @property string $rate
 * @property CarbonImmutable $published_at
 * @property CarbonImmutable $fetched_at
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
