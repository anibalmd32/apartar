<?php

declare(strict_types=1);

namespace App\Events\Layaway;

/**
 * `overdue` → `active`: se cubrió todo lo vencido (`REQ-LAY-005` RB-4).
 */
final class LayawayCaughtUp extends LayawayTransitioned {}
