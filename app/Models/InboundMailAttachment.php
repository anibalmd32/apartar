<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $inbound_mail_id
 * @property string $mailtrap_attachment_id
 * @property string $filename
 * @property string $content_type
 * @property string $disposition
 * @property string|null $content_id
 * @property int|null $size
 * @property string|null $path
 */
class InboundMailAttachment extends Model
{
    public const DISK = 'mail';

    protected $guarded = ['id'];

    /**
     * @return BelongsTo<InboundMail, $this>
     */
    public function inboundMail(): BelongsTo
    {
        return $this->belongsTo(InboundMail::class);
    }

    public function isStored(): bool
    {
        return $this->path !== null;
    }
}
