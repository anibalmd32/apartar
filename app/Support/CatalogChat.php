<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\Account\PortalContext;
use App\Models\Catalog;

final class CatalogChat
{
    /**
     * @return array{canWrite: bool, sendUrl: string, threadUrl: string}
     */
    public static function state(Catalog $catalog): array
    {
        return [
            'canWrite' => app(PortalContext::class)->customerIn((int) $catalog->organization_id) !== null,
            'sendUrl' => route('catalog.chat', ['catalog' => $catalog->slug]),
            'threadUrl' => route('portal.chat.show', ['organization' => $catalog->organization->slug]),
        ];
    }
}
