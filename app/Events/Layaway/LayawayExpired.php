<?php

declare(strict_types=1);

namespace App\Events\Layaway;

/**
 * `pending_down_payment` → `expired`: la inicial no llegó a tiempo (`REQ-LAY-006`); libera cupo (RB-6).
 */
final class LayawayExpired extends LayawayTransition {}
