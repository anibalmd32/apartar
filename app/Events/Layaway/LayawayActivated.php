<?php

declare(strict_types=1);

namespace App\Events\Layaway;

/**
 * `pending_down_payment` → `active`: se aprobó la inicial (`REQ-LAY-005` RB-1).
 */
final class LayawayActivated extends LayawayTransitioned {}
