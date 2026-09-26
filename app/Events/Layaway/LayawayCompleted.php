<?php

declare(strict_types=1);

namespace App\Events\Layaway;

/**
 * `active` u `overdue` → `completed`: saldado; libera cupo del plan (RB-5, RB-6).
 */
final class LayawayCompleted extends LayawayTransitioned {}
