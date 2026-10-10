<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Export\ExportStatus;
use App\Domain\Export\ExportType;
use App\Domain\Tenancy\BelongsToOrganization;
use Database\Factories\ExportJobFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\URL;

/**
 * Una exportación a Excel (`REQ-BOF-043`, `T-070`).
 *
 * Del tenant: solo la ve y la descarga su organización (RB-12). Los filtros
 * son **los que la pantalla aplicó** (`meta.filters`), guardados tal cual para
 * que el job los vuelva a leer con la misma regla que la pantalla (RB-3); lo
 * que entre tanto dejó de valer se ignora y se dice en la cabecera del
 * archivo (SP-6).
 *
 * @property int $id
 * @property int $organization_id
 * @property int|null $requested_by
 * @property ExportType $type
 * @property ExportStatus $status
 * @property array<string, mixed> $filters
 * @property string|null $file_path
 * @property int|null $file_size
 * @property int|null $row_count
 * @property string|null $error_message
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property \Illuminate\Support\Carbon $created_at
 * @property-read User|null $requester
 */
class ExportJob extends Model
{
    /** @use HasFactory<ExportJobFactory> */
    use BelongsToOrganization, HasFactory;

    /** El disco privado de las exportaciones (RB-5). */
    public const DISK = 'exports';

    /**
     * Cuántos días se guarda el archivo antes de purgarse (RB-7).
     */
    public const RETENTION_DAYS = 7;

    /**
     * Minutos a partir de los cuales una exportación «en curso» se da por
     * perdida (`RequestExportAction`): muy por encima de lo que dura un job
     * (`GenerateExport::$timeout`) y de una cola normal.
     */
    public const STALE_MINUTES = 15;

    /**
     * Cuántas horas vale el enlace firmado de descarga (RB-5).
     *
     * Es `platform_settings.export_link_ttl_hours` (24), y es una constante
     * porque nadie lee todavía los ajustes de plataforma —como
     * `ReceiptFile::MAX_SIZE_MB`—: el día que exista ese modelo se pone rojo
     * `ExportJobTest` y hay que leerlo de ahí (`T-086`).
     */
    public const LINK_TTL_HOURS = 24;

    /** @var list<string> */
    protected $fillable = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ExportType::class,
            'status' => ExportStatus::class,
            'filters' => 'array',
            'file_size' => 'integer',
            'row_count' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * El enlace firmado de descarga, válido `LINK_TTL_HOURS` (RB-5). La firma
     * no basta sola: la ruta exige además ser de **su** organización (RB-12).
     */
    public function downloadUrl(): string
    {
        return URL::temporarySignedRoute('exports.download', now()->addHours(self::LINK_TTL_HOURS), ['export' => $this->getKey()]);
    }

    /**
     * El nombre con que se descarga: el tipo y el día en que se pidió.
     */
    public function downloadName(): string
    {
        return sprintf('%s-%s.xlsx', $this->type->filename(), $this->created_at->toDateString());
    }
}
